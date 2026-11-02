<?php

/**
 * Maho DataSync Order Entity Handler
 *
 * Handles import/export of sales orders with items, addresses, and payments.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Model_Entity_Order extends Maho_DataSync_Model_Entity_Abstract
{
    protected array $_requiredFields = ['increment_id', 'grand_total', 'base_grand_total'];

    protected array $_foreignKeyFields = [
        'customer_id' => [
            'entity_type' => 'customer',
            'required' => false, // Guest orders have null customer_id
        ],
    ];

    protected ?string $_externalRefField = 'increment_id';

    /**
     * Default custom fields to copy from source order
     * These can be overridden via app/etc/datasync/order_custom_fields.json
     */
    protected array $_customFields = [
        'netsuite_id',
        'netsuite_date_synced',
        'ship_note_id',
    ];

    /**
     * Cached config from JSON file
     */
    protected static ?array $_customFieldsConfig = null;

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getEntityType(): string
    {
        return 'order';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'Sales Orders';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function findExisting(array $data): ?int
    {
        if (empty($data['increment_id'])) {
            return null;
        }

        $order = Mage::getModel('sales/order')->loadByIncrementId($data['increment_id']);

        return $order->getId() ? (int) $order->getId() : null;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function import(array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $existingId = $data['_existing_id'] ?? null;
        $action = $data['_action'] ?? 'create';

        // Orders are typically not updated after creation
        // If updating, we only update specific fields like status
        if ($existingId) {
            return $this->_updateOrder($existingId, $data, $action);
        }

        return $this->_createOrder($data, $registry);
    }

    /**
     * Create a new order
     *
     * @param array $data Order data
     * @return int New order entity_id
     * @throws Maho_DataSync_Exception
     */
    protected function _createOrder(array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $this->_log("Creating order: {$data['increment_id']}");

        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order');

        // Store assignment
        $storeId = $this->_getStoreId($data, Mage::app()->getDefaultStoreView()->getId());
        $order->setStoreId($storeId);

        // Generate new increment_id or use provided target_increment_id
        if (!empty($data['target_increment_id'])) {
            $order->setIncrementId($data['target_increment_id']);
        } else {
            // Let Maho generate a new increment_id
            $order->setIncrementId(
                Mage::getSingleton('eav/config')
                    ->getEntityType('order')
                    ->fetchNewIncrementId($storeId),
            );
        }

        // Customer assignment
        $this->_setCustomerData($order, $data);

        // Order dates
        $order->setCreatedAt($this->_parseDate($data['created_at'] ?? null) ?? Mage_Core_Model_Locale::nowUtc());
        if (!empty($data['updated_at'])) {
            $order->setUpdatedAt($this->_parseDate($data['updated_at']));
        }

        // Order status and state - use setData to bypass state validation for historical imports
        // (setState() throws "The Order State must not be set manually" for states like 'complete')
        $order->setData('state', $data['state'] ?? Mage_Sales_Model_Order::STATE_NEW);
        $order->setData('status', $data['status'] ?? 'pending');

        // Currency
        $order->setBaseCurrencyCode($data['base_currency_code'] ?? 'AUD');
        $order->setGlobalCurrencyCode($data['global_currency_code'] ?? 'AUD');
        $order->setStoreCurrencyCode($data['store_currency_code'] ?? 'AUD');
        $order->setOrderCurrencyCode($data['order_currency_code'] ?? 'AUD');
        $order->setBaseToGlobalRate($data['base_to_global_rate'] ?? 1.0);
        $order->setBaseToOrderRate($data['base_to_order_rate'] ?? 1.0);
        $order->setStoreToBaseRate($data['store_to_base_rate'] ?? 1.0);
        $order->setStoreToOrderRate($data['store_to_order_rate'] ?? 1.0);

        // Totals
        $this->_setOrderTotals($order, $data);

        // Shipping method
        if (!empty($data['shipping_method'])) {
            $order->setShippingMethod($data['shipping_method']);
            $order->setShippingDescription($data['shipping_description'] ?? $data['shipping_method']);
        }

        // Payment method - use default from entity options if provided is invalid
        $entityOptions = $data['_entity_options'] ?? [];
        $defaultPayment = $entityOptions['default_payment_method'] ?? 'checkmo';
        $paymentMethod = $data['payment_method'] ?? $defaultPayment;

        // Validate and fallback to default if invalid
        if (!$this->_isValidPaymentMethod($paymentMethod)) {
            $this->_log(
                "Order {$data['increment_id']}: Invalid payment method '{$paymentMethod}', using '{$defaultPayment}'",
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
            $paymentMethod = $defaultPayment;
        }

        // Weight and items count
        $order->setTotalQtyOrdered($data['total_qty_ordered'] ?? 0);
        $order->setWeight($data['weight'] ?? 0);
        $order->setTotalItemCount($data['total_item_count'] ?? 0);

        // Remote IP (for fraud detection tracking)
        if (!empty($data['remote_ip'])) {
            $order->setRemoteIp($data['remote_ip']);
        }

        // Order protect code (for guest order lookup)
        $order->setProtectCode(Mage::helper('core')->getRandomString(32));

        // CRITICAL: Prevent order confirmation email
        $order->setEmailSent(1);
        $order->setSendEmail(false);
        $order->setCanSendNewEmailFlag(false);

        // Custom fields (NetSuite integration, etc.)
        $this->_importCustomFields($order, $data);

        // DataSync tracking
        $sourceSystem = $data['_source_system'] ?? 'import';
        $sourceId = $data['entity_id'] ?? null;

        $order->setData('datasync_source_system', $sourceSystem);
        if ($sourceId) {
            $order->setData('datasync_source_id', (int) $sourceId);
        }
        $order->setData('datasync_source_increment_id', $data['increment_id']);
        $order->setData('datasync_imported_at', Mage_Core_Model_Locale::nowUtc());

        // Import addresses BEFORE first save (required by some observers)
        $this->_importAddresses($order, $data);

        // Import payment
        $this->_importPayment($order, $data, $paymentMethod);

        // Save order first to get entity_id
        try {
            $order->save();
        } catch (Exception $e) {
            // Log full trace for debugging
            Mage::log("Order import error for {$data['increment_id']}: " . $e->getMessage() . "\n" . $e->getTraceAsString(), Mage::LOG_ERROR, 'datasync_debug.log');
            throw Maho_DataSync_Exception::importFailed(
                'order',
                $data['entity_id'] ?? 0,
                $sourceSystem,
                $e->getMessage(),
            );
        }

        // Import order items (supports JSON string or array)
        $items = $this->_parseItems($data);
        if (!empty($items)) {
            $this->_importItems($order, $items);
        }

        // Recalculate totals after items
        $this->_recalculateTotals($order);

        // Save again with addresses, payment, and items
        try {
            $order->save();
        } catch (Exception $e) {
            throw Maho_DataSync_Exception::importFailed(
                'order',
                $data['entity_id'] ?? 0,
                $sourceSystem,
                'Failed to save order with items: ' . $e->getMessage(),
            );
        }

        // Import status history (after order is saved and has ID)
        $this->_importStatusHistory($order, $data);

        // Import invoices (after order is saved and has ID)
        $this->_importInvoices($order, $data);

        // Import shipments (after order is saved and has ID)
        $this->_importShipments($order, $data);

        // Import related tables (e.g., shipnote_note) configured in order_custom_fields.json
        $this->_importRelatedTables($order, $data);

        // Fire post-import event for extensions to update grid fields
        // This is useful for small imports. For bulk imports, use datasync:grid:sync command
        Mage::dispatchEvent('datasync_order_import_after', [
            'order' => $order,
            'data' => $data,
        ]);

        // Set external reference for registry
        $data['_external_ref'] = $data['increment_id'];

        $this->_log("Created order #{$order->getId()} ({$order->getIncrementId()})");

        return (int) $order->getId();
    }

    /**
     * Update an existing order
     *
     * @param string $action 'update' or 'merge'
     * @throws Maho_DataSync_Exception
     */
    protected function _updateOrder(int $existingId, array $data, string $action): int
    {
        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->load($existingId);

        if (!$order->getId()) {
            throw new Maho_DataSync_Exception(
                "Order #{$existingId} not found for update",
                Maho_DataSync_Exception::CODE_ENTITY_NOT_FOUND,
            );
        }

        $this->_log("Updating order #{$existingId} ({$order->getIncrementId()})");

        // Only allow updating certain fields on existing orders
        $updatableFields = ['status', 'state', 'customer_note', 'customer_note_notify'];

        foreach ($updatableFields as $field) {
            if (isset($data[$field])) {
                if ($action === 'merge' && $data[$field] === '') {
                    continue; // Keep existing value in merge mode
                }
                $order->setData($field, $data[$field]);
            }
        }

        // Import custom fields (configurable via JSON)
        $this->_importCustomFields($order, $data);

        // Update DataSync tracking
        $order->setData('datasync_imported_at', Mage_Core_Model_Locale::nowUtc());

        try {
            $order->save();
        } catch (Exception $e) {
            throw Maho_DataSync_Exception::importFailed(
                'order',
                $existingId,
                $data['_source_system'] ?? 'import',
                $e->getMessage(),
            );
        }

        // Import related tables (shipnote_note, etc.)
        $this->_importRelatedTables($order, $data);

        return $existingId;
    }

    /**
     * Set customer data on order
     */
    protected function _setCustomerData(Mage_Sales_Model_Order $order, array $data): void
    {
        // Customer ID (may be null for guest orders, or resolved FK)
        $customerId = $data['customer_id'] ?? null;
        $customer = null;

        // Try to load customer by ID first
        if ($customerId) {
            $customer = Mage::getModel('customer/customer')->load($customerId);
            if (!$customer->getId()) {
                $customer = null;
                $this->_log("Customer ID {$customerId} not found, will try email lookup", Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG);
            }
        }

        // Fallback: lookup customer by email if no customer_id or customer not found
        if (!$customer && !empty($data['customer_email'])) {
            $websiteId = $order->getStore()->getWebsiteId();
            $customer = Mage::getModel('customer/customer');
            $customer->setWebsiteId($websiteId);
            $customer->loadByEmail($data['customer_email']);

            if ($customer->getId()) {
                $this->_log("Found existing customer #{$customer->getId()} by email: {$data['customer_email']}", Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG);
            } else {
                $customer = null;
            }
        }

        // Set customer data on order
        if ($customer && $customer->getId()) {
            $order->setCustomerId($customer->getId());
            $order->setCustomerIsGuest(0);
            $order->setCustomerEmail($customer->getEmail());
            $order->setCustomerFirstname($customer->getFirstname());
            $order->setCustomerLastname($customer->getLastname());
            $order->setCustomerMiddlename($customer->getMiddlename());
            $order->setCustomerPrefix($customer->getPrefix());
            $order->setCustomerSuffix($customer->getSuffix());
            $order->setCustomerGroupId($customer->getGroupId());
            $order->setCustomerTaxvat($customer->getTaxvat());
            $order->setCustomerGender((int) $customer->getGender());
            $order->setCustomerDob($customer->getDob());
        } else {
            // Guest order
            $order->setCustomerIsGuest(1);
            $order->setCustomerGroupId(Mage_Customer_Model_Group::NOT_LOGGED_IN_ID);
        }

        // Override with explicit values from import data (allows overriding customer data)
        if (!empty($data['customer_email'])) {
            $order->setCustomerEmail($data['customer_email']);
        }
        if (!empty($data['customer_firstname'])) {
            $order->setCustomerFirstname($this->_cleanString($data['customer_firstname']));
        }
        if (!empty($data['customer_lastname'])) {
            $order->setCustomerLastname($this->_cleanString($data['customer_lastname']));
        }
        if (isset($data['customer_group_id'])) {
            $order->setCustomerGroupId((int) $data['customer_group_id']);
        }
    }

    /**
     * Set order totals
     */
    protected function _setOrderTotals(Mage_Sales_Model_Order $order, array $data): void
    {
        // Subtotal
        $order->setSubtotal((float) ($data['subtotal'] ?? 0));
        $order->setBaseSubtotal((float) ($data['base_subtotal'] ?? $data['subtotal'] ?? 0));

        // Tax
        $order->setTaxAmount((float) ($data['tax_amount'] ?? 0));
        $order->setBaseTaxAmount((float) ($data['base_tax_amount'] ?? $data['tax_amount'] ?? 0));

        // Shipping
        $order->setShippingAmount((float) ($data['shipping_amount'] ?? 0));
        $order->setBaseShippingAmount((float) ($data['base_shipping_amount'] ?? $data['shipping_amount'] ?? 0));
        $order->setShippingTaxAmount((float) ($data['shipping_tax_amount'] ?? 0));
        $order->setBaseShippingTaxAmount((float) ($data['base_shipping_tax_amount'] ?? $data['shipping_tax_amount'] ?? 0));

        // Discount
        $order->setDiscountAmount((float) ($data['discount_amount'] ?? 0));
        $order->setBaseDiscountAmount((float) ($data['base_discount_amount'] ?? $data['discount_amount'] ?? 0));
        if (!empty($data['discount_description'])) {
            $order->setDiscountDescription($data['discount_description']);
        }
        if (!empty($data['coupon_code'])) {
            $order->setCouponCode($data['coupon_code']);
        }

        // Grand total
        $order->setGrandTotal((float) $data['grand_total']);
        $order->setBaseGrandTotal((float) ($data['base_grand_total'] ?? $data['grand_total']));

        // Paid/refunded amounts
        $order->setTotalPaid((float) ($data['total_paid'] ?? 0));
        $order->setBaseTotalPaid((float) ($data['base_total_paid'] ?? $data['total_paid'] ?? 0));
        $order->setTotalRefunded((float) ($data['total_refunded'] ?? 0));
        $order->setBaseTotalRefunded((float) ($data['base_total_refunded'] ?? $data['total_refunded'] ?? 0));
        $order->setTotalDue((float) ($data['total_due'] ?? $data['grand_total']));
        $order->setBaseTotalDue((float) ($data['base_total_due'] ?? $data['total_due'] ?? $data['grand_total']));

        // Invoiced amounts
        $order->setTotalInvoiced((float) ($data['total_invoiced'] ?? 0));
        $order->setBaseTotalInvoiced((float) ($data['base_total_invoiced'] ?? $data['total_invoiced'] ?? 0));
        $order->setSubtotalInvoiced((float) ($data['subtotal_invoiced'] ?? 0));
        $order->setBaseSubtotalInvoiced((float) ($data['base_subtotal_invoiced'] ?? $data['subtotal_invoiced'] ?? 0));
        $order->setTaxInvoiced((float) ($data['tax_invoiced'] ?? 0));
        $order->setBaseTaxInvoiced((float) ($data['base_tax_invoiced'] ?? $data['tax_invoiced'] ?? 0));
        $order->setShippingInvoiced((float) ($data['shipping_invoiced'] ?? 0));
        $order->setBaseShippingInvoiced((float) ($data['base_shipping_invoiced'] ?? $data['shipping_invoiced'] ?? 0));
    }

    /**
     * Load custom fields configuration from JSON file
     */
    protected function _getCustomFieldsConfig(): array
    {
        if (self::$_customFieldsConfig !== null) {
            return self::$_customFieldsConfig;
        }

        $configFile = Mage::getBaseDir('etc') . '/datasync/order_custom_fields.json';
        if (file_exists($configFile)) {
            $json = file_get_contents($configFile);
            $config = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($config)) {
                self::$_customFieldsConfig = $config;
                return $config;
            }
            $this->_log(
                'Failed to parse order_custom_fields.json: ' . json_last_error_msg(),
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
        }

        // Return defaults if no config file
        self::$_customFieldsConfig = [
            'order_fields' => ['fields' => $this->_customFields],
            'related_tables' => ['tables' => []],
        ];
        return self::$_customFieldsConfig;
    }

    /**
     * Import custom fields from source order
     *
     * Copies non-standard columns that exist in the sales_flat_order table,
     * such as NetSuite integration fields. Configuration loaded from
     * app/etc/datasync/order_custom_fields.json or defaults.
     */
    protected function _importCustomFields(Mage_Sales_Model_Order $order, array $data): void
    {
        // Get custom fields from entity options, config file, or defaults
        $entityOptions = $data['_entity_options'] ?? [];
        $config = $this->_getCustomFieldsConfig();
        $customFields = $entityOptions['custom_fields'] ?? $config['order_fields']['fields'] ?? $this->_customFields;

        foreach ($customFields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $order->setData($field, $data[$field]);
            }
        }
    }

    /**
     * Import related tables data (e.g., shipnote_note)
     *
     * Called after order is created to sync any related table records.
     * Configuration loaded from app/etc/datasync/order_custom_fields.json
     */
    protected function _importRelatedTables(Mage_Sales_Model_Order $order, array $data): void
    {
        $config = $this->_getCustomFieldsConfig();
        $relatedTables = $config['related_tables']['tables'] ?? [];

        foreach ($relatedTables as $tableName => $tableConfig) {
            $foreignKey = $tableConfig['foreign_key'] ?? null;
            $primaryKey = $tableConfig['primary_key'] ?? 'entity_id';

            if (!$foreignKey) {
                continue;
            }

            // Check if the related data exists in the import data
            $relatedDataKey = 'related_' . $tableName;
            if (isset($data[$relatedDataKey]) && is_array($data[$relatedDataKey])) {
                $this->_importRelatedTableRecord($order, $tableName, $tableConfig, $data[$relatedDataKey]);
            }
        }
    }

    /**
     * Import a single related table record
     */
    protected function _importRelatedTableRecord(
        Mage_Sales_Model_Order $order,
        string $tableName,
        array $tableConfig,
        array $recordData,
    ): void {
        $resource = Mage::getSingleton('core/resource');
        $write = $resource->getConnection('core_write');
        $table = $resource->getTableName($tableName);
        $foreignKey = $tableConfig['foreign_key'];
        $primaryKey = $tableConfig['primary_key'] ?? 'entity_id';
        $fields = $tableConfig['fields'] ?? [];

        // Filter record data to only include configured fields (except primary key)
        $insertData = [];
        foreach ($fields as $field) {
            if ($field !== $primaryKey && array_key_exists($field, $recordData)) {
                $insertData[$field] = $recordData[$field];
            }
        }

        if (empty($insertData)) {
            return;
        }

        try {
            // Insert the related record
            $write->insert($table, $insertData);
            $newId = $write->lastInsertId();

            // Update the order with the new foreign key
            $order->setData($foreignKey, $newId);
            $order->save();

            $this->_log(
                "Imported related record into {$tableName} with ID {$newId} for order {$order->getIncrementId()}",
                Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
            );
        } catch (Exception $e) {
            $this->_log(
                "Failed to import related table {$tableName} for order {$order->getIncrementId()}: " . $e->getMessage(),
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
        }
    }

    /**
     * Import billing and shipping addresses
     */
    protected function _importAddresses(Mage_Sales_Model_Order $order, array $data): void
    {
        // Billing address
        $billingData = $this->_extractAddressData($data, 'billing_');
        if (!empty($billingData)) {
            /** @var Mage_Sales_Model_Order_Address $billing */
            $billing = Mage::getModel('sales/order_address');
            $billing->setAddressType(Mage_Sales_Model_Order_Address::TYPE_BILLING);
            $this->_populateAddress($billing, $billingData, $order);
            $order->setBillingAddress($billing);
        }

        // Shipping address (may be same as billing or separate)
        $sameAsBilling = !empty($data['shipping_same_as_billing']) || !empty($data['same_as_billing']);

        if ($sameAsBilling && !empty($billingData)) {
            /** @var Mage_Sales_Model_Order_Address $shipping */
            $shipping = Mage::getModel('sales/order_address');
            $shipping->setAddressType(Mage_Sales_Model_Order_Address::TYPE_SHIPPING);
            $this->_populateAddress($shipping, $billingData, $order);
            $shipping->setSameAsBilling(true);
            $order->setShippingAddress($shipping);
        } else {
            $shippingData = $this->_extractAddressData($data, 'shipping_');
            if (!empty($shippingData)) {
                /** @var Mage_Sales_Model_Order_Address $shipping */
                $shipping = Mage::getModel('sales/order_address');
                $shipping->setAddressType(Mage_Sales_Model_Order_Address::TYPE_SHIPPING);
                $this->_populateAddress($shipping, $shippingData, $order);
                $order->setShippingAddress($shipping);
            }
        }
    }

    /**
     * Extract address data from order data using prefix
     */
    protected function _extractAddressData(array $data, string $prefix): array
    {
        $addressFields = [
            'firstname', 'lastname', 'middlename', 'prefix', 'suffix',
            'company', 'street', 'city', 'region', 'region_id', 'postcode',
            'country_id', 'telephone', 'fax', 'email',
        ];

        $address = [];
        foreach ($addressFields as $field) {
            $key = $prefix . $field;
            if (isset($data[$key]) && $data[$key] !== '') {
                $address[$field] = $data[$key];
            }
        }

        return $address;
    }

    /**
     * Populate address model from data
     */
    protected function _populateAddress(
        Mage_Sales_Model_Order_Address $address,
        array $data,
        Mage_Sales_Model_Order $order,
    ): void {
        $address->setFirstname($this->_cleanString($data['firstname'] ?? $order->getCustomerFirstname()));
        $address->setLastname($this->_cleanString($data['lastname'] ?? $order->getCustomerLastname()));

        if (!empty($data['middlename'])) {
            $address->setMiddlename($this->_cleanString($data['middlename']));
        }
        if (!empty($data['prefix'])) {
            $address->setPrefix($this->_cleanString($data['prefix']));
        }
        if (!empty($data['suffix'])) {
            $address->setSuffix($this->_cleanString($data['suffix']));
        }
        if (!empty($data['company'])) {
            $address->setCompany($this->_cleanString($data['company']));
        }

        // Street (handle multi-line)
        if (!empty($data['street'])) {
            $street = is_array($data['street'])
                ? $data['street']
                : explode("\n", $data['street']);
            $address->setStreet($street);
        }

        if (!empty($data['city'])) {
            $address->setCity($this->_cleanString($data['city']));
        }
        if (!empty($data['region'])) {
            $address->setRegion($this->_cleanString($data['region']));
        }
        if (!empty($data['region_id'])) {
            $address->setRegionId((int) $data['region_id']);
        }
        if (!empty($data['postcode'])) {
            $address->setPostcode($this->_cleanString($data['postcode']));
        }
        if (!empty($data['country_id'])) {
            $address->setCountryId($this->_cleanString($data['country_id']));
        }
        if (!empty($data['telephone'])) {
            $address->setTelephone($this->_cleanString($data['telephone']));
        }
        if (!empty($data['fax'])) {
            $address->setFax($this->_cleanString($data['fax']));
        }
        if (!empty($data['email'])) {
            $address->setEmail($this->_cleanString($data['email']));
        } else {
            $address->setEmail($order->getCustomerEmail());
        }
    }

    /**
     * Import payment information
     *
     * For historical order imports, we need to bypass payment method validation
     * by pre-setting both the method code and method instance. We also copy ALL
     * payment fields from the source to preserve transaction details.
     *
     * @param array $data Order data (may contain payment_* fields or 'payment' array)
     * @param string $paymentMethod The fallback payment method to use (e.g. 'checkmo')
     */
    protected function _importPayment(Mage_Sales_Model_Order $order, array $data, string $paymentMethod): void
    {
        /** @var Mage_Sales_Model_Order_Payment $payment */
        $payment = Mage::getModel('sales/order_payment');

        // Get payment data - either from 'payment' array or payment_* prefixed fields
        $paymentData = $data['payment'] ?? [];
        if (empty($paymentData)) {
            // Extract payment_* prefixed fields
            foreach ($data as $key => $value) {
                if (str_starts_with($key, 'payment_') && $key !== 'payment_method') {
                    $paymentData[substr($key, 8)] = $value;
                }
            }
        }

        // Set the fallback method code (for method instance)
        $payment->setData('method', $paymentMethod);

        // CRITICAL: Pre-set the method instance to prevent validation during save
        try {
            $methodInstance = Mage::helper('payment')->getMethodInstance($paymentMethod);
            if ($methodInstance && is_object($methodInstance)) {
                $methodInstance->setInfoInstance($payment);
                $payment->setMethodInstance($methodInstance);
            } else {
                $this->_log(
                    "Payment method '{$paymentMethod}' returned false, using fallback",
                    Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
                );
                // Try to use the default fallback method
                $defaultMethod = $data['_entity_options']['default_payment_method'] ?? 'checkmo';
                if ($defaultMethod !== $paymentMethod) {
                    $defaultInstance = Mage::helper('payment')->getMethodInstance($defaultMethod);
                    if ($defaultInstance && is_object($defaultInstance)) {
                        $defaultInstance->setInfoInstance($payment);
                        $payment->setMethodInstance($defaultInstance);
                        $payment->setData('method', $defaultMethod);
                        $paymentMethod = $defaultMethod;
                    }
                }
            }
        } catch (Exception $e) {
            $this->_log(
                "Could not instantiate payment method '{$paymentMethod}': " . $e->getMessage(),
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
        }

        // Copy ALL payment fields from source (except auto-generated and special fields)
        $excludeFields = ['entity_id', 'parent_id', 'quote_payment_id', 'method', 'additional_information'];
        foreach ($paymentData as $field => $value) {
            if (!in_array($field, $excludeFields) && $value !== null) {
                $payment->setData($field, $value);
            }
        }

        // Handle additional_information - parse, add original method, and set
        $additionalInfo = [];
        if (!empty($paymentData['additional_information'])) {
            $rawInfo = $paymentData['additional_information'];
            if (is_string($rawInfo)) {
                $additionalInfo = @unserialize($rawInfo, ['allowed_classes' => false]) ?: [];
            } elseif (is_array($rawInfo)) {
                $additionalInfo = $rawInfo;
            }
        }

        // Store original method in additional_information for reference
        $originalMethod = $paymentData['method'] ?? $data['payment_method'] ?? null;
        if ($originalMethod && $originalMethod !== $paymentMethod) {
            $additionalInfo['original_payment_method'] = $originalMethod;
        }

        // Set all additional_information at once
        if (!empty($additionalInfo)) {
            $payment->setData('additional_information', serialize($additionalInfo));
        }

        // Ensure method is set to fallback (important: do this AFTER copying other fields)
        $payment->setData('method', $paymentMethod);

        // Ensure amounts are set from order if not in payment data
        if (!$payment->getAmountOrdered()) {
            $payment->setAmountOrdered((float) $data['grand_total']);
            $payment->setBaseAmountOrdered((float) ($data['base_grand_total'] ?? $data['grand_total']));
        }
        if (!$payment->getAmountPaid() && !empty($data['total_paid'])) {
            $payment->setAmountPaid((float) $data['total_paid']);
            $payment->setBaseAmountPaid((float) ($data['base_total_paid'] ?? $data['total_paid']));
        }

        $order->setPayment($payment);
    }

    /**
     * Parse items from data
     *
     * Supports multiple formats:
     * 1. Array: $data['items'] = [['sku' => 'ABC', ...], ...]
     * 2. JSON string: $data['items'] = '[{"sku": "ABC", ...}]'
     * 3. Pipe-delimited simple format: $data['items'] = 'SKU1:qty:price|SKU2:qty:price'
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

            // Try pipe-delimited format: SKU:qty:price|SKU:qty:price
            if (str_contains($items, '|') || str_contains($items, ':')) {
                return $this->_parsePipeDelimitedItems($items);
            }
        }

        return [];
    }

    /**
     * Parse pipe-delimited items format
     *
     * Format: SKU:qty:price|SKU:qty:price
     * Example: ABC123:2:29.95|DEF456:1:49.00
     */
    protected function _parsePipeDelimitedItems(string $items): array
    {
        $result = [];
        $itemStrings = explode('|', $items);

        foreach ($itemStrings as $itemString) {
            $parts = explode(':', trim($itemString));
            if (count($parts) >= 1) {
                $item = [
                    'sku' => trim($parts[0]),
                    'qty_ordered' => isset($parts[1]) ? (float) $parts[1] : 1,
                    'price' => isset($parts[2]) ? (float) $parts[2] : 0,
                ];

                // Try to find product name from SKU
                $product = Mage::getModel('catalog/product')->loadByAttribute('sku', $item['sku']);
                if ($product && $product->getId()) {
                    $item['product_id'] = $product->getId();
                    $item['name'] = $product->getName();
                } else {
                    $item['name'] = $item['sku'];
                }

                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Import order items
     *
     * Handles parent-child relationships for configurable/bundle products.
     * Child items have parent_item_id pointing to the parent item.
     */
    protected function _importItems(Mage_Sales_Model_Order $order, array $items): void
    {
        // Build mapping of source item_id to item data for parent lookups
        $sourceItemMap = [];
        foreach ($items as $itemData) {
            if (!empty($itemData['item_id'])) {
                $sourceItemMap[$itemData['item_id']] = $itemData;
            }
        }

        // Track source_item_id => new_item for parent_item_id resolution
        $itemIdMap = [];

        // First pass: Create all items
        foreach ($items as $itemData) {
            /** @var Mage_Sales_Model_Order_Item $item */
            $item = Mage::getModel('sales/order_item');

            // Product info — resolve local product_id by SKU (source IDs don't match target)
            $sku = $this->_cleanString($itemData['sku'] ?? '');
            $localProductId = $this->_resolveLocalProductId($sku, $itemData['product_id'] ?? null);
            $item->setProductId($localProductId);
            $item->setSku($sku);
            $item->setName($this->_cleanString($itemData['name'] ?? $itemData['sku'] ?? ''));
            $item->setProductType($itemData['product_type'] ?? 'simple');

            // Selected options: configurable super-attributes (e.g. Grip Size) + custom
            // options (String / Tension / Cover ...). Copied VERBATIM from the source —
            // Mage_Sales_Model_Order_Item::getProductOptions() reads via the smart
            // core/string::unserialize(), which handles both legacy PHP-serialized and
            // JSON, so no conversion is needed. Do NOT use setProductOptions(): that
            // expects an array and jsonEncodes it, which would double-encode the string.
            // Without this the admin / PDF / email item rows show only the SKU.
            if (!empty($itemData['product_options'])) {
                $item->setData('product_options', $itemData['product_options']);
            }

            // Quantities
            $item->setQtyOrdered((float) ($itemData['qty_ordered'] ?? 1));
            $item->setQtyInvoiced((float) ($itemData['qty_invoiced'] ?? 0));
            $item->setQtyShipped((float) ($itemData['qty_shipped'] ?? 0));
            $item->setQtyRefunded((float) ($itemData['qty_refunded'] ?? 0));
            $item->setQtyCanceled((float) ($itemData['qty_canceled'] ?? 0));

            // Prices
            $item->setPrice((float) ($itemData['price'] ?? 0));
            $item->setBasePrice((float) ($itemData['base_price'] ?? $itemData['price'] ?? 0));
            $item->setOriginalPrice((float) ($itemData['original_price'] ?? $itemData['price'] ?? 0));
            $item->setBaseOriginalPrice((float) ($itemData['base_original_price'] ?? $itemData['original_price'] ?? $itemData['price'] ?? 0));

            // Row totals
            $qty = (float) ($itemData['qty_ordered'] ?? 1);
            $price = (float) ($itemData['price'] ?? 0);
            $rowTotal = (float) ($itemData['row_total'] ?? ($price * $qty));
            $baseRowTotal = (float) ($itemData['base_row_total'] ?? $rowTotal);

            $item->setRowTotal($rowTotal);
            $item->setBaseRowTotal($baseRowTotal);

            // Tax
            $item->setTaxAmount((float) ($itemData['tax_amount'] ?? 0));
            $item->setBaseTaxAmount((float) ($itemData['base_tax_amount'] ?? $itemData['tax_amount'] ?? 0));
            $item->setTaxPercent((float) ($itemData['tax_percent'] ?? 0));

            // Discount
            $item->setDiscountAmount((float) ($itemData['discount_amount'] ?? 0));
            $item->setBaseDiscountAmount((float) ($itemData['base_discount_amount'] ?? $itemData['discount_amount'] ?? 0));
            $item->setDiscountPercent((float) ($itemData['discount_percent'] ?? 0));

            // Row total with tax/discount
            $item->setRowTotalInclTax((float) ($itemData['row_total_incl_tax'] ?? ($rowTotal + ($itemData['tax_amount'] ?? 0))));
            $item->setBaseRowTotalInclTax((float) ($itemData['base_row_total_incl_tax'] ?? $item->getRowTotalInclTax()));

            // Invoiced amounts — REQUIRED for credit memos / refunds. The refundable
            // amount is derived from the item-level *_invoiced totals, not row_total;
            // without these a credit memo computes $0. Map from source when present
            // (exact), else prorate the row totals by the invoiced qty ratio (a
            // fully-invoiced item gets its full row).
            $qtyInvoiced = (float) ($itemData['qty_invoiced'] ?? 0);
            $invRatio = $qty > 0 ? $qtyInvoiced / $qty : 0;
            $item->setRowInvoiced((float) ($itemData['row_invoiced'] ?? round($rowTotal * $invRatio, 4)));
            $item->setBaseRowInvoiced((float) ($itemData['base_row_invoiced'] ?? round($baseRowTotal * $invRatio, 4)));
            $item->setTaxInvoiced((float) ($itemData['tax_invoiced'] ?? round((float) ($itemData['tax_amount'] ?? 0) * $invRatio, 4)));
            $item->setBaseTaxInvoiced((float) ($itemData['base_tax_invoiced'] ?? round((float) ($itemData['base_tax_amount'] ?? $itemData['tax_amount'] ?? 0) * $invRatio, 4)));
            $item->setDiscountInvoiced((float) ($itemData['discount_invoiced'] ?? round((float) ($itemData['discount_amount'] ?? 0) * $invRatio, 4)));
            $item->setBaseDiscountInvoiced((float) ($itemData['base_discount_invoiced'] ?? round((float) ($itemData['base_discount_amount'] ?? $itemData['discount_amount'] ?? 0) * $invRatio, 4)));
            $item->setHiddenTaxInvoiced((float) ($itemData['hidden_tax_invoiced'] ?? round((float) ($itemData['hidden_tax_amount'] ?? 0) * $invRatio, 4)));
            $item->setBaseHiddenTaxInvoiced((float) ($itemData['base_hidden_tax_invoiced'] ?? round((float) ($itemData['base_hidden_tax_amount'] ?? 0) * $invRatio, 4)));

            // Weight
            $item->setWeight((float) ($itemData['weight'] ?? 0));
            $item->setRowWeight((float) ($itemData['row_weight'] ?? ($item->getWeight() * $qty)));

            // Free shipping flag
            $item->setFreeShipping((int) ($itemData['free_shipping'] ?? 0));
            $item->setIsVirtual((int) ($itemData['is_virtual'] ?? 0));

            // Store ID
            $item->setStoreId($order->getStoreId());

            // Source tracking - store original item_id for parent resolution
            $sourceItemId = $itemData['item_id'] ?? $itemData['entity_id'] ?? null;
            if ($sourceItemId) {
                $item->setData('datasync_source_item_id', (int) $sourceItemId);
            }

            // Store source parent_item_id for second pass
            if (!empty($itemData['parent_item_id'])) {
                $item->setData('_source_parent_item_id', (int) $itemData['parent_item_id']);
            }

            $order->addItem($item);

            // Track for parent resolution
            if ($sourceItemId) {
                $itemIdMap[$sourceItemId] = $item;
            }
        }

        // Second pass: Resolve parent_item_id relationships
        foreach ($order->getAllItems() as $item) {
            $sourceParentId = $item->getData('_source_parent_item_id');
            if ($sourceParentId && isset($itemIdMap[$sourceParentId])) {
                $parentItem = $itemIdMap[$sourceParentId];
                // Set the parent item (Magento will resolve the ID after save)
                $item->setParentItem($parentItem);
            }
            // Clean up temp data
            $item->unsetData('_source_parent_item_id');
        }
    }

    /**
     * Recalculate order totals from items
     */
    protected function _recalculateTotals(Mage_Sales_Model_Order $order): void
    {
        $totalQty = 0;
        $weight = 0;
        $itemCount = 0;

        foreach ($order->getAllItems() as $item) {
            $totalQty += $item->getQtyOrdered();
            $weight += $item->getRowWeight();
            $itemCount++;
        }

        $order->setTotalQtyOrdered($totalQty);
        $order->setWeight($weight);
        $order->setTotalItemCount($itemCount);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function export(array $filters = []): iterable
    {
        $collection = Mage::getModel('sales/order')->getCollection();

        // Apply filters
        if (!empty($filters['date_from'])) {
            $collection->addAttributeToFilter('created_at', ['gteq' => $filters['date_from']]);
        }

        if (!empty($filters['date_to'])) {
            $collection->addAttributeToFilter('created_at', ['lteq' => $filters['date_to']]);
        }

        if (!empty($filters['store_id'])) {
            $collection->addAttributeToFilter('store_id', ['in' => (array) $filters['store_id']]);
        }

        if (!empty($filters['id_from'])) {
            $collection->addAttributeToFilter('entity_id', ['gteq' => $filters['id_from']]);
        }

        if (!empty($filters['id_to'])) {
            $collection->addAttributeToFilter('entity_id', ['lteq' => $filters['id_to']]);
        }

        if (!empty($filters['status'])) {
            $collection->addAttributeToFilter('status', ['in' => (array) $filters['status']]);
        }

        $collection->setOrder('entity_id', 'ASC');

        if (!empty($filters['limit'])) {
            $collection->setPageSize($filters['limit']);
        }

        foreach ($collection as $order) {
            yield $this->_exportOrder($order);
        }
    }

    /**
     * Export single order to array
     */
    protected function _exportOrder(Mage_Sales_Model_Order $order): array
    {
        $data = $order->getData();

        // Add items
        $data['items'] = [];
        foreach ($order->getAllItems() as $item) {
            $data['items'][] = $item->getData();
        }

        // Add addresses
        if ($order->getBillingAddress()) {
            $billingData = $order->getBillingAddress()->getData();
            foreach ($billingData as $key => $value) {
                $data['billing_' . $key] = $value;
            }
        }

        if ($order->getShippingAddress()) {
            $shippingData = $order->getShippingAddress()->getData();
            foreach ($shippingData as $key => $value) {
                $data['shipping_' . $key] = $value;
            }
        }

        return $data;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function validate(array $data): array
    {
        $errors = parent::validate($data);

        // Validate increment_id format (should be alphanumeric with optional dashes)
        if (isset($data['increment_id']) && !preg_match('/^[A-Za-z0-9\-_]+$/', $data['increment_id'])) {
            $errors[] = "Invalid increment_id format: {$data['increment_id']}";
        }

        // Validate grand_total is positive
        if (isset($data['grand_total']) && (float) $data['grand_total'] < 0) {
            $errors[] = "Grand total cannot be negative: {$data['grand_total']}";
        }

        // Validate customer email format
        if (!empty($data['customer_email']) && !filter_var($data['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid customer email format: {$data['customer_email']}";
        }

        // Validate billing address (required for orders, but relaxed for virtual orders)
        // Virtual orders (gift cards, digital products) may only have country from PayPal
        $isVirtual = !empty($data['is_virtual']) && $data['is_virtual'] == '1';
        if (!$this->_hasBillingAddress($data, $isVirtual)) {
            if ($isVirtual) {
                $errors[] = 'Missing billing address (billing_country_id required for virtual orders)';
            } else {
                $errors[] = 'Missing billing address (billing_street, billing_city, billing_country_id required)';
            }
        }

        // Validate items (warn if missing, error if malformed)
        $items = $this->_parseItems($data);
        if (empty($items)) {
            // This is a warning, not an error - some orders may have no items (e.g., refund-only)
            $this->_log(
                "Order {$data['increment_id']} has no items - this may be intentional",
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
        } else {
            foreach ($items as $i => $item) {
                if (empty($item['sku']) && empty($item['name'])) {
                    $errors[] = "Item #{$i} must have either sku or name";
                }
                if (isset($item['qty_ordered']) && (float) $item['qty_ordered'] <= 0) {
                    $errors[] = "Item #{$i} has invalid qty_ordered: {$item['qty_ordered']}";
                }
            }
        }

        // Validate payment method exists (warning only)
        $paymentMethod = $data['payment_method'] ?? 'checkmo';
        if (!$this->_isValidPaymentMethod($paymentMethod)) {
            $this->_log(
                "Order {$data['increment_id']} has unrecognized payment method '{$paymentMethod}' - will use 'checkmo'",
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
        }

        return $errors;
    }

    /**
     * Check if data contains billing address
     *
     * @param array $data Order data
     * @param bool $isVirtual If true, only country is required (for gift cards, digital products)
     */
    protected function _hasBillingAddress(array $data, bool $isVirtual = false): bool
    {
        // Virtual orders (gift cards, digital products) only need country
        // PayPal Express often doesn't provide full billing address for these
        if ($isVirtual) {
            return !empty($data['billing_country_id']);
        }

        // Physical orders need street, city, and country
        $required = ['billing_street', 'billing_city', 'billing_country_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Cache for payment method validation results
     */
    protected static array $_validPaymentMethodsCache = [];

    /**
     * Check if payment method is valid/available
     *
     * Tries to instantiate the payment method to verify it exists.
     * Results are cached for performance during bulk imports.
     */
    /**
     * Resolved product-ID cache for order item import, keyed by "sourceId|sku"
     */
    protected static array $_skuProductIdCache = [];

    /**
     * Resolve the local product_id for an order item.
     *
     * The source system's product_id does not match the target catalog, so it has
     * to be translated. `datasync_source_id` records exactly that translation and
     * is the only key that identifies a product unambiguously, so it is tried
     * first.
     *
     * SKU is a fallback, not the primary key, because it does not identify an
     * order item's product. On a configurable order the PARENT row stores the
     * CHILD's sku while pointing at the parent product:
     *
     *   parent_item_id NULL   product_type configurable   product_id 30347   sku 232026-4 3/8
     *   parent_item_id 566539 product_type simple         product_id 30355   sku 232026-4 3/8
     *
     * Resolving that by sku lands both rows on the child, so the configurable
     * parent -- the product customers actually search and browse -- is credited
     * with none of its own sales. Ordered-quantity ranking, bestseller reports,
     * recommendations and cross-sells all read these rows.
     *
     * Returns null when neither key resolves. Returning the unmapped source id
     * would point the row at whichever local product happens to occupy that id.
     */
    protected function _resolveLocalProductId(string $sku, ?int $sourceProductId): ?int
    {
        $cacheKey = ($sourceProductId ?? '-') . '|' . $sku;
        if (array_key_exists($cacheKey, self::$_skuProductIdCache)) {
            return self::$_skuProductIdCache[$cacheKey];
        }

        $localId = $sourceProductId === null ? null : $this->_findProductBySourceId($sourceProductId);

        if ($localId === null && $sku !== '') {
            $resource = Mage::getSingleton('core/resource');
            $found = $resource->getConnection('core_read')->fetchOne(
                "SELECT entity_id FROM {$resource->getTableName('catalog/product')} WHERE sku = ?",
                [$sku],
            );
            $localId = $found ? (int) $found : null;
        }

        if ($localId === null) {
            Mage::log(
                sprintf(
                    'DataSync: order item product unresolved (source product_id %s, sku %s) - product_id left null',
                    $sourceProductId ?? 'null',
                    $sku === '' ? '(none)' : $sku,
                ),
                Mage::LOG_NOTICE,
                'datasync.log',
            );
        }

        self::$_skuProductIdCache[$cacheKey] = $localId;
        return $localId;
    }

    /**
     * Local entity_id of the product imported from $sourceProductId, or null.
     */
    protected function _findProductBySourceId(int $sourceProductId): ?int
    {
        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');

        $entityTypeId = (int) Mage::getSingleton('eav/config')
            ->getEntityType(Mage_Catalog_Model_Product::ENTITY)
            ->getId();

        $attributeId = (int) $read->fetchOne(
            "SELECT attribute_id FROM {$resource->getTableName('eav/attribute')}
             WHERE attribute_code = ? AND entity_type_id = ?",
            ['datasync_source_id', $entityTypeId],
        );
        if ($attributeId === 0) {
            return null;
        }

        $ids = $read->fetchCol(
            "SELECT entity_id FROM {$resource->getTableName('catalog/product')}_int
             WHERE attribute_id = ? AND store_id = 0 AND value = ?",
            [$attributeId, $sourceProductId],
        );

        // Two products claiming the same source id makes the choice arbitrary.
        // Fall through to sku rather than pick one.
        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    protected function _isValidPaymentMethod(string $method): bool
    {
        if (isset(self::$_validPaymentMethodsCache[$method])) {
            return self::$_validPaymentMethodsCache[$method];
        }

        try {
            $methodInstance = Mage::helper('payment')->getMethodInstance($method);
            // Check for both null and false (getMethodInstance can return false)
            $isValid = $methodInstance !== null && $methodInstance !== false && is_object($methodInstance);
        } catch (Exception $e) {
            $isValid = false;
        }

        self::$_validPaymentMethodsCache[$method] = $isValid;
        return $isValid;
    }

    /**
     * Import status history comments
     *
     * @param array $data Order data containing 'status_history' array
     */
    protected function _importStatusHistory(Mage_Sales_Model_Order $order, array $data): void
    {
        $historyItems = $data['status_history'] ?? [];
        if (empty($historyItems)) {
            return;
        }

        foreach ($historyItems as $historyData) {
            /** @var Mage_Sales_Model_Order_Status_History $history */
            $history = Mage::getModel('sales/order_status_history');

            $history->setParentId($order->getId());
            $history->setStatus($historyData['status'] ?? $order->getStatus());
            $history->setComment($historyData['comment'] ?? null);
            $history->setIsCustomerNotified($historyData['is_customer_notified'] ?? 0);
            $history->setIsVisibleOnFront($historyData['is_visible_on_front'] ?? 0);
            $history->setEntityName($historyData['entity_name'] ?? 'order');

            // Preserve original created_at timestamp
            if (!empty($historyData['created_at'])) {
                $history->setCreatedAt($this->_parseDate($historyData['created_at']));
            } else {
                $history->setCreatedAt(Mage_Core_Model_Locale::nowUtc());
            }

            // Store source entity_id for tracking
            if (!empty($historyData['entity_id'])) {
                $history->setData('datasync_source_id', (int) $historyData['entity_id']);
            }

            try {
                $history->save();
            } catch (Exception $e) {
                $this->_log(
                    "Failed to import status history for order {$order->getIncrementId()}: " . $e->getMessage(),
                    Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
                );
            }
        }

        $this->_log(
            'Imported ' . count($historyItems) . " status history items for order {$order->getIncrementId()}",
            Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
        );
    }

    /**
     * Import invoices for an order
     *
     * @param array $data Order data containing 'invoices' array
     */
    protected function _importInvoices(Mage_Sales_Model_Order $order, array $data): void
    {
        $invoices = $data['invoices'] ?? [];
        if (empty($invoices)) {
            return;
        }

        foreach ($invoices as $invoiceData) {
            $this->_importSingleInvoice($order, $invoiceData);
        }

        $this->_log(
            'Imported ' . count($invoices) . " invoices for order {$order->getIncrementId()}",
            Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
        );
    }

    /**
     * Import a single invoice
     */
    protected function _importSingleInvoice(Mage_Sales_Model_Order $order, array $invoiceData): void
    {
        try {
            /** @var Mage_Sales_Model_Order_Invoice $invoice */
            $invoice = Mage::getModel('sales/order_invoice');

            $invoice->setOrderId($order->getId());
            $invoice->setStoreId($order->getStoreId());

            // Preserve original increment_id if provided
            if (!empty($invoiceData['increment_id'])) {
                $invoice->setIncrementId($invoiceData['increment_id']);
            }

            // State - use setData to bypass validation
            $invoice->setData('state', $invoiceData['state'] ?? Mage_Sales_Model_Order_Invoice::STATE_PAID);

            // Totals
            $invoice->setGrandTotal((float) ($invoiceData['grand_total'] ?? $order->getGrandTotal()));
            $invoice->setBaseGrandTotal((float) ($invoiceData['base_grand_total'] ?? $invoice->getGrandTotal()));
            $invoice->setSubtotal((float) ($invoiceData['subtotal'] ?? $order->getSubtotal()));
            $invoice->setBaseSubtotal((float) ($invoiceData['base_subtotal'] ?? $invoice->getSubtotal()));
            $invoice->setTaxAmount((float) ($invoiceData['tax_amount'] ?? $order->getTaxAmount()));
            $invoice->setBaseTaxAmount((float) ($invoiceData['base_tax_amount'] ?? $invoice->getTaxAmount()));
            $invoice->setShippingAmount((float) ($invoiceData['shipping_amount'] ?? $order->getShippingAmount()));
            $invoice->setBaseShippingAmount((float) ($invoiceData['base_shipping_amount'] ?? $invoice->getShippingAmount()));
            $invoice->setDiscountAmount((float) ($invoiceData['discount_amount'] ?? $order->getDiscountAmount()));
            $invoice->setBaseDiscountAmount((float) ($invoiceData['base_discount_amount'] ?? $invoice->getDiscountAmount()));

            // Shipping tax
            $invoice->setShippingTaxAmount((float) ($invoiceData['shipping_tax_amount'] ?? 0));
            $invoice->setBaseShippingTaxAmount((float) ($invoiceData['base_shipping_tax_amount'] ?? 0));

            // Currency
            $invoice->setBaseCurrencyCode($order->getBaseCurrencyCode());
            $invoice->setStoreCurrencyCode($order->getStoreCurrencyCode());
            $invoice->setOrderCurrencyCode($order->getOrderCurrencyCode());
            $invoice->setGlobalCurrencyCode($order->getGlobalCurrencyCode());
            $invoice->setBaseToGlobalRate($order->getBaseToGlobalRate());
            $invoice->setBaseToOrderRate($order->getBaseToOrderRate());
            $invoice->setStoreToBaseRate($order->getStoreToBaseRate());
            $invoice->setStoreToOrderRate($order->getStoreToOrderRate());

            // Billing address
            if ($order->getBillingAddressId()) {
                $invoice->setBillingAddressId($order->getBillingAddressId());
            }
            if ($order->getShippingAddressId()) {
                $invoice->setShippingAddressId($order->getShippingAddressId());
            }

            // Dates
            if (!empty($invoiceData['created_at'])) {
                $invoice->setCreatedAt($this->_parseDate($invoiceData['created_at']));
            }
            if (!empty($invoiceData['updated_at'])) {
                $invoice->setUpdatedAt($this->_parseDate($invoiceData['updated_at']));
            }

            // CRITICAL: Prevent invoice email
            $invoice->setEmailSent(1);
            $invoice->setSendEmail(false);

            // Transaction ID
            if (!empty($invoiceData['transaction_id'])) {
                $invoice->setTransactionId($invoiceData['transaction_id']);
            }

            // DataSync tracking
            if (!empty($invoiceData['entity_id'])) {
                $invoice->setData('datasync_source_id', (int) $invoiceData['entity_id']);
            }

            // Save invoice first to get entity_id
            $invoice->save();

            // Import invoice items
            $this->_importInvoiceItems($invoice, $order, $invoiceData);

            // Save again with items
            $invoice->save();

            // Import invoice comments if present
            if (!empty($invoiceData['comments'])) {
                $this->_importInvoiceComments($invoice, $invoiceData['comments']);
            }

        } catch (Exception $e) {
            $this->_log(
                "Failed to import invoice for order {$order->getIncrementId()}: " . $e->getMessage(),
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
        }
    }

    /**
     * Import invoice items
     */
    protected function _importInvoiceItems(
        Mage_Sales_Model_Order_Invoice $invoice,
        Mage_Sales_Model_Order $order,
        array $invoiceData,
    ): void {
        $invoiceItems = $invoiceData['items'] ?? [];

        // If no specific invoice items, create from order items
        if (empty($invoiceItems)) {
            foreach ($order->getAllItems() as $orderItem) {
                /** @var Mage_Sales_Model_Order_Invoice_Item $item */
                $item = Mage::getModel('sales/order_invoice_item');
                $item->setInvoice($invoice);
                $item->setOrderItem($orderItem);
                $item->setOrderItemId($orderItem->getId());
                $item->setProductId($orderItem->getProductId());
                $item->setSku($orderItem->getSku());
                $item->setName($orderItem->getName());
                $item->setQty($orderItem->getQtyOrdered());
                $item->setPrice($orderItem->getPrice());
                $item->setBasePrice($orderItem->getBasePrice());
                $item->setRowTotal($orderItem->getRowTotal());
                $item->setBaseRowTotal($orderItem->getBaseRowTotal());
                $item->setTaxAmount($orderItem->getTaxAmount());
                $item->setBaseTaxAmount($orderItem->getBaseTaxAmount());
                $item->setDiscountAmount($orderItem->getDiscountAmount());
                $item->setBaseDiscountAmount($orderItem->getBaseDiscountAmount());

                $invoice->addItem($item);
            }
        } else {
            // Use specific invoice items data
            foreach ($invoiceItems as $itemData) {
                // Find matching order item
                $orderItem = null;
                foreach ($order->getAllItems() as $oi) {
                    if ($oi->getSku() === ($itemData['sku'] ?? '')) {
                        $orderItem = $oi;
                        break;
                    }
                }

                /** @var Mage_Sales_Model_Order_Invoice_Item $item */
                $item = Mage::getModel('sales/order_invoice_item');
                $item->setInvoice($invoice);

                if ($orderItem) {
                    $item->setOrderItem($orderItem);
                    $item->setOrderItemId($orderItem->getId());
                }

                $invoiceSku = $itemData['sku'] ?? '';
                // The order item's product_id is already translated to this catalog.
                // Never feed it back in as a source id.
                $item->setProductId($orderItem
                    ? (int) $orderItem->getProductId()
                    : $this->_resolveLocalProductId($invoiceSku, $itemData['product_id'] ?? null));
                $item->setSku($invoiceSku);
                $item->setName($itemData['name'] ?? ($orderItem ? $orderItem->getName() : ''));
                $item->setQty((float) ($itemData['qty'] ?? 1));
                $item->setPrice((float) ($itemData['price'] ?? 0));
                $item->setBasePrice((float) ($itemData['base_price'] ?? $itemData['price'] ?? 0));
                $item->setRowTotal((float) ($itemData['row_total'] ?? 0));
                $item->setBaseRowTotal((float) ($itemData['base_row_total'] ?? $itemData['row_total'] ?? 0));
                $item->setTaxAmount((float) ($itemData['tax_amount'] ?? 0));
                $item->setBaseTaxAmount((float) ($itemData['base_tax_amount'] ?? 0));
                $item->setDiscountAmount((float) ($itemData['discount_amount'] ?? 0));
                $item->setBaseDiscountAmount((float) ($itemData['base_discount_amount'] ?? 0));

                $invoice->addItem($item);
            }
        }
    }

    /**
     * Import invoice comments
     */
    protected function _importInvoiceComments(Mage_Sales_Model_Order_Invoice $invoice, array $comments): void
    {
        foreach ($comments as $commentData) {
            /** @var Mage_Sales_Model_Order_Invoice_Comment $comment */
            $comment = Mage::getModel('sales/order_invoice_comment');
            $comment->setParentId($invoice->getId());
            $comment->setComment($commentData['comment'] ?? '');
            $comment->setIsCustomerNotified($commentData['is_customer_notified'] ?? 0);
            $comment->setIsVisibleOnFront($commentData['is_visible_on_front'] ?? 0);

            if (!empty($commentData['created_at'])) {
                $comment->setCreatedAt($this->_parseDate($commentData['created_at']));
            }

            try {
                $comment->save();
            } catch (Exception $e) {
                $this->_log(
                    'Failed to import invoice comment: ' . $e->getMessage(),
                    Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
                );
            }
        }
    }

    /**
     * Import shipments for an order
     *
     * @param array $data Order data containing 'shipments' array
     */
    protected function _importShipments(Mage_Sales_Model_Order $order, array $data): void
    {
        $shipments = $data['shipments'] ?? [];
        if (empty($shipments)) {
            return;
        }

        foreach ($shipments as $shipmentData) {
            $this->_importSingleShipment($order, $shipmentData);
        }

        $this->_log(
            'Imported ' . count($shipments) . " shipments for order {$order->getIncrementId()}",
            Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
        );
    }

    /**
     * Import a single shipment
     */
    protected function _importSingleShipment(Mage_Sales_Model_Order $order, array $shipmentData): void
    {
        try {
            /** @var Mage_Sales_Model_Order_Shipment $shipment */
            $shipment = Mage::getModel('sales/order_shipment');

            $shipment->setOrderId($order->getId());
            $shipment->setStoreId($order->getStoreId());

            // Preserve original increment_id if provided
            if (!empty($shipmentData['increment_id'])) {
                $shipment->setIncrementId($shipmentData['increment_id']);
            }

            // Totals
            $shipment->setTotalQty((float) ($shipmentData['total_qty'] ?? 0));
            $shipment->setTotalWeight((float) ($shipmentData['total_weight'] ?? 0));

            // Addresses
            if ($order->getBillingAddressId()) {
                $shipment->setBillingAddressId($order->getBillingAddressId());
            }
            if ($order->getShippingAddressId()) {
                $shipment->setShippingAddressId($order->getShippingAddressId());
            }

            // Customer info
            $shipment->setCustomerId($order->getCustomerId());
            $shipment->setCustomerEmail($order->getCustomerEmail());

            // Dates
            if (!empty($shipmentData['created_at'])) {
                $shipment->setCreatedAt($this->_parseDate($shipmentData['created_at']));
            }
            if (!empty($shipmentData['updated_at'])) {
                $shipment->setUpdatedAt($this->_parseDate($shipmentData['updated_at']));
            }

            // CRITICAL: Prevent shipment email
            $shipment->setEmailSent(1);
            $shipment->setSendEmail(false);

            // DataSync tracking
            if (!empty($shipmentData['entity_id'])) {
                $shipment->setData('datasync_source_id', (int) $shipmentData['entity_id']);
            }

            // Save shipment first to get entity_id
            $shipment->save();

            // Import shipment items
            $this->_importShipmentItems($shipment, $order, $shipmentData);

            // Import tracking numbers
            $this->_importShipmentTracks($shipment, $shipmentData);

            // Save again with items and tracks
            $shipment->save();

        } catch (Exception $e) {
            $this->_log(
                "Failed to import shipment for order {$order->getIncrementId()}: " . $e->getMessage(),
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
        }
    }

    /**
     * Import shipment items
     */
    protected function _importShipmentItems(
        Mage_Sales_Model_Order_Shipment $shipment,
        Mage_Sales_Model_Order $order,
        array $shipmentData,
    ): void {
        $shipmentItems = $shipmentData['items'] ?? [];

        // If no specific shipment items, create from order items
        if (empty($shipmentItems)) {
            foreach ($order->getAllItems() as $orderItem) {
                if ($orderItem->getQtyShipped() > 0) {
                    /** @var Mage_Sales_Model_Order_Shipment_Item $item */
                    $item = Mage::getModel('sales/order_shipment_item');
                    $item->setShipment($shipment);
                    $item->setOrderItem($orderItem);
                    $item->setOrderItemId($orderItem->getId());
                    $item->setProductId($orderItem->getProductId());
                    $item->setSku($orderItem->getSku());
                    $item->setName($orderItem->getName());
                    $item->setQty($orderItem->getQtyShipped());
                    $item->setPrice($orderItem->getPrice());
                    $item->setWeight($orderItem->getWeight());
                    $shipment->addItem($item);
                }
            }
        } else {
            // Use specific shipment items data
            foreach ($shipmentItems as $itemData) {
                // Find matching order item
                $orderItem = null;
                foreach ($order->getAllItems() as $oi) {
                    if ($oi->getSku() === ($itemData['sku'] ?? '')) {
                        $orderItem = $oi;
                        break;
                    }
                }

                /** @var Mage_Sales_Model_Order_Shipment_Item $item */
                $item = Mage::getModel('sales/order_shipment_item');
                $item->setShipment($shipment);

                if ($orderItem) {
                    $item->setOrderItem($orderItem);
                    $item->setOrderItemId($orderItem->getId());
                }

                $shipSku = $itemData['sku'] ?? '';
                // As above: prefer the order item's already-translated product_id.
                $item->setProductId($orderItem
                    ? (int) $orderItem->getProductId()
                    : $this->_resolveLocalProductId($shipSku, $itemData['product_id'] ?? null));
                $item->setSku($shipSku);
                $item->setName($itemData['name'] ?? ($orderItem ? $orderItem->getName() : ''));
                $item->setQty((float) ($itemData['qty'] ?? 1));
                $item->setPrice((float) ($itemData['price'] ?? 0));
                $item->setWeight((float) ($itemData['weight'] ?? 0));
                $item->setRowTotal((float) ($itemData['row_total'] ?? 0));

                $shipment->addItem($item);
            }
        }
    }

    /**
     * Import shipment tracking numbers
     */
    protected function _importShipmentTracks(Mage_Sales_Model_Order_Shipment $shipment, array $shipmentData): void
    {
        $tracks = $shipmentData['tracks'] ?? [];
        if (empty($tracks)) {
            return;
        }

        foreach ($tracks as $trackData) {
            /** @var Mage_Sales_Model_Order_Shipment_Track $track */
            $track = Mage::getModel('sales/order_shipment_track');
            $track->setShipment($shipment);
            $track->setOrderId($shipment->getOrderId());
            $track->setCarrierCode($trackData['carrier_code'] ?? 'custom');
            $track->setTitle($trackData['title'] ?? $trackData['carrier_code'] ?? 'Custom');
            $track->setNumber($trackData['track_number'] ?? $trackData['number'] ?? '');

            if (!empty($trackData['created_at'])) {
                $track->setCreatedAt($this->_parseDate($trackData['created_at']));
            }

            $shipment->addTrack($track);
        }
    }
}
