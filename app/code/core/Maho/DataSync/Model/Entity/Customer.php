<?php

/**
 * Maho DataSync Customer Entity Handler
 *
 * Handles import/export of customer entities.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Model_Entity_Customer extends Maho_DataSync_Model_Entity_Abstract
{
    protected array $_requiredFields = ['email']; // firstname/lastname filled with fallbacks in import() — single-name / social-login sources (PayPal etc.) carry only one name
    protected array $_foreignKeyFields = []; // Customers have no FK dependencies
    protected ?string $_externalRefField = 'email';

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getEntityType(): string
    {
        return 'customer';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'Customers';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function findExisting(array $data): ?int
    {
        if (empty($data['email'])) {
            return null;
        }

        $customer = Mage::getModel('customer/customer');

        // Set website for lookup
        $websiteId = $this->_getWebsiteId($data);
        $customer->setWebsiteId($websiteId);
        $customer->loadByEmail(trim((string) $data['email']));

        return $customer->getId() ? (int) $customer->getId() : null;
    }

    /**
     * Fill missing first/last name so single-name sources (social-login / PayPal, which
     * carry only one name) pass Maho's customer validation without inventing data:
     * split a two-word firstname on the last space, else use "." for the surname; if the
     * firstname itself is empty, fall back to the email local-part.
     */
    private function _fillMissingNames(array $data): array
    {
        $first = trim((string) ($data['firstname'] ?? ''));
        $last  = trim((string) ($data['lastname'] ?? ''));
        if ($last === '') {
            if ($first !== '' && str_contains($first, ' ')) {
                $pos   = strrpos($first, ' ');
                $last  = trim(substr($first, $pos + 1));
                $first = trim(substr($first, 0, $pos));
            }
            if ($last === '') {
                $last = '.';
            }
        }
        if ($first === '') {
            $email = (string) ($data['email'] ?? '');
            $at    = strpos($email, '@');
            $first = ($email !== '' && $at !== false) ? substr($email, 0, $at) : '.';
        }
        $data['firstname'] = $first;
        $data['lastname']  = $last;
        return $data;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function import(array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $data = $this->_fillMissingNames($data);

        // Determine if this is a create, update, or merge
        // The engine sets _existing_id when a duplicate is found and mode is update/merge
        $existingId = $data['_existing_id'] ?? null;
        $action = $data['_action'] ?? 'create';
        $isMerge = ($action === 'merge');

        /** @var Mage_Customer_Model_Customer $customer */
        $customer = Mage::getModel('customer/customer');

        if ($existingId) {
            $customer->load($existingId);
            $this->_log("Updating existing customer #{$existingId}: {$data['email']} (mode: {$action})");
        } else {
            $this->_log("Creating new customer: {$data['email']}");
        }

        // Helper function for merge mode - only set if source value is not empty
        $setValue = function ($field, $value, $setter = null) use ($customer, $isMerge, $existingId) {
            // For new records, always set
            if (!$existingId) {
                if ($setter) {
                    $customer->$setter($value);
                } else {
                    $customer->setData($field, $value);
                }
                return;
            }

            // For merge mode, only set if value is not empty
            if ($isMerge && ($value === '' || $value === null)) {
                return; // Keep existing value
            }

            // For update mode, always overwrite
            if ($setter) {
                $customer->$setter($value);
            } else {
                $customer->setData($field, $value);
            }
        };

        // Set basic fields (always required)
        $setValue('email', $this->_cleanString($data['email']), 'setEmail');
        $setValue('firstname', $this->_cleanString($data['firstname']), 'setFirstname');
        $setValue('lastname', $this->_cleanString($data['lastname']), 'setLastname');

        // Optional fields
        if (isset($data['middlename'])) {
            $setValue('middlename', $this->_cleanString($data['middlename']), 'setMiddlename');
        }

        if (isset($data['prefix'])) {
            $setValue('prefix', $this->_cleanString($data['prefix']), 'setPrefix');
        }

        if (isset($data['suffix'])) {
            $setValue('suffix', $this->_cleanString($data['suffix']), 'setSuffix');
        }

        if (isset($data['dob'])) {
            $setValue('dob', $this->_parseDate($data['dob']), 'setDob');
        }

        if (isset($data['gender'])) {
            $setValue('gender', (int) $data['gender'], 'setGender');
        }

        if (isset($data['taxvat'])) {
            $setValue('taxvat', $this->_cleanString($data['taxvat']), 'setTaxvat');
        }

        // Store assignment (only for new customers, don't change existing)
        if (!$existingId) {
            $storeId = $this->_getStoreId($data, Mage::app()->getDefaultStoreView()->getId());
            $websiteId = $this->_getWebsiteId($data, Mage::app()->getDefaultStoreView()->getWebsiteId());
            $customer->setStoreId($storeId);
            $customer->setWebsiteId($websiteId);
        }

        // Customer group
        if (isset($data['group_id'])) {
            $setValue('group_id', (int) $data['group_id'], 'setGroupId');
        }

        // Password.
        //
        // Hashes from a Magento/OpenMage-lineage source are portable: this platform's
        // Mage_Core_Model_Encryption::validateHash() tries every historical hash version
        // (bcrypt -> SHA512 -> SHA256 -> MD5) before failing, so a hash written by the old
        // system still authenticates and is upgraded in place on the customer's next login.
        // Carrying it means migrated customers keep the password they already know instead
        // of being forced through a reset.
        //
        // Sources without a compatible hash (CSV, Shopify, WooCommerce, ...) still fall back
        // to the random-password-plus-forced-reset behaviour.
        //
        // setPasswordHash() stores the value verbatim. setPassword() would hash it a second
        // time, which silently locks the customer out of their own account.
        $sourceHash = trim((string) ($data['password_hash'] ?? ''));

        if ($sourceHash !== '' && $this->_isPortablePasswordHash($sourceHash)) {
            if ($customer->getPasswordHash() !== $sourceHash) {
                $customer->setPasswordHash($sourceHash);

                // Carried so "sessions issued before the password changed" stays meaningful;
                // without it a customer whose password changed at source would keep older
                // sessions alive here.
                if (!empty($data['password_created_at'])) {
                    $customer->setPasswordCreatedAt((int) $data['password_created_at']);
                }

                $this->_log(
                    "Synced password hash for customer: {$data['email']}",
                    Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
                );
            }
        } elseif (!$existingId) {
            $randomPassword = $this->_getHelper()->generateRandomPassword(16);
            $customer->setPassword($randomPassword);
            $customer->setData('force_password_reset', 1);

            $this->_log(
                "Generated random password for customer (requires reset): {$data['email']}",
                Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
            );
        }

        // Set created_at if provided (for historical data, only for new customers)
        if (isset($data['created_at']) && !$existingId) {
            $customer->setCreatedAt($this->_parseDate($data['created_at']));
        }

        // Import custom customer attributes (like netsuite_id, etc.)
        $this->_importCustomAttributes($customer, $data, $setValue);

        // Set DataSync tracking attributes
        $sourceSystem = $data['_source_system'] ?? 'import';
        $sourceId = $data['entity_id'] ?? null;

        $customer->setData('datasync_source_system', $sourceSystem);
        if ($sourceId) {
            $customer->setData('datasync_source_id', (int) $sourceId);
        }
        $customer->setData('datasync_imported_at', Mage_Core_Model_Locale::nowUtc());

        // Save customer
        try {
            $customer->save();
        } catch (Exception $e) {
            throw Maho_DataSync_Exception::importFailed(
                'customer',
                $data['entity_id'] ?? 0,
                $sourceSystem,
                $e->getMessage(),
            );
        }

        // Import addresses if provided (supports both nested array and flat columns)
        $addresses = $this->_parseAddresses($data);
        if (!empty($addresses)) {
            $this->_importAddresses($customer, $addresses, $existingId ? true : false);
        }

        // Set external reference for registry
        $data['_external_ref'] = $data['email'];

        return (int) $customer->getId();
    }

    /**
     * Parse addresses from import data
     *
     * Supports two formats:
     * 1. Nested array: $data['addresses'] = [['street' => '...', 'city' => '...'], ...]
     * 2. Flat columns: $data['billing_street'], $data['shipping_street'], etc.
     *
     * Flat column format uses prefixes: billing_, shipping_, address1_, address2_, etc.
     * Also supports use_billing_as_shipping flag for single address that is both.
     */
    protected function _parseAddresses(array $data): array
    {
        // If already nested array format, return as-is
        if (isset($data['addresses']) && is_array($data['addresses'])) {
            return $data['addresses'];
        }

        $addresses = [];
        $addressFields = [
            'firstname', 'lastname', 'company', 'street', 'city',
            'region', 'region_id', 'postcode', 'country_id', 'telephone', 'fax',
        ];

        // Parse billing address from flat columns
        $billingAddress = $this->_extractAddressFromPrefix($data, 'billing_', $addressFields);
        if (!empty($billingAddress)) {
            $billingAddress['is_default_billing'] = true;

            // Check if billing should also be shipping
            $useBillingAsShipping = !empty($data['use_billing_as_shipping'])
                || !empty($data['billing_is_shipping'])
                || !empty($data['same_as_billing']);

            if ($useBillingAsShipping) {
                $billingAddress['is_default_shipping'] = true;
            }

            $addresses[] = $billingAddress;
        }

        // Parse shipping address (only if not using billing as shipping)
        $useBillingAsShipping = !empty($data['use_billing_as_shipping'])
            || !empty($data['billing_is_shipping'])
            || !empty($data['same_as_billing']);

        if (!$useBillingAsShipping) {
            $shippingAddress = $this->_extractAddressFromPrefix($data, 'shipping_', $addressFields);
            if (!empty($shippingAddress)) {
                $shippingAddress['is_default_shipping'] = true;
                $addresses[] = $shippingAddress;
            }
        }

        // Parse additional addresses (address1_, address2_, etc.)
        for ($i = 1; $i <= 10; $i++) {
            $additionalAddress = $this->_extractAddressFromPrefix($data, "address{$i}_", $addressFields);
            if (empty($additionalAddress)) {
                break; // Stop at first missing address
            }
            $addresses[] = $additionalAddress;
        }

        return $addresses;
    }

    /**
     * Extract address fields from data using a prefix
     *
     * @param array $data Source data
     * @param string $prefix Field prefix (e.g., 'billing_', 'shipping_')
     * @param array $fields List of address field names
     * @return array Address data (empty if no fields found)
     */
    protected function _extractAddressFromPrefix(array $data, string $prefix, array $fields): array
    {
        $address = [];
        $hasData = false;

        foreach ($fields as $field) {
            $key = $prefix . $field;
            if (isset($data[$key]) && $data[$key] !== '') {
                $address[$field] = $data[$key];
                $hasData = true;
            }
        }

        // Only return if we have at least street or city
        if ($hasData && (isset($address['street']) || isset($address['city']))) {
            return $address;
        }

        return [];
    }

    /**
     * Import customer addresses
     *
     * @param bool $isUpdate Whether this is an update (existing customer)
     */
    protected function _importAddresses(Mage_Customer_Model_Customer $customer, array $addresses, bool $isUpdate = false): void
    {
        // For updates, we can optionally delete existing addresses first
        // For now, we just add new addresses without removing existing ones
        // This prevents data loss on updates

        foreach ($addresses as $addressData) {
            /** @var Mage_Customer_Model_Address $address */
            $address = Mage::getModel('customer/address');

            // Try to find existing address by entity_id if updating
            if ($isUpdate && !empty($addressData['entity_id'])) {
                // Look for address by source ID in existing customer addresses
                // This is a simplistic approach - addresses don't have a good unique identifier
                // In practice, you might want to match by postcode + street or similar
            }

            $address->setCustomerId($customer->getId());
            $address->setFirstname($this->_cleanString($addressData['firstname'] ?? $customer->getFirstname()));
            $address->setLastname($this->_cleanString($addressData['lastname'] ?? $customer->getLastname()));

            if (isset($addressData['company'])) {
                $address->setCompany($this->_cleanString($addressData['company']));
            }

            if (isset($addressData['street'])) {
                $street = is_array($addressData['street'])
                    ? $addressData['street']
                    : explode("\n", $addressData['street']);
                $address->setStreet($street);
            }

            if (isset($addressData['city'])) {
                $address->setCity($this->_cleanString($addressData['city']));
            }

            if (isset($addressData['region']) || isset($addressData['region_id'])) {
                if (isset($addressData['region_id'])) {
                    $address->setRegionId((int) $addressData['region_id']);
                } else {
                    $address->setRegion($this->_cleanString($addressData['region']));
                }
            }

            if (isset($addressData['postcode'])) {
                $address->setPostcode($this->_cleanString($addressData['postcode']));
            }

            if (isset($addressData['country_id'])) {
                $address->setCountryId($this->_cleanString($addressData['country_id']));
            }

            if (isset($addressData['telephone'])) {
                $address->setTelephone($this->_cleanString($addressData['telephone']));
            }

            if (isset($addressData['fax'])) {
                $address->setFax($this->_cleanString($addressData['fax']));
            }

            try {
                $address->save();
                $city = $addressData['city'] ?? 'unknown';
                $this->_log(
                    "Imported address for customer #{$customer->getId()}: {$city}",
                    Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
                );

                // Set as default billing/shipping on the CUSTOMER after address is saved
                // This properly updates the customer's default_billing/default_shipping EAV attributes
                if (!empty($addressData['is_default_billing'])) {
                    $customer->setDefaultBilling($address->getId());
                    $this->_log(
                        "Set default billing address #{$address->getId()} for customer #{$customer->getId()}",
                        Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
                    );
                }

                if (!empty($addressData['is_default_shipping'])) {
                    $customer->setDefaultShipping($address->getId());
                    $this->_log(
                        "Set default shipping address #{$address->getId()} for customer #{$customer->getId()}",
                        Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
                    );
                }
            } catch (Exception $e) {
                $this->_log(
                    "Failed to import address for customer #{$customer->getId()}: {$e->getMessage()}",
                    Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
                );
            }
        }

        // Save customer again to persist default billing/shipping addresses
        try {
            $customer->save();
        } catch (Exception $e) {
            $this->_log(
                "Failed to save customer defaults #{$customer->getId()}: {$e->getMessage()}",
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
        }
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function export(array $filters = []): iterable
    {
        $collection = Mage::getModel('customer/customer')->getCollection()
            ->addAttributeToSelect('*');

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

        $collection->setOrder('entity_id', 'ASC');

        if (!empty($filters['limit'])) {
            $collection->setPageSize($filters['limit']);
        }

        foreach ($collection as $customer) {
            yield $customer->getData();
        }
    }

    /**
     * Import custom customer attributes
     *
     * Handles custom EAV attributes like netsuite_id that aren't
     * part of the standard customer model.
     *
     * @param array $data Source data
     * @param callable $setValue Helper function for merge mode
     */
    protected function _importCustomAttributes(Mage_Customer_Model_Customer $customer, array $data, callable $setValue): void
    {
        // List of custom attribute codes to import (if they exist in source data)
        $customAttributes = [
            'netsuite_id',
            'netsuite_customer_id',
            'loyalty_points',
            'company',
            'vat_number',
        ];

        foreach ($customAttributes as $attrCode) {
            if (isset($data[$attrCode]) && $data[$attrCode] !== '') {
                // Verify the attribute exists in target system
                $attribute = Mage::getModel('eav/entity_attribute')->loadByCode('customer', $attrCode);
                if ($attribute->getId()) {
                    $setValue($attrCode, $data[$attrCode]);
                    $this->_log(
                        "Set custom attribute {$attrCode}={$data[$attrCode]} for customer",
                        Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
                    );
                }
            }
        }
    }

    /**
     * Whether a source hash is in a shape Mage_Core_Model_Encryption can validate:
     * bcrypt, "<hex digest>:<salt>", or a bare hex digest.
     *
     * Anything else (a bcrypt variant this platform cannot read, a foreign platform's
     * format, or truncated data) is rejected rather than stored, because an unvalidatable
     * hash is indistinguishable from a wrong password at the login prompt.
     */
    protected function _isPortablePasswordHash(string $hash): bool
    {
        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$')) {
            return true;
        }

        $digest = explode(':', $hash, 2)[0];

        return in_array(strlen($digest), [32, 64, 128], true) && ctype_xdigit($digest);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function validate(array $data): array
    {
        $errors = parent::validate($data);

        // Validate email format
        if (isset($data['email']) && !filter_var(trim((string) $data['email']), FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format: {$data['email']}";
        }

        // Validate gender if provided
        if (isset($data['gender']) && !in_array((int) $data['gender'], [0, 1, 2])) {
            $errors[] = "Invalid gender value: {$data['gender']} (expected 0, 1, or 2)";
        }

        return $errors;
    }
}
