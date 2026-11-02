<?php

/**
 * Maho DataSync Credit Memo Entity Handler
 *
 * Handles import of sales credit memos (refunds) for historical orders.
 * Creates credit memo records WITHOUT triggering emails or actual refund processing.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Model_Entity_Creditmemo extends Maho_DataSync_Model_Entity_Abstract
{
    protected array $_requiredFields = ['order_id', 'grand_total'];

    protected array $_foreignKeyFields = [
        'order_id' => [
            'entity_type' => 'order',
            'required' => true,
        ],
        'invoice_id' => [
            'entity_type' => 'invoice',
            'required' => false, // Credit memo can be created without invoice
        ],
    ];

    protected ?string $_externalRefField = 'increment_id';

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getEntityType(): string
    {
        return 'creditmemo';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'Credit Memos';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function findExisting(array $data): ?int
    {
        // Find by increment_id if provided
        if (!empty($data['increment_id'])) {
            $creditmemo = Mage::getModel('sales/order_creditmemo')->getCollection()
                ->addFieldToFilter('increment_id', $data['increment_id'])
                ->getFirstItem();

            if ($creditmemo->getId()) {
                return (int) $creditmemo->getId();
            }
        }

        // Also check by order_id + source tracking
        if (!empty($data['order_id']) && !empty($data['entity_id'])) {
            $creditmemo = Mage::getModel('sales/order_creditmemo')->getCollection()
                ->addFieldToFilter('order_id', $data['order_id'])
                ->addFieldToFilter('datasync_source_id', $data['entity_id'])
                ->getFirstItem();

            if ($creditmemo->getId()) {
                return (int) $creditmemo->getId();
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

        // Credit memos are typically not updated after creation
        if ($existingId) {
            $this->_log("Credit memo #{$existingId} already exists, skipping");
            return $existingId;
        }

        return $this->_createCreditmemo($data, $registry);
    }

    /**
     * Create a new credit memo
     *
     * @param array $data Credit memo data
     * @return int New credit memo entity_id
     * @throws Maho_DataSync_Exception
     */
    protected function _createCreditmemo(array $data, Maho_DataSync_Model_Registry $registry): int
    {
        // Load the order
        $orderId = $data['order_id'];
        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->load($orderId);

        if (!$order->getId()) {
            throw new Maho_DataSync_Exception(
                "Order #{$orderId} not found for credit memo import",
                Maho_DataSync_Exception::CODE_FK_RESOLUTION_FAILED,
            );
        }

        $this->_log("Creating credit memo for order #{$order->getIncrementId()}");

        /** @var Mage_Sales_Model_Order_Creditmemo $creditmemo */
        $creditmemo = Mage::getModel('sales/order_creditmemo');

        // Set order relationship
        $creditmemo->setOrder($order);
        $creditmemo->setOrderId($order->getId());
        $creditmemo->setStoreId($order->getStoreId());

        // Link to invoice if provided
        if (!empty($data['invoice_id'])) {
            $creditmemo->setInvoiceId($data['invoice_id']);
        }

        // CRITICAL: Prevent email sending
        $creditmemo->setEmailSent(1);
        $creditmemo->setSendEmail(false);

        // Generate or use provided increment_id
        if (!empty($data['target_increment_id'])) {
            $creditmemo->setIncrementId($data['target_increment_id']);
        } else {
            $creditmemo->setIncrementId(
                Mage::getSingleton('eav/config')
                    ->getEntityType('creditmemo')
                    ->fetchNewIncrementId($order->getStoreId()),
            );
        }

        // Dates
        $creditmemo->setCreatedAt($this->_parseDate($data['created_at'] ?? null) ?? Mage_Core_Model_Locale::nowUtc());
        if (!empty($data['updated_at'])) {
            $creditmemo->setUpdatedAt($this->_parseDate($data['updated_at']));
        }

        // State (2 = refunded is typical for historical imports)
        $creditmemo->setState($data['state'] ?? Mage_Sales_Model_Order_Creditmemo::STATE_REFUNDED);

        // Currency (inherit from order)
        $creditmemo->setBaseCurrencyCode($order->getBaseCurrencyCode());
        $creditmemo->setGlobalCurrencyCode($order->getGlobalCurrencyCode());
        $creditmemo->setStoreCurrencyCode($order->getStoreCurrencyCode());
        $creditmemo->setOrderCurrencyCode($order->getOrderCurrencyCode());
        $creditmemo->setStoreToBaseRate($order->getStoreToBaseRate());
        $creditmemo->setStoreToOrderRate($order->getStoreToOrderRate());
        $creditmemo->setBaseToGlobalRate($order->getBaseToGlobalRate());
        $creditmemo->setBaseToOrderRate($order->getBaseToOrderRate());

        // Totals
        $this->_setCreditmemoTotals($creditmemo, $data, $order);

        // Billing address
        if ($order->getBillingAddress()) {
            $creditmemo->setBillingAddressId($order->getBillingAddress()->getId());
        }

        // Shipping address
        if ($order->getShippingAddress()) {
            $creditmemo->setShippingAddressId($order->getShippingAddress()->getId());
        }

        // Adjustments
        $creditmemo->setAdjustmentPositive((float) ($data['adjustment_positive'] ?? 0));
        $creditmemo->setBaseAdjustmentPositive((float) ($data['base_adjustment_positive'] ?? $data['adjustment_positive'] ?? 0));
        $creditmemo->setAdjustmentNegative((float) ($data['adjustment_negative'] ?? 0));
        $creditmemo->setBaseAdjustmentNegative((float) ($data['base_adjustment_negative'] ?? $data['adjustment_negative'] ?? 0));
        $creditmemo->setAdjustment((float) ($data['adjustment'] ?? 0));
        $creditmemo->setBaseAdjustment((float) ($data['base_adjustment'] ?? $data['adjustment'] ?? 0));

        // Shipping refund amount
        $creditmemo->setShippingAmount((float) ($data['shipping_amount'] ?? 0));
        $creditmemo->setBaseShippingAmount((float) ($data['base_shipping_amount'] ?? $data['shipping_amount'] ?? 0));

        // Customer info
        $creditmemo->setCustomerId($order->getCustomerId());

        // Offline refund indicator (important for historical imports)
        $creditmemo->setOfflineRequested((int) ($data['offline_requested'] ?? 1));

        // DataSync tracking
        $sourceSystem = $data['_source_system'] ?? 'import';
        $sourceId = $data['entity_id'] ?? null;

        $creditmemo->setData('datasync_source_system', $sourceSystem);
        if ($sourceId) {
            $creditmemo->setData('datasync_source_id', (int) $sourceId);
        }
        $creditmemo->setData('datasync_source_increment_id', $data['increment_id'] ?? null);
        $creditmemo->setData('datasync_imported_at', Mage_Core_Model_Locale::nowUtc());

        // NetSuite reference. The order and customer importers both carry this across
        // (Entity/Order.php $_customFields, Entity/Customer.php custom attributes) but this
        // one never did, so every credit memo imported arrived with the field blank. The source
        // row has it: the adapter selects *, the value simply was not copied. Without it a
        // refund here cannot be reconciled against the credit memo NetSuite already holds.
        if (!empty($data['netsuite_id'])) {
            $creditmemo->setData('netsuite_id', $data['netsuite_id']);
        }

        // Save credit memo first to get entity_id
        try {
            // Use resource model directly to avoid observers/events
            $creditmemo->getResource()->save($creditmemo);
        } catch (Exception $e) {
            throw Maho_DataSync_Exception::importFailed(
                'creditmemo',
                $data['entity_id'] ?? 0,
                $sourceSystem,
                $e->getMessage(),
            );
        }

        // Import credit memo items
        $items = $this->_parseItems($data);
        if (!empty($items)) {
            $this->_importItems($creditmemo, $items, $order);
        } else {
            // Auto-create items from order if not specified (full refund)
            $this->_createItemsFromOrder($creditmemo, $order);
        }

        // Save creditmemo items explicitly (resource save doesn't cascade to items)
        try {
            foreach ($creditmemo->getAllItems() as $creditmemoItem) {
                $creditmemoItem->setParentId($creditmemo->getId());
                $creditmemoItem->getResource()->save($creditmemoItem);
            }
        } catch (Exception $e) {
            throw Maho_DataSync_Exception::importFailed(
                'creditmemo',
                $data['entity_id'] ?? 0,
                $sourceSystem,
                'Failed to save credit memo items: ' . $e->getMessage(),
            );
        }

        // Update order refunded totals
        $this->_updateOrderRefundedTotals($order, $creditmemo);

        // Save order items to persist qty_refunded changes
        foreach ($order->getAllItems() as $orderItem) {
            $orderItem->getResource()->save($orderItem);
        }

        // Add comment to order
        $order->addStatusHistoryComment(
            "Credit Memo #{$creditmemo->getIncrementId()} imported via DataSync from {$sourceSystem}",
            false,
        );
        $order->getResource()->save($order);

        // Update creditmemo grid table for admin visibility
        $this->_updateCreditmemoGrid($creditmemo, $order);

        // Set external reference for registry
        $data['_external_ref'] = $creditmemo->getIncrementId();

        // Roll creditmemo counter forward (see _advanceIncrementCounter docblock).
        $this->_advanceIncrementCounter('creditmemo', (int) $order->getStoreId(), (string) $creditmemo->getIncrementId());

        $this->_log("Created credit memo #{$creditmemo->getId()} ({$creditmemo->getIncrementId()}) for order #{$order->getIncrementId()}");

        return (int) $creditmemo->getId();
    }

    /**
     * Update creditmemo grid table for admin visibility
     */
    protected function _updateCreditmemoGrid(
        Mage_Sales_Model_Order_Creditmemo $creditmemo,
        Mage_Sales_Model_Order $order,
    ): void {
        $resource = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_write');
        $gridTable = $resource->getTableName('sales/creditmemo_grid');

        $billingAddress = $order->getBillingAddress();
        $billingName = $billingAddress
            ? trim($billingAddress->getFirstname() . ' ' . $billingAddress->getLastname())
            : '';

        $data = [
            'entity_id' => $creditmemo->getId(),
            'store_id' => $creditmemo->getStoreId(),
            'base_grand_total' => $creditmemo->getBaseGrandTotal(),
            'grand_total' => $creditmemo->getGrandTotal(),
            'order_id' => $creditmemo->getOrderId(),
            'state' => $creditmemo->getState(),
            'creditmemo_status' => $creditmemo->getState(),
            'store_currency_code' => $creditmemo->getStoreCurrencyCode(),
            'order_currency_code' => $creditmemo->getOrderCurrencyCode(),
            'base_currency_code' => $creditmemo->getBaseCurrencyCode(),
            'global_currency_code' => $creditmemo->getGlobalCurrencyCode(),
            'order_increment_id' => $order->getIncrementId(),
            'increment_id' => $creditmemo->getIncrementId(),
            'created_at' => $creditmemo->getCreatedAt(),
            'order_created_at' => $order->getCreatedAt(),
            'billing_name' => $billingName,
        ];

        $connection->insertOnDuplicate($gridTable, $data, array_keys($data));
    }

    /**
     * Set credit memo totals
     */
    protected function _setCreditmemoTotals(
        Mage_Sales_Model_Order_Creditmemo $creditmemo,
        array $data,
        Mage_Sales_Model_Order $order,
    ): void {
        // Subtotal
        $creditmemo->setSubtotal((float) ($data['subtotal'] ?? 0));
        $creditmemo->setBaseSubtotal((float) ($data['base_subtotal'] ?? $data['subtotal'] ?? 0));

        // Tax
        $creditmemo->setTaxAmount((float) ($data['tax_amount'] ?? 0));
        $creditmemo->setBaseTaxAmount((float) ($data['base_tax_amount'] ?? $data['tax_amount'] ?? 0));

        // Shipping
        $creditmemo->setShippingAmount((float) ($data['shipping_amount'] ?? 0));
        $creditmemo->setBaseShippingAmount((float) ($data['base_shipping_amount'] ?? $data['shipping_amount'] ?? 0));
        $creditmemo->setShippingTaxAmount((float) ($data['shipping_tax_amount'] ?? 0));
        $creditmemo->setBaseShippingTaxAmount((float) ($data['base_shipping_tax_amount'] ?? $data['shipping_tax_amount'] ?? 0));

        // Discount
        $creditmemo->setDiscountAmount((float) ($data['discount_amount'] ?? 0));
        $creditmemo->setBaseDiscountAmount((float) ($data['base_discount_amount'] ?? $data['discount_amount'] ?? 0));

        // Grand total
        $creditmemo->setGrandTotal((float) $data['grand_total']);
        $creditmemo->setBaseGrandTotal((float) ($data['base_grand_total'] ?? $data['grand_total']));

        // Hidden tax (if applicable)
        $creditmemo->setHiddenTaxAmount((float) ($data['hidden_tax_amount'] ?? 0));
        $creditmemo->setBaseHiddenTaxAmount((float) ($data['base_hidden_tax_amount'] ?? 0));

        // Shipping including tax
        $creditmemo->setShippingInclTax((float) ($data['shipping_incl_tax'] ?? 0));
        $creditmemo->setBaseShippingInclTax((float) ($data['base_shipping_incl_tax'] ?? 0));
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

        if (is_array($items)) {
            return $items;
        }

        if (is_string($items)) {
            $decoded = json_decode($items, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Import credit memo items
     */
    protected function _importItems(
        Mage_Sales_Model_Order_Creditmemo $creditmemo,
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
                    'Could not find order item for credit memo item: ' . json_encode($itemData),
                    Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
                );
                continue;
            }

            /** @var Mage_Sales_Model_Order_Creditmemo_Item $creditmemoItem */
            $creditmemoItem = Mage::getModel('sales/order_creditmemo_item');

            $creditmemoItem->setCreditmemo($creditmemo);
            $creditmemoItem->setOrderItem($orderItem);
            $creditmemoItem->setOrderItemId($orderItem->getId());

            // Product info
            $creditmemoItem->setProductId($orderItem->getProductId());
            $creditmemoItem->setSku($orderItem->getSku());
            $creditmemoItem->setName($orderItem->getName());

            // Quantity
            $qty = (float) ($itemData['qty'] ?? 1);
            $creditmemoItem->setQty($qty);

            // Prices (from order item or override)
            $creditmemoItem->setPrice((float) ($itemData['price'] ?? $orderItem->getPrice()));
            $creditmemoItem->setBasePrice((float) ($itemData['base_price'] ?? $orderItem->getBasePrice()));

            // Row totals
            $rowTotal = (float) ($itemData['row_total'] ?? ($creditmemoItem->getPrice() * $qty));
            $creditmemoItem->setRowTotal($rowTotal);
            $creditmemoItem->setBaseRowTotal((float) ($itemData['base_row_total'] ?? $rowTotal));

            // Tax
            $creditmemoItem->setTaxAmount((float) ($itemData['tax_amount'] ?? 0));
            $creditmemoItem->setBaseTaxAmount((float) ($itemData['base_tax_amount'] ?? 0));

            // Discount
            $creditmemoItem->setDiscountAmount((float) ($itemData['discount_amount'] ?? 0));
            $creditmemoItem->setBaseDiscountAmount((float) ($itemData['base_discount_amount'] ?? 0));

            // Back to stock flag (for historical imports, typically false)
            $creditmemoItem->setBackToStock((bool) ($itemData['back_to_stock'] ?? false));

            $creditmemo->addItem($creditmemoItem);

            // Update order item qty_refunded (without full save)
            $orderItem->setQtyRefunded($orderItem->getQtyRefunded() + $qty);
            $orderItem->setAmountRefunded($orderItem->getAmountRefunded() + $rowTotal);
            $orderItem->setBaseAmountRefunded($orderItem->getBaseAmountRefunded() + $creditmemoItem->getBaseRowTotal());
        }
    }

    /**
     * Create credit memo items from all order items (full refund)
     */
    protected function _createItemsFromOrder(
        Mage_Sales_Model_Order_Creditmemo $creditmemo,
        Mage_Sales_Model_Order $order,
    ): void {
        foreach ($order->getAllItems() as $orderItem) {
            // Skip already fully refunded items
            $qtyToRefund = $orderItem->getQtyInvoiced() - $orderItem->getQtyRefunded();
            if ($qtyToRefund <= 0) {
                continue;
            }

            /** @var Mage_Sales_Model_Order_Creditmemo_Item $creditmemoItem */
            $creditmemoItem = Mage::getModel('sales/order_creditmemo_item');

            $creditmemoItem->setCreditmemo($creditmemo);
            $creditmemoItem->setOrderItem($orderItem);
            $creditmemoItem->setOrderItemId($orderItem->getId());

            $creditmemoItem->setProductId($orderItem->getProductId());
            $creditmemoItem->setSku($orderItem->getSku());
            $creditmemoItem->setName($orderItem->getName());

            $creditmemoItem->setQty($qtyToRefund);
            $creditmemoItem->setPrice($orderItem->getPrice());
            $creditmemoItem->setBasePrice($orderItem->getBasePrice());

            $creditmemoItem->setRowTotal($orderItem->getRowTotal());
            $creditmemoItem->setBaseRowTotal($orderItem->getBaseRowTotal());

            $creditmemoItem->setTaxAmount($orderItem->getTaxAmount());
            $creditmemoItem->setBaseTaxAmount($orderItem->getBaseTaxAmount());

            $creditmemoItem->setDiscountAmount($orderItem->getDiscountAmount());
            $creditmemoItem->setBaseDiscountAmount($orderItem->getBaseDiscountAmount());

            // Don't return to stock for historical imports
            $creditmemoItem->setBackToStock(false);

            $creditmemo->addItem($creditmemoItem);

            // Update order item
            $orderItem->setQtyRefunded($orderItem->getQtyInvoiced());
            $orderItem->setAmountRefunded($orderItem->getRowTotal());
            $orderItem->setBaseAmountRefunded($orderItem->getBaseRowTotal());
        }
    }

    /**
     * Update order with refunded totals
     */
    protected function _updateOrderRefundedTotals(
        Mage_Sales_Model_Order $order,
        Mage_Sales_Model_Order_Creditmemo $creditmemo,
    ): void {
        // Update refunded amounts
        $order->setTotalRefunded($order->getTotalRefunded() + $creditmemo->getGrandTotal());
        $order->setBaseTotalRefunded($order->getBaseTotalRefunded() + $creditmemo->getBaseGrandTotal());
        $order->setSubtotalRefunded($order->getSubtotalRefunded() + $creditmemo->getSubtotal());
        $order->setBaseSubtotalRefunded($order->getBaseSubtotalRefunded() + $creditmemo->getBaseSubtotal());
        $order->setTaxRefunded($order->getTaxRefunded() + $creditmemo->getTaxAmount());
        $order->setBaseTaxRefunded($order->getBaseTaxRefunded() + $creditmemo->getBaseTaxAmount());
        $order->setShippingRefunded($order->getShippingRefunded() + $creditmemo->getShippingAmount());
        $order->setBaseShippingRefunded($order->getBaseShippingRefunded() + $creditmemo->getBaseShippingAmount());
        $order->setDiscountRefunded($order->getDiscountRefunded() + abs($creditmemo->getDiscountAmount()));
        $order->setBaseDiscountRefunded($order->getBaseDiscountRefunded() + abs($creditmemo->getBaseDiscountAmount()));

        // Update adjustment amounts
        $order->setAdjustmentPositive($order->getAdjustmentPositive() + $creditmemo->getAdjustmentPositive());
        $order->setBaseAdjustmentPositive($order->getBaseAdjustmentPositive() + $creditmemo->getBaseAdjustmentPositive());
        $order->setAdjustmentNegative($order->getAdjustmentNegative() + $creditmemo->getAdjustmentNegative());
        $order->setBaseAdjustmentNegative($order->getBaseAdjustmentNegative() + $creditmemo->getBaseAdjustmentNegative());

        // Update online/offline refund totals
        if ($creditmemo->getOfflineRequested()) {
            $order->setTotalOfflineRefunded($order->getTotalOfflineRefunded() + $creditmemo->getGrandTotal());
            $order->setBaseTotalOfflineRefunded($order->getBaseTotalOfflineRefunded() + $creditmemo->getBaseGrandTotal());
        } else {
            $order->setTotalOnlineRefunded($order->getTotalOnlineRefunded() + $creditmemo->getGrandTotal());
            $order->setBaseTotalOnlineRefunded($order->getBaseTotalOnlineRefunded() + $creditmemo->getBaseGrandTotal());
        }
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

        // Validate items have required fields
        $items = $this->_parseItems($data);
        foreach ($items as $i => $item) {
            if (empty($item['sku']) && empty($item['order_item_id'])) {
                $errors[] = "Item #{$i} must have either sku or order_item_id";
            }
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function export(array $filters = []): iterable
    {
        $collection = Mage::getModel('sales/order_creditmemo')->getCollection();

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

        foreach ($collection as $creditmemo) {
            yield $this->_exportCreditmemo($creditmemo);
        }
    }

    /**
     * Export single credit memo to array
     */
    protected function _exportCreditmemo(Mage_Sales_Model_Order_Creditmemo $creditmemo): array
    {
        $data = $creditmemo->getData();

        // Add items
        $data['items'] = [];
        foreach ($creditmemo->getAllItems() as $item) {
            $data['items'][] = $item->getData();
        }

        return $data;
    }
}
