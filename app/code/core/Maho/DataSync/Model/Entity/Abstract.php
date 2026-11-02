<?php

/**
 * Maho DataSync Abstract Entity Handler
 *
 * Base class for entity handlers with common functionality.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
abstract class Maho_DataSync_Model_Entity_Abstract implements Maho_DataSync_Model_Entity_Interface
{
    /**
     * Default required fields (override in subclasses)
     */
    protected array $_requiredFields = [];

    /**
     * Default foreign key mappings (override in subclasses)
     */
    protected array $_foreignKeyFields = [];

    /**
     * External reference field (override in subclasses)
     */
    protected ?string $_externalRefField = null;

    /**
     * @inheritDoc
     */
    #[\Override]
    abstract public function getEntityType(): string;

    /**
     * @inheritDoc
     */
    #[\Override]
    abstract public function getLabel(): string;

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getRequiredFields(): array
    {
        return $this->_requiredFields;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getForeignKeyFields(): array
    {
        return $this->_foreignKeyFields;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getExternalRefField(): ?string
    {
        return $this->_externalRefField;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function validate(array $data): array
    {
        $errors = [];

        foreach ($this->_requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                $errors[] = "Missing required field: {$field}";
            }
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function findExisting(array $data): ?int
    {
        // Default: no duplicate detection
        // Subclasses should override to implement entity-specific logic
        return null;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function export(array $filters = []): iterable
    {
        // Default: export not implemented
        throw new Maho_DataSync_Exception(
            "Export not implemented for entity type: {$this->getEntityType()}",
            Maho_DataSync_Exception::CODE_GENERAL,
        );
    }

    /**
     * Get helper instance
     */
    protected function _getHelper(): Maho_DataSync_Helper_Data
    {
        return Mage::helper('datasync');
    }

    /**
     * Log message
     */
    protected function _log(string $message, int $level = Maho_DataSync_Helper_Data::LOG_LEVEL_INFO): void
    {
        $this->_getHelper()->log($message, $level);
    }

    /**
     * Map source data to Maho entity fields
     *
     * Override in subclasses to customize field mapping.
     *
     * @param array $data Source data
     * @return array Mapped data for Maho entity
     */
    protected function _mapFields(array $data): array
    {
        // Remove internal fields
        $mapped = array_filter($data, function ($key) {
            return !str_starts_with($key, '_'); // Remove fields starting with _
        }, ARRAY_FILTER_USE_KEY);

        return $mapped;
    }

    /**
     * Get store ID from data or default
     *
     * @param array $data Source data
     * @param int $default Default store ID
     * @return int Store ID
     */
    protected function _getStoreId(array $data, int $default = 0): int
    {
        if (isset($data['target_store_id'])) {
            return (int) $data['target_store_id'];
        }

        if (isset($data['store_id'])) {
            return (int) $data['store_id'];
        }

        return $default;
    }

    /**
     * Get website ID from data or default
     *
     * @param array $data Source data
     * @param int $default Default website ID
     * @return int Website ID
     */
    protected function _getWebsiteId(array $data, int $default = 1): int
    {
        if (isset($data['target_website_id'])) {
            return (int) $data['target_website_id'];
        }

        if (isset($data['website_id'])) {
            return (int) $data['website_id'];
        }

        return $default;
    }

    /**
     * Clean and normalize string value
     *
     * @param mixed $value Value to clean
     * @return string Cleaned string
     */
    protected function _cleanString($value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * Parse date value to MySQL format
     *
     * @param mixed $value Date value (string, timestamp, etc.)
     * @return string|null MySQL datetime string or null
     */
    protected function _parseDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int) $value);
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Parse boolean value
     *
     * @param mixed $value Boolean-like value
     */
    protected function _parseBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function finishSync(Maho_DataSync_Model_Registry $registry): void
    {
        // Default: no post-processing needed
        // Subclasses override for entity-specific batch finalization
    }

    /**
     * Roll the eav_entity_store counter forward past a freshly-imported
     * increment_id. Without this, importing prod orders that carry their
     * own increment_id leaves the local counter stale — and the next
     * locally-placed order / quote.reserveOrderId() collides with an
     * already-imported one (duplicate increment_id, PayPal DUPLICATE_INVOICE_ID,
     * etc).
     *
     * Conditional UPDATE: only bumps when our imported value is higher than
     * the current counter, so subsequent imports of older orders are no-ops
     * and locally-placed orders that have already advanced past the import
     * stay untouched.
     */
    protected function _advanceIncrementCounter(string $entityTypeCode, int $storeId, string $incrementId): void
    {
        if ($incrementId === '') {
            return;
        }
        $resource = Mage::getSingleton('core/resource');
        $write = $resource->getConnection('core_write');
        $entityType = Mage::getSingleton('eav/config')->getEntityType($entityTypeCode);
        $entityTypeId = (int) $entityType->getId();
        if ($entityTypeId === 0) {
            return;
        }

        // Strip non-digit prefix (e.g. store prefix '1' or '9') so numeric
        // comparison works regardless of what eav_entity_store currently has.
        $numericPart = preg_replace('/\D/', '', $incrementId);
        if ($numericPart === '' || $numericPart === null) {
            return;
        }

        $table = $resource->getTableName('eav/entity_store');
        $write->query(
            "UPDATE {$table}
             SET increment_last_id = ?
             WHERE entity_type_id = ? AND store_id = ?
               AND CAST(REGEXP_REPLACE(increment_last_id, '[^0-9]', '') AS UNSIGNED) < CAST(? AS UNSIGNED)",
            [$incrementId, $entityTypeId, $storeId, $numericPart],
        );
    }
}
