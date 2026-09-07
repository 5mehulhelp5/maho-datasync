<?php

/**
 * Maho DataSync Shipment Entity Handler
 *
 * Handles import of sales shipments for historical orders.
 * Creates shipment records WITH tracking numbers, WITHOUT triggering emails.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Model_Entity_Shipment extends Maho_DataSync_Model_Entity_Abstract
{
    protected array $_requiredFields = ['order_id'];

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
        return 'shipment';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'Shipments';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function findExisting(array $data): ?int
    {
        // Find by increment_id if provided
        if (!empty($data['increment_id'])) {
            $shipment = Mage::getModel('sales/order_shipment')->getCollection()
                ->addFieldToFilter('increment_id', $data['increment_id'])
                ->getFirstItem();

            if ($shipment->getId()) {
                return (int) $shipment->getId();
            }
        }

        // Also check by order_id + source tracking
        if (!empty($data['order_id']) && !empty($data['entity_id'])) {
            $shipment = Mage::getModel('sales/order_shipment')->getCollection()
                ->addFieldToFilter('order_id', $data['order_id'])
                ->addFieldToFilter('datasync_source_id', $data['entity_id'])
                ->getFirstItem();

            if ($shipment->getId()) {
                return (int) $shipment->getId();
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

        // Shipments are typically not updated after creation
        if ($existingId) {
            $this->_log("Shipment #{$existingId} already exists, skipping");
            return $existingId;
        }

        return $this->_createShipment($data, $registry);
    }

    /**
     * Create a new shipment
     *
     * @param array $data Shipment data
     * @return int New shipment entity_id
     * @throws Maho_DataSync_Exception
     */
    protected function _createShipment(array $data, Maho_DataSync_Model_Registry $registry): int
    {
        // Load the order
        $orderId = $data['order_id'];
        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->load($orderId);

        if (!$order->getId()) {
            throw new Maho_DataSync_Exception(
                "Order #{$orderId} not found for shipment import",
                Maho_DataSync_Exception::CODE_FK_RESOLUTION_FAILED,
            );
        }

        $this->_log("Creating shipment for order #{$order->getIncrementId()}");

        /** @var Mage_Sales_Model_Order_Shipment $shipment */
        $shipment = Mage::getModel('sales/order_shipment');

        // Set order relationship
        $shipment->setOrder($order);
        $shipment->setOrderId($order->getId());
        $shipment->setStoreId($order->getStoreId());

        // CRITICAL: Prevent email sending
        $shipment->setEmailSent(1);
        $shipment->setSendEmail(false);

        // Generate or use provided increment_id
        if (!empty($data['target_increment_id'])) {
            $shipment->setIncrementId($data['target_increment_id']);
        } else {
            $shipment->setIncrementId(
                Mage::getSingleton('eav/config')
                    ->getEntityType('shipment')
                    ->fetchNewIncrementId($order->getStoreId()),
            );
        }

        // Dates
        $shipment->setCreatedAt($this->_parseDate($data['created_at'] ?? null) ?? Mage_Core_Model_Locale::nowUtc());
        if (!empty($data['updated_at'])) {
            $shipment->setUpdatedAt($this->_parseDate($data['updated_at']));
        }

        // Shipping address
        if ($order->getShippingAddress()) {
            $shipment->setShippingAddressId($order->getShippingAddress()->getId());
        }

        // Billing address
        if ($order->getBillingAddress()) {
            $shipment->setBillingAddressId($order->getBillingAddress()->getId());
        }

        // Customer info
        $shipment->setCustomerId($order->getCustomerId());

        // Totals
        $shipment->setTotalQty((float) ($data['total_qty'] ?? 0));
        $shipment->setTotalWeight((float) ($data['total_weight'] ?? 0));

        // DataSync tracking
        $sourceSystem = $data['_source_system'] ?? 'import';
        $sourceId = $data['entity_id'] ?? null;

        $shipment->setData('datasync_source_system', $sourceSystem);
        if ($sourceId) {
            $shipment->setData('datasync_source_id', (int) $sourceId);
        }
        $shipment->setData('datasync_source_increment_id', $data['increment_id'] ?? null);
        $shipment->setData('datasync_imported_at', Mage_Core_Model_Locale::nowUtc());

        // Save shipment first to get entity_id
        try {
            // Use resource model directly to avoid observers/events
            $shipment->getResource()->save($shipment);
        } catch (Exception $e) {
            throw Maho_DataSync_Exception::importFailed(
                'shipment',
                $data['entity_id'] ?? 0,
                $sourceSystem,
                $e->getMessage(),
            );
        }

        // Import shipment items
        $items = $this->_parseItems($data);
        if (!empty($items)) {
            $this->_importItems($shipment, $items, $order);
        } else {
            // Auto-create items from order if not specified
            $this->_createItemsFromOrder($shipment, $order);
        }

        // Import tracking numbers
        $tracks = $this->_parseTracks($data);
        if (!empty($tracks)) {
            $this->_importTracks($shipment, $tracks);
        }

        // Calculate totals
        $this->_recalculateTotals($shipment);

        // Save shipment items explicitly (resource save doesn't cascade to items)
        try {
            foreach ($shipment->getAllItems() as $shipmentItem) {
                $shipmentItem->setParentId($shipment->getId());
                $shipmentItem->getResource()->save($shipmentItem);
            }
            // Save tracks
            foreach ($shipment->getAllTracks() as $track) {
                $track->setParentId($shipment->getId());
                $track->getResource()->save($track);
            }
        } catch (Exception $e) {
            throw Maho_DataSync_Exception::importFailed(
                'shipment',
                $data['entity_id'] ?? 0,
                $sourceSystem,
                'Failed to save shipment items/tracks: ' . $e->getMessage(),
            );
        }

        // Update order shipped quantities
        $this->_updateOrderShippedQtys($order, $shipment);

        // Save order items to persist qty_shipped changes
        foreach ($order->getAllItems() as $orderItem) {
            $orderItem->getResource()->save($orderItem);
        }

        // Add comment to order
        $trackInfo = count($tracks) > 0 ? ' with ' . count($tracks) . ' tracking number(s)' : '';
        $order->addStatusHistoryComment(
            "Shipment #{$shipment->getIncrementId()} imported via DataSync from {$sourceSystem}{$trackInfo}",
            false,
        );
        $order->getResource()->save($order);

        // Update shipment grid table for admin visibility
        $this->_updateShipmentGrid($shipment, $order);

        // Set external reference for registry
        $data['_external_ref'] = $shipment->getIncrementId();

        $this->_log("Created shipment #{$shipment->getId()} ({$shipment->getIncrementId()}) for order #{$order->getIncrementId()}");

        return (int) $shipment->getId();
    }

    /**
     * Update shipment grid table for admin visibility
     */
    protected function _updateShipmentGrid(
        Mage_Sales_Model_Order_Shipment $shipment,
        Mage_Sales_Model_Order $order,
    ): void {
        $resource = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_write');
        $gridTable = $resource->getTableName('sales/shipment_grid');

        $shippingAddress = $order->getShippingAddress();
        $shippingName = $shippingAddress
            ? trim($shippingAddress->getFirstname() . ' ' . $shippingAddress->getLastname())
            : '';

        $data = [
            'entity_id' => $shipment->getId(),
            'store_id' => $shipment->getStoreId(),
            'total_qty' => $shipment->getTotalQty(),
            'order_id' => $shipment->getOrderId(),
            'order_increment_id' => $order->getIncrementId(),
            'increment_id' => $shipment->getIncrementId(),
            'created_at' => $shipment->getCreatedAt(),
            'order_created_at' => $order->getCreatedAt(),
            'shipping_name' => $shippingName,
        ];

        $connection->insertOnDuplicate($gridTable, $data, array_keys($data));
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
     * Parse tracking numbers from data
     *
     * Supports multiple formats:
     * 1. Array: $data['tracks'] = [['carrier_code' => 'auspost', 'track_number' => 'ABC123'], ...]
     * 2. JSON string
     * 3. Simple format: carrier:number|carrier:number
     */
    protected function _parseTracks(array $data): array
    {
        if (empty($data['tracks'])) {
            return [];
        }

        $tracks = $data['tracks'];

        // Already an array
        if (is_array($tracks)) {
            return $tracks;
        }

        // Try JSON decode
        if (is_string($tracks)) {
            $decoded = json_decode($tracks, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            // Try pipe-delimited format: carrier:number|carrier:number
            if (str_contains($tracks, '|') || str_contains($tracks, ':')) {
                return $this->_parsePipeDelimitedTracks($tracks);
            }
        }

        return [];
    }

    /**
     * Parse pipe-delimited tracks format
     *
     * Format: carrier:number|carrier:number
     * Example: auspost:ABC123|auspost:DEF456
     */
    protected function _parsePipeDelimitedTracks(string $tracks): array
    {
        $result = [];
        $trackStrings = explode('|', $tracks);

        foreach ($trackStrings as $trackString) {
            $parts = explode(':', trim($trackString), 2);
            if (count($parts) >= 2) {
                $result[] = [
                    'carrier_code' => trim($parts[0]),
                    'track_number' => trim($parts[1]),
                ];
            } elseif (count($parts) === 1 && !empty($parts[0])) {
                // Just a tracking number, no carrier
                $result[] = [
                    'carrier_code' => 'custom',
                    'track_number' => trim($parts[0]),
                ];
            }
        }

        return $result;
    }

    /**
     * Import shipment items
     */
    protected function _importItems(
        Mage_Sales_Model_Order_Shipment $shipment,
        array $items,
        Mage_Sales_Model_Order $order,
    ): void {
        // An order can carry the same SKU on more than one line, so track which local
        // items have already been claimed and never hand one to two shipment items.
        $claimedItemIds = [];

        foreach ($items as $itemData) {
            // Find matching order item
            $orderItem = null;

            // order_item_id is the SOURCE system's id. DataSync creates fresh local
            // order items with new ids, so this lookup only hits when the source
            // happens to share our numbering - in practice almost never.
            if (!empty($itemData['order_item_id'])) {
                $orderItem = $order->getItemById($itemData['order_item_id']);
            }

            // SKU is the mapping that actually resolves, so it has to run whenever the
            // id lookup misses - not only when the id is absent. It used to be the
            // 'elseif' branch of the lookup above, which meant that for any source
            // supplying order_item_id (all of them) it never ran: every item fell
            // through to the 'continue' below and the shipment was written with no
            // items at all, leaving qty_shipped at 0 on the order.
            if (!$orderItem && !empty($itemData['sku'])) {
                foreach ($order->getAllItems() as $item) {
                    if ($item->getSku() === $itemData['sku']
                        && !isset($claimedItemIds[$item->getId()])
                    ) {
                        $orderItem = $item;
                        break;
                    }
                }
            }

            if (!$orderItem) {
                $this->_log(
                    'Could not find order item for shipment item: ' . json_encode($itemData),
                    Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
                );
                continue;
            }

            $claimedItemIds[$orderItem->getId()] = true;

            /** @var Mage_Sales_Model_Order_Shipment_Item $shipmentItem */
            $shipmentItem = Mage::getModel('sales/order_shipment_item');

            $shipmentItem->setShipment($shipment);
            $shipmentItem->setOrderItem($orderItem);
            $shipmentItem->setOrderItemId($orderItem->getId());

            // Product info
            $shipmentItem->setProductId($orderItem->getProductId());
            $shipmentItem->setSku($orderItem->getSku());
            $shipmentItem->setName($orderItem->getName());

            // Quantity
            $qty = (float) ($itemData['qty'] ?? $orderItem->getQtyOrdered());
            $shipmentItem->setQty($qty);

            // Weight
            $shipmentItem->setWeight($orderItem->getWeight());
            $shipmentItem->setRowWeight($orderItem->getWeight() * $qty);

            // Price (for packing slips)
            $shipmentItem->setPrice($orderItem->getPrice());

            $shipment->addItem($shipmentItem);

            // Update order item qty_shipped (without full save)
            $orderItem->setQtyShipped($orderItem->getQtyShipped() + $qty);
        }
    }

    /**
     * Create shipment items from all order items (full shipment)
     */
    protected function _createItemsFromOrder(
        Mage_Sales_Model_Order_Shipment $shipment,
        Mage_Sales_Model_Order $order,
    ): void {
        foreach ($order->getAllItems() as $orderItem) {
            // Skip virtual items
            if ($orderItem->getIsVirtual()) {
                continue;
            }

            // Skip already fully shipped items
            $qtyToShip = $orderItem->getQtyOrdered() - $orderItem->getQtyShipped();
            if ($qtyToShip <= 0) {
                continue;
            }

            /** @var Mage_Sales_Model_Order_Shipment_Item $shipmentItem */
            $shipmentItem = Mage::getModel('sales/order_shipment_item');

            $shipmentItem->setShipment($shipment);
            $shipmentItem->setOrderItem($orderItem);
            $shipmentItem->setOrderItemId($orderItem->getId());

            $shipmentItem->setProductId($orderItem->getProductId());
            $shipmentItem->setSku($orderItem->getSku());
            $shipmentItem->setName($orderItem->getName());

            $shipmentItem->setQty($qtyToShip);
            $shipmentItem->setWeight($orderItem->getWeight());
            $shipmentItem->setRowWeight($orderItem->getWeight() * $qtyToShip);
            $shipmentItem->setPrice($orderItem->getPrice());

            $shipment->addItem($shipmentItem);

            // Update order item
            $orderItem->setQtyShipped($orderItem->getQtyOrdered());
        }
    }

    /**
     * Import tracking numbers
     */
    protected function _importTracks(Mage_Sales_Model_Order_Shipment $shipment, array $tracks): void
    {
        foreach ($tracks as $trackData) {
            /** @var Mage_Sales_Model_Order_Shipment_Track $track */
            $track = Mage::getModel('sales/order_shipment_track');

            $track->setShipment($shipment);
            $track->setOrderId($shipment->getOrderId());

            // Carrier
            $carrierCode = $trackData['carrier_code'] ?? $trackData['carrier'] ?? 'custom';
            $track->setCarrierCode($carrierCode);

            // Title (carrier name for display)
            $title = $trackData['title'] ?? $this->_getCarrierTitle($carrierCode);
            $track->setTitle($title);

            // Tracking number
            $track->setTrackNumber($trackData['track_number'] ?? $trackData['number'] ?? '');

            // Weight (optional)
            if (isset($trackData['weight'])) {
                $track->setWeight((float) $trackData['weight']);
            }

            // Quantity (optional)
            if (isset($trackData['qty'])) {
                $track->setQty((float) $trackData['qty']);
            }

            // Created at
            if (!empty($trackData['created_at'])) {
                $track->setCreatedAt($this->_parseDate($trackData['created_at']));
            } else {
                $track->setCreatedAt($shipment->getCreatedAt());
            }

            $shipment->addTrack($track);
        }
    }

    /**
     * Get carrier title from code
     */
    protected function _getCarrierTitle(string $carrierCode): string
    {
        $carriers = [
            'auspost' => 'Australia Post',
            'auspost_eparcel' => 'Australia Post eParcel',
            'dhl' => 'DHL',
            'fedex' => 'FedEx',
            'ups' => 'UPS',
            'usps' => 'USPS',
            'tnt' => 'TNT',
            'startrack' => 'StarTrack',
            'aramex' => 'Aramex',
            'sendle' => 'Sendle',
            'custom' => 'Custom',
        ];

        return $carriers[$carrierCode] ?? ucfirst($carrierCode);
    }

    /**
     * Recalculate shipment totals
     */
    protected function _recalculateTotals(Mage_Sales_Model_Order_Shipment $shipment): void
    {
        $totalQty = 0;
        $totalWeight = 0;

        foreach ($shipment->getAllItems() as $item) {
            $totalQty += $item->getQty();
            $totalWeight += $item->getRowWeight();
        }

        $shipment->setTotalQty($totalQty);
        $shipment->setTotalWeight($totalWeight);
    }

    /**
     * Update order with shipped quantities
     */
    protected function _updateOrderShippedQtys(
        Mage_Sales_Model_Order $order,
        Mage_Sales_Model_Order_Shipment $shipment,
    ): void {
        // Check if order is fully shipped
        $allShipped = true;
        foreach ($order->getAllItems() as $orderItem) {
            if (!$orderItem->getIsVirtual() && $orderItem->getQtyShipped() < $orderItem->getQtyOrdered()) {
                $allShipped = false;
                break;
            }
        }

        // If fully shipped and invoiced, order can be marked complete
        // But we don't change state automatically - leave that to the migration
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function validate(array $data): array
    {
        $errors = parent::validate($data);

        // Validate tracks have required fields
        $tracks = $this->_parseTracks($data);
        foreach ($tracks as $i => $track) {
            if (empty($track['track_number']) && empty($track['number'])) {
                $errors[] = "Track #{$i} is missing track_number";
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
        $collection = Mage::getModel('sales/order_shipment')->getCollection();

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

        foreach ($collection as $shipment) {
            yield $this->_exportShipment($shipment);
        }
    }

    /**
     * Export single shipment to array
     */
    protected function _exportShipment(Mage_Sales_Model_Order_Shipment $shipment): array
    {
        $data = $shipment->getData();

        // Add items
        $data['items'] = [];
        foreach ($shipment->getAllItems() as $item) {
            $data['items'][] = $item->getData();
        }

        // Add tracks
        $data['tracks'] = [];
        foreach ($shipment->getAllTracks() as $track) {
            $data['tracks'][] = $track->getData();
        }

        return $data;
    }
}
