<?php

/**
 * Maho DataSync Invoice Entity Handler
 *
 * Handles import of sales invoices for historical orders.
 * Creates invoice records WITHOUT triggering emails or payment processing.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Model_Entity_Invoice extends Maho_DataSync_Model_Entity_Abstract
{
    protected array $_requiredFields = ['order_id', 'grand_total'];

    protected array $_foreignKeyFields = [
        'order_id' => [
            'entity_type' => 'order',
            'required' => true,
        ],
    ];

    protected ?string $_externalRefField = 'increment_id';

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getEntityType(): string
    {
        return 'invoice';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'Invoices';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function findExisting(array $data): ?int
    {
        // Find by increment_id if provided
        if (!empty($data['increment_id'])) {
            $invoice = Mage::getModel('sales/order_invoice')->getCollection()
                ->addFieldToFilter('increment_id', $data['increment_id'])
                ->getFirstItem();

            if ($invoice->getId()) {
                return (int) $invoice->getId();
            }
        }

        // Also check by order_id + source tracking
        if (!empty($data['order_id']) && !empty($data['entity_id'])) {
            $invoice = Mage::getModel('sales/order_invoice')->getCollection()
                ->addFieldToFilter('order_id', $data['order_id'])
                ->addFieldToFilter('datasync_source_id', $data['entity_id'])
                ->getFirstItem();

            if ($invoice->getId()) {
                return (int) $invoice->getId();
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function import(array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $existingId = $data['_existing_id'] ?? null;

        // Invoices are typically not updated after creation
        if ($existingId) {
            $this->_log("Invoice #{$existingId} already exists, skipping");
            return $existingId;
        }

        return $this->_createInvoice($data, $registry);
    }

    /**
     * Create a new invoice
     *
     * @param array $data Invoice data
     * @return int New invoice entity_id
     * @throws Maho_DataSync_Exception
     */
    protected function _createInvoice(array $data, Maho_DataSync_Model_Registry $registry): int
    {
        // Load the order
        $orderId = $data['order_id'];
        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->load($orderId);

        if (!$order->getId()) {
            throw new Maho_DataSync_Exception(
                "Order #{$orderId} not found for invoice import",
                Maho_DataSync_Exception::CODE_FK_RESOLUTION_FAILED,
            );
        }

        $this->_log("Creating invoice for order #{$order->getIncrementId()}");

        // Check if order can be invoiced (for non-import scenarios)
        // For imports, we bypass this check since we're creating historical records

        /** @var Mage_Sales_Model_Order_Invoice $invoice */
        $invoice = Mage::getModel('sales/order_invoice');

        // Set order relationship
        $invoice->setOrder($order);
        $invoice->setOrderId($order->getId());
        $invoice->setStoreId($order->getStoreId());

        // CRITICAL: Prevent email sending
        $invoice->setEmailSent(1);
        $invoice->setSendEmail(false);

        // Generate or use provided increment_id
        if (!empty($data['target_increment_id'])) {
            $invoice->setIncrementId($data['target_increment_id']);
        } elseif (!empty($data['increment_id'])) {
            // For historical imports, we might want to preserve the source increment_id
            // but this could conflict - generate new one instead
            $invoice->setIncrementId(
                Mage::getSingleton('eav/config')
                    ->getEntityType('invoice')
                    ->fetchNewIncrementId($order->getStoreId()),
            );
        } else {
            $invoice->setIncrementId(
                Mage::getSingleton('eav/config')
                    ->getEntityType('invoice')
                    ->fetchNewIncrementId($order->getStoreId()),
            );
        }

        // Dates
        $invoice->setCreatedAt($this->_parseDate($data['created_at'] ?? null) ?? Mage_Core_Model_Locale::nowUtc());
        if (!empty($data['updated_at'])) {
            $invoice->setUpdatedAt($this->_parseDate($data['updated_at']));
        }

        // State (2 = paid is typical for historical imports)
        $invoice->setState($data['state'] ?? Mage_Sales_Model_Order_Invoice::STATE_PAID);

        // Currency (inherit from order)
        $invoice->setBaseCurrencyCode($order->getBaseCurrencyCode());
        $invoice->setGlobalCurrencyCode($order->getGlobalCurrencyCode());
        $invoice->setStoreCurrencyCode($order->getStoreCurrencyCode());
        $invoice->setOrderCurrencyCode($order->getOrderCurrencyCode());
        $invoice->setStoreToBaseRate($order->getStoreToBaseRate());
        $invoice->setStoreToOrderRate($order->getStoreToOrderRate());
        $invoice->setBaseToGlobalRate($order->getBaseToGlobalRate());
        $invoice->setBaseToOrderRate($order->getBaseToOrderRate());

        // Totals
        $this->_setInvoiceTotals($invoice, $data, $order);

        // Billing address
        if ($order->getBillingAddress()) {
            $invoice->setBillingAddressId($order->getBillingAddress()->getId());
        }

        // Shipping address
        if ($order->getShippingAddress()) {
            $invoice->setShippingAddressId($order->getShippingAddress()->getId());
        }

        // Transaction ID (for payment reference)
        if (!empty($data['transaction_id'])) {
            $invoice->setTransactionId($data['transaction_id']);
        }

        // DataSync tracking
        $sourceSystem = $data['_source_system'] ?? 'import';
        $sourceId = $data['entity_id'] ?? null;

        $invoice->setData('datasync_source_system', $sourceSystem);
        if ($sourceId) {
            $invoice->setData('datasync_source_id', (int) $sourceId);
        }
        $invoice->setData('datasync_source_increment_id', $data['increment_id'] ?? null);
        $invoice->setData('datasync_imported_at', Mage_Core_Model_Locale::nowUtc());

        // Save invoice first to get entity_id
        try {
            // Use resource model directly to avoid observers/events
            $invoice->getResource()->save($invoice);
        } catch (Exception $e) {
            throw Maho_DataSync_Exception::importFailed(
                'invoice',
                $data['entity_id'] ?? 0,
                $sourceSystem,
                $e->getMessage(),
            );
        }

        // Import invoice items
        $items = $this->_parseItems($data);
        if (!empty($items)) {
            $this->_importItems($invoice, $items, $order);
        } else {
            // Auto-create items from order if not specified
            $this->_createItemsFromOrder($invoice, $order);
        }

        // Save invoice items explicitly (resource save doesn't cascade to items)
        try {
            foreach ($invoice->getAllItems() as $invoiceItem) {
                $invoiceItem->setParentId($invoice->getId());
                $invoiceItem->getResource()->save($invoiceItem);
            }
        } catch (Exception $e) {
            throw Maho_DataSync_Exception::importFailed(
                'invoice',
                $data['entity_id'] ?? 0,
                $sourceSystem,
                'Failed to save invoice items: ' . $e->getMessage(),
            );
        }

        // Update order invoiced amounts (without triggering observers)
        $this->_updateOrderInvoicedTotals($order, $invoice);

        // Save order items to persist qty_invoiced changes
        foreach ($order->getAllItems() as $orderItem) {
            $orderItem->getResource()->save($orderItem);
        }

        // Add comment to order
        $order->addStatusHistoryComment(
            "Invoice #{$invoice->getIncrementId()} imported via DataSync from {$sourceSystem}",
            false,
        );
        $order->getResource()->save($order);

        // Update invoice grid table for admin visibility
        $this->_updateInvoiceGrid($invoice, $order);

        // Set external reference for registry
        $data['_external_ref'] = $invoice->getIncrementId();

        // Roll invoice counter forward (see _advanceIncrementCounter docblock).
        $this->_advanceIncrementCounter('invoice', (int) $order->getStoreId(), (string) $invoice->getIncrementId());

        $this->_log("Created invoice #{$invoice->getId()} ({$invoice->getIncrementId()}) for order #{$order->getIncrementId()}");

        return (int) $invoice->getId();
    }

    /**
     * Update invoice grid table for admin visibility
     */
    protected function _updateInvoiceGrid(
        Mage_Sales_Model_Order_Invoice $invoice,
        Mage_Sales_Model_Order $order,
    ): void {
        $resource = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_write');
        $gridTable = $resource->getTableName('sales/invoice_grid');

        $billingAddress = $order->getBillingAddress();
        $billingName = $billingAddress
            ? trim($billingAddress->getFirstname() . ' ' . $billingAddress->getLastname())
            : '';

        $data = [
            'entity_id' => $invoice->getId(),
            'store_id' => $invoice->getStoreId(),
            'base_grand_total' => $invoice->getBaseGrandTotal(),
            'grand_total' => $invoice->getGrandTotal(),
            'order_id' => $invoice->getOrderId(),
            'state' => $invoice->getState(),
            'store_currency_code' => $invoice->getStoreCurrencyCode(),
            'order_currency_code' => $invoice->getOrderCurrencyCode(),
            'base_currency_code' => $invoice->getBaseCurrencyCode(),
            'global_currency_code' => $invoice->getGlobalCurrencyCode(),
            'order_increment_id' => $order->getIncrementId(),
            'increment_id' => $invoice->getIncrementId(),
            'created_at' => $invoice->getCreatedAt(),
            'order_created_at' => $order->getCreatedAt(),
            'billing_name' => $billingName,
        ];

        $connection->insertOnDuplicate($gridTable, $data, array_keys($data));
    }

    /**
     * Set invoice totals
     */
    protected function _setInvoiceTotals(
        Mage_Sales_Model_Order_Invoice $invoice,
        array $data,
        Mage_Sales_Model_Order $order,
    ): void {
        // Use provided values or default to order totals (full invoice)
        $invoice->setSubtotal((float) ($data['subtotal'] ?? $order->getSubtotal()));
        $invoice->setBaseSubtotal((float) ($data['base_subtotal'] ?? $order->getBaseSubtotal()));

        $invoice->setTaxAmount((float) ($data['tax_amount'] ?? $order->getTaxAmount()));
        $invoice->setBaseTaxAmount((float) ($data['base_tax_amount'] ?? $order->getBaseTaxAmount()));

        $invoice->setShippingAmount((float) ($data['shipping_amount'] ?? $order->getShippingAmount()));
        $invoice->setBaseShippingAmount((float) ($data['base_shipping_amount'] ?? $order->getBaseShippingAmount()));
        $invoice->setShippingTaxAmount((float) ($data['shipping_tax_amount'] ?? $order->getShippingTaxAmount()));
        $invoice->setBaseShippingTaxAmount((float) ($data['base_shipping_tax_amount'] ?? $order->getBaseShippingTaxAmount()));

        $invoice->setDiscountAmount((float) ($data['discount_amount'] ?? $order->getDiscountAmount()));
        $invoice->setBaseDiscountAmount((float) ($data['base_discount_amount'] ?? $order->getBaseDiscountAmount()));

        $invoice->setGrandTotal((float) ($data['grand_total'] ?? $order->getGrandTotal()));
        $invoice->setBaseGrandTotal((float) ($data['base_grand_total'] ?? $order->getBaseGrandTotal()));

        // Hidden tax (if applicable)
        $invoice->setHiddenTaxAmount((float) ($data['hidden_tax_amount'] ?? 0));
        $invoice->setBaseHiddenTaxAmount((float) ($data['base_hidden_tax_amount'] ?? 0));

        // Shipping including tax
        $invoice->setShippingInclTax((float) ($data['shipping_incl_tax'] ?? $order->getShippingInclTax()));
        $invoice->setBaseShippingInclTax((float) ($data['base_shipping_incl_tax'] ?? $order->getBaseShippingInclTax()));
    }

    /**
     * Parse items from data
     */
    protected function _parseItems(array $data): array
    {
        if (empty($data['items'])) {
            return [];
        }

        $items = $data['items'];

        // Already an array
        if (is_array($items)) {
            return $items;
        }

        // Try JSON decode
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Import invoice items
     */
    protected function _importItems(
        Mage_Sales_Model_Order_Invoice $invoice,
        array $items,
        Mage_Sales_Model_Order $order,
    ): void {
        foreach ($items as $itemData) {
            // Find matching order item
            $orderItem = null;

            if (!empty($itemData['order_item_id'])) {
                $orderItem = $order->getItemById($itemData['order_item_id']);
            } elseif (!empty($itemData['sku'])) {
                foreach ($order->getAllItems() as $item) {
                    if ($item->getSku() === $itemData['sku']) {
                        $orderItem = $item;
                        break;
                    }
                }
            }

            if (!$orderItem) {
                $this->_log(
                    'Could not find order item for invoice item: ' . json_encode($itemData),
                    Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
                );
                continue;
            }

            /** @var Mage_Sales_Model_Order_Invoice_Item $invoiceItem */
            $invoiceItem = Mage::getModel('sales/order_invoice_item');

            $invoiceItem->setInvoice($invoice);
            $invoiceItem->setOrderItem($orderItem);
            $invoiceItem->setOrderItemId($orderItem->getId());

            // Product info
            $invoiceItem->setProductId($orderItem->getProductId());
            $invoiceItem->setSku($orderItem->getSku());
            $invoiceItem->setName($orderItem->getName());

            // Quantity - use setData to bypass qty validation since the order sync
            // may have already updated qty_invoiced on the order item before the
            // invoice record is created (timing gap in incremental sync)
            $qty = (float) ($itemData['qty'] ?? $orderItem->getQtyOrdered());
            $invoiceItem->setData('qty', $qty);

            // Prices (from order item or override)
            $invoiceItem->setPrice((float) ($itemData['price'] ?? $orderItem->getPrice()));
            $invoiceItem->setBasePrice((float) ($itemData['base_price'] ?? $orderItem->getBasePrice()));

            // Row totals
            $rowTotal = (float) ($itemData['row_total'] ?? ($invoiceItem->getPrice() * $qty));
            $invoiceItem->setRowTotal($rowTotal);
            $invoiceItem->setBaseRowTotal((float) ($itemData['base_row_total'] ?? $rowTotal));

            // Tax
            $invoiceItem->setTaxAmount((float) ($itemData['tax_amount'] ?? 0));
            $invoiceItem->setBaseTaxAmount((float) ($itemData['base_tax_amount'] ?? 0));

            // Discount
            $invoiceItem->setDiscountAmount((float) ($itemData['discount_amount'] ?? 0));
            $invoiceItem->setBaseDiscountAmount((float) ($itemData['base_discount_amount'] ?? 0));

            $invoice->addItem($invoiceItem);

            // Update order item qty_invoiced (without full save)
            $orderItem->setQtyInvoiced($orderItem->getQtyInvoiced() + $qty);
        }
    }

    /**
     * Create invoice items from all order items (full invoice)
     */
    protected function _createItemsFromOrder(
        Mage_Sales_Model_Order_Invoice $invoice,
        Mage_Sales_Model_Order $order,
    ): void {
        foreach ($order->getAllItems() as $orderItem) {
            // For DataSync imports, the order sync may have already set qty_invoiced
            // from the source system before the invoice record is created. Use
            // qty_ordered as fallback when qty_to_invoice shows 0.
            $qtyToInvoice = $orderItem->getQtyOrdered() - $orderItem->getQtyInvoiced();
            if ($qtyToInvoice <= 0) {
                $qtyToInvoice = $orderItem->getQtyOrdered();
                if ($qtyToInvoice <= 0) {
                    continue;
                }
            }

            /** @var Mage_Sales_Model_Order_Invoice_Item $invoiceItem */
            $invoiceItem = Mage::getModel('sales/order_invoice_item');

            $invoiceItem->setInvoice($invoice);
            $invoiceItem->setOrderItem($orderItem);
            $invoiceItem->setOrderItemId($orderItem->getId());

            $invoiceItem->setProductId($orderItem->getProductId());
            $invoiceItem->setSku($orderItem->getSku());
            $invoiceItem->setName($orderItem->getName());

            $invoiceItem->setData('qty', $qtyToInvoice);
            $invoiceItem->setPrice($orderItem->getPrice());
            $invoiceItem->setBasePrice($orderItem->getBasePrice());

            $invoiceItem->setRowTotal($orderItem->getRowTotal());
            $invoiceItem->setBaseRowTotal($orderItem->getBaseRowTotal());

            $invoiceItem->setTaxAmount($orderItem->getTaxAmount());
            $invoiceItem->setBaseTaxAmount($orderItem->getBaseTaxAmount());

            $invoiceItem->setDiscountAmount($orderItem->getDiscountAmount());
            $invoiceItem->setBaseDiscountAmount($orderItem->getBaseDiscountAmount());

            $invoice->addItem($invoiceItem);

            // Update order item
            $orderItem->setQtyInvoiced($orderItem->getQtyOrdered());
        }
    }

    /**
     * Update order with invoiced totals
     */
    protected function _updateOrderInvoicedTotals(
        Mage_Sales_Model_Order $order,
        Mage_Sales_Model_Order_Invoice $invoice,
    ): void {
        $order->setTotalInvoiced($order->getTotalInvoiced() + $invoice->getGrandTotal());
        $order->setBaseTotalInvoiced($order->getBaseTotalInvoiced() + $invoice->getBaseGrandTotal());
        $order->setSubtotalInvoiced($order->getSubtotalInvoiced() + $invoice->getSubtotal());
        $order->setBaseSubtotalInvoiced($order->getBaseSubtotalInvoiced() + $invoice->getBaseSubtotal());
        $order->setTaxInvoiced($order->getTaxInvoiced() + $invoice->getTaxAmount());
        $order->setBaseTaxInvoiced($order->getBaseTaxInvoiced() + $invoice->getBaseTaxAmount());
        $order->setShippingInvoiced($order->getShippingInvoiced() + $invoice->getShippingAmount());
        $order->setBaseShippingInvoiced($order->getBaseShippingInvoiced() + $invoice->getBaseShippingAmount());
        $order->setDiscountInvoiced($order->getDiscountInvoiced() + $invoice->getDiscountAmount());
        $order->setBaseDiscountInvoiced($order->getBaseDiscountInvoiced() + $invoice->getBaseDiscountAmount());

        // Update paid amounts
        $order->setTotalPaid($order->getTotalPaid() + $invoice->getGrandTotal());
        $order->setBaseTotalPaid($order->getBaseTotalPaid() + $invoice->getBaseGrandTotal());

        // Update due amounts
        $order->setTotalDue($order->getGrandTotal() - $order->getTotalPaid());
        $order->setBaseTotalDue($order->getBaseGrandTotal() - $order->getBaseTotalPaid());
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function validate(array $data): array
    {
        $errors = parent::validate($data);

        // Validate grand_total is positive
        if (isset($data['grand_total']) && (float) $data['grand_total'] < 0) {
            $errors[] = "Grand total cannot be negative: {$data['grand_total']}";
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function export(array $filters = []): iterable
    {
        $collection = Mage::getModel('sales/order_invoice')->getCollection();

        if (!empty($filters['date_from'])) {
            $collection->addFieldToFilter('created_at', ['gteq' => $filters['date_from']]);
        }

        if (!empty($filters['date_to'])) {
            $collection->addFieldToFilter('created_at', ['lteq' => $filters['date_to']]);
        }

        if (!empty($filters['order_id'])) {
            $collection->addFieldToFilter('order_id', ['in' => (array) $filters['order_id']]);
        }

        $collection->setOrder('entity_id', 'ASC');

        if (!empty($filters['limit'])) {
            $collection->setPageSize($filters['limit']);
        }

        foreach ($collection as $invoice) {
            yield $this->_exportInvoice($invoice);
        }
    }

    /**
     * Export single invoice to array
     */
    protected function _exportInvoice(Mage_Sales_Model_Order_Invoice $invoice): array
    {
        $data = $invoice->getData();

        // Add items
        $data['items'] = [];
        foreach ($invoice->getAllItems() as $item) {
            $data['items'][] = $item->getData();
        }

        return $data;
    }
}
