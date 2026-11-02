<?php

/**
 * Maho
 *
 * @package    Maho_DataSync
 * @copyright  Copyright (c) 2025-2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

/**
 * OpenMage/Magento 1 Database Adapter
 *
 * Direct database connection to OpenMage/Magento 1 instances.
 * Properly handles EAV entities (products, categories, customers)
 * and flat tables (orders, invoices, shipments, credit memos).
 */
class Maho_DataSync_Model_Adapter_Openmage extends Maho_DataSync_Model_Adapter_Abstract
{
    protected ?PDO $_connection = null;
    protected string $_tablePrefix = '';

    /** @var int|null Filter to specific entity ID (for incremental sync) */
    protected ?int $_entityIdFilter = null;

    /** @var array Cached attribute metadata */
    protected array $_attributeCache = [];

    /** @var array<int,array<string,string>> Cached source option_id => label maps, keyed by attribute_id */
    protected array $_optionLabelCache = [];

    /** @var array Cached entity type IDs */
    protected array $_entityTypeIds = [];

    /** @var array|null Cached custom fields config */
    protected static ?array $_customFieldsConfig = null;

    /**
     * Entity type configurations
     */
    protected array $_entityTypes = [
        'product' => [
            'entity_type_code' => 'catalog_product',
            'entity_table' => 'catalog_product_entity',
            'id_field' => 'entity_id',
            'date_field' => 'updated_at',
            'eav' => true,
        ],
        'category' => [
            'entity_type_code' => 'catalog_category',
            'entity_table' => 'catalog_category_entity',
            'id_field' => 'entity_id',
            'date_field' => 'updated_at',
            'eav' => true,
        ],
        'customer' => [
            'entity_type_code' => 'customer',
            'entity_table' => 'customer_entity',
            'id_field' => 'entity_id',
            'date_field' => 'updated_at',
            'eav' => true,
        ],
        'order' => [
            'entity_table' => 'sales_flat_order',
            'id_field' => 'entity_id',
            'date_field' => 'created_at',
            'eav' => false,
        ],
        'invoice' => [
            'entity_table' => 'sales_flat_invoice',
            'id_field' => 'entity_id',
            'date_field' => 'created_at',
            'eav' => false,
            'parent_field' => 'order_id',
        ],
        'shipment' => [
            'entity_table' => 'sales_flat_shipment',
            'id_field' => 'entity_id',
            'date_field' => 'created_at',
            'eav' => false,
            'parent_field' => 'order_id',
        ],
        'creditmemo' => [
            'entity_table' => 'sales_flat_creditmemo',
            'id_field' => 'entity_id',
            'date_field' => 'created_at',
            'eav' => false,
            'parent_field' => 'order_id',
        ],
        'product_attribute' => [
            'entity_table' => 'eav_attribute',
            'id_field' => 'attribute_id',
            'date_field' => null,
            'eav' => false,
        ],
        'newsletter' => [
            'entity_table' => 'newsletter_subscriber',
            'id_field' => 'subscriber_id',
            'date_field' => 'change_status_at',
            'eav' => false,
        ],
        'cms_block' => [
            'entity_table' => 'cms_block',
            'id_field' => 'block_id',
            'date_field' => 'update_time',
            'eav' => false,
        ],
        'cms_page' => [
            'entity_table' => 'cms_page',
            'id_field' => 'page_id',
            'date_field' => 'update_time',
            'eav' => false,
        ],
    ];

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getCode(): string
    {
        return 'openmage';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'OpenMage/Magento 1 Database';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getSupportedEntities(): array
    {
        return array_keys($this->_entityTypes);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function configure(array $config): void
    {
        parent::configure($config);

        // Parse connection string if provided
        if (isset($config['source']) && is_string($config['source'])) {
            $parsed = Mage::helper('datasync')->parseConnectionString($config['source']);
            $config = array_merge($config, $parsed);
        }

        $this->_tablePrefix = $config['prefix'] ?? '';

        // Build DSN
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $database = $config['database'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // Connect timeout so an unreachable source fails fast instead of hanging.
            // PDO MySQL has no portable per-read timeout constant, so read/stall hangs
            // must be bounded by the caller (e.g. a cron `timeout` wrapper).
            PDO::ATTR_TIMEOUT => 10,               // connect timeout (seconds)
        ];

        try {
            $this->_connection = new PDO(
                $dsn,
                $config['username'] ?? '',
                $config['password'] ?? '',
                $options,
            );
        } catch (PDOException $e) {
            throw Maho_DataSync_Exception::connectionFailed($e->getMessage(), [
                'host' => $host,
                'database' => $database,
            ]);
        }
    }

    /**
     * Set an existing PDO connection (used by incremental sync)
     */
    public function setDatabaseConnection(PDO $connection): self
    {
        $this->_connection = $connection;
        $this->_configured = true;
        return $this;
    }

    /**
     * Set entity ID filter for incremental sync
     */
    public function setEntityIdFilter(int $entityId): self
    {
        $this->_entityIdFilter = $entityId;
        return $this;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function validate(): bool
    {
        $this->_ensureConfigured();

        if ($this->_connection === null) {
            throw Maho_DataSync_Exception::connectionFailed('No database connection');
        }

        // Test query
        try {
            $stmt = $this->_connection->query('SELECT 1');
            $stmt->fetch();
            return true;
        } catch (PDOException $e) {
            throw Maho_DataSync_Exception::connectionFailed($e->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function read(string $entityType, array $filters = []): iterable
    {
        $this->_ensureConfigured();

        $entityType = strtolower($entityType);
        if (!isset($this->_entityTypes[$entityType])) {
            throw Maho_DataSync_Exception::entityNotFound($entityType);
        }

        $config = $this->_entityTypes[$entityType];

        if ($config['eav']) {
            return $this->_readEavEntity($entityType, $config, $filters);
        }

        return $this->_readFlatEntity($entityType, $config, $filters);
    }

    /**
     * Read flat (non-EAV) entities
     */
    protected function _readFlatEntity(string $entityType, array $config, array $filters): iterable
    {
        $table = $this->_tablePrefix . $config['entity_table'];
        $idField = $config['id_field'];
        $dateField = $config['date_field'];
        $normalizedFilters = $this->_normalizeFilters($filters);

        $sql = "SELECT * FROM {$table} WHERE 1=1";
        $params = [];

        // For product_attribute, filter to only product attributes
        if ($entityType === 'product_attribute') {
            $productEntityTypeId = $this->_getEntityTypeId('catalog_product');
            if ($productEntityTypeId) {
                $sql .= ' AND entity_type_id = :entity_type_id';
                $params['entity_type_id'] = $productEntityTypeId;
            }
        }

        // Date filters
        if ($dateField !== null) {
            if ($normalizedFilters['date_from'] !== null) {
                $sql .= " AND {$dateField} >= :date_from";
                $params['date_from'] = $normalizedFilters['date_from'] . ' 00:00:00';
            }
            if ($normalizedFilters['date_to'] !== null) {
                $sql .= " AND {$dateField} <= :date_to";
                $params['date_to'] = $normalizedFilters['date_to'] . ' 23:59:59';
            }
        }

        // ID filters
        if ($normalizedFilters['id_from'] !== null) {
            $sql .= " AND {$idField} >= :id_from";
            $params['id_from'] = $normalizedFilters['id_from'];
        }
        if ($normalizedFilters['id_to'] !== null) {
            $sql .= " AND {$idField} <= :id_to";
            $params['id_to'] = $normalizedFilters['id_to'];
        }

        // Specific entity ID filter (for incremental sync)
        if ($this->_entityIdFilter !== null) {
            $sql .= " AND {$idField} = :entity_id_filter";
            $params['entity_id_filter'] = $this->_entityIdFilter;
        }

        // Multiple entity IDs filter
        if (!empty($normalizedFilters['entity_ids']) && is_array($normalizedFilters['entity_ids'])) {
            $placeholders = [];
            foreach ($normalizedFilters['entity_ids'] as $i => $entityId) {
                $key = "entity_id_{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = (int) $entityId;
            }
            $sql .= " AND {$idField} IN (" . implode(',', $placeholders) . ')';
        }

        // Multiple increment IDs filter (orders only)
        if (!empty($normalizedFilters['increment_ids']) && is_array($normalizedFilters['increment_ids'])) {
            $placeholders = [];
            foreach ($normalizedFilters['increment_ids'] as $i => $incrementId) {
                $key = "increment_id_{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = $incrementId;
            }
            $sql .= ' AND increment_id IN (' . implode(',', $placeholders) . ')';
        }

        $sql .= " ORDER BY {$idField} ASC";

        if ($normalizedFilters['limit'] !== null) {
            $sql .= ' LIMIT ' . (int) $normalizedFilters['limit'];
        }
        if ($normalizedFilters['offset'] > 0) {
            $sql .= ' OFFSET ' . (int) $normalizedFilters['offset'];
        }

        try {
            $stmt = $this->_connection->prepare($sql);
            foreach ($params as $key => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue(":{$key}", $value, $type);
            }
            $stmt->execute();

            while ($row = $stmt->fetch()) {
                // Normalize id field to entity_id so engine can track source IDs
                if (!isset($row['entity_id']) && isset($row[$idField])) {
                    $row['entity_id'] = $row[$idField];
                }

                // Add related data for orders
                if ($entityType === 'order') {
                    $row = $this->_enrichOrderData($row);
                } elseif ($entityType === 'invoice') {
                    $row = $this->_enrichInvoiceData($row);
                } elseif ($entityType === 'shipment') {
                    $row = $this->_enrichShipmentData($row);
                } elseif ($entityType === 'creditmemo') {
                    $row = $this->_enrichCreditmemoData($row);
                } elseif ($entityType === 'product_attribute') {
                    $row = $this->_enrichAttributeData($row);
                } elseif ($entityType === 'cms_block') {
                    $row = $this->_enrichCmsBlockData($row);
                } elseif ($entityType === 'cms_page') {
                    $row = $this->_enrichCmsPageData($row);
                }

                yield $row;
            }
        } catch (PDOException $e) {
            throw new Maho_DataSync_Exception(
                "Database query failed: {$e->getMessage()}",
                Maho_DataSync_Exception::CODE_CONNECTION_FAILED,
            );
        }
    }

    /**
     * Read EAV entities (products, categories, customers)
     */
    protected function _readEavEntity(string $entityType, array $config, array $filters): iterable
    {
        $entityTable = $this->_tablePrefix . $config['entity_table'];
        $idField = $config['id_field'];
        $dateField = $config['date_field'];
        $entityTypeCode = $config['entity_type_code'];
        $normalizedFilters = $this->_normalizeFilters($filters);

        // Get entity type ID
        $entityTypeId = $this->_getEntityTypeId($entityTypeCode);
        if (!$entityTypeId) {
            throw new Maho_DataSync_Exception("Entity type not found: {$entityTypeCode}");
        }

        // Get all attributes for this entity type
        $attributes = $this->_getEntityAttributes($entityTypeId);

        // Build main query
        $sql = "SELECT e.* FROM {$entityTable} e WHERE 1=1";
        $params = [];

        if ($dateField !== null) {
            if ($normalizedFilters['date_from'] !== null) {
                $sql .= " AND e.{$dateField} >= :date_from";
                $params['date_from'] = $normalizedFilters['date_from'] . ' 00:00:00';
            }
            if ($normalizedFilters['date_to'] !== null) {
                $sql .= " AND e.{$dateField} <= :date_to";
                $params['date_to'] = $normalizedFilters['date_to'] . ' 23:59:59';
            }
        }

        if ($normalizedFilters['id_from'] !== null) {
            $sql .= " AND e.{$idField} >= :id_from";
            $params['id_from'] = $normalizedFilters['id_from'];
        }
        if ($normalizedFilters['id_to'] !== null) {
            $sql .= " AND e.{$idField} <= :id_to";
            $params['id_to'] = $normalizedFilters['id_to'];
        }

        // Specific entity ID filter (for incremental sync)
        if ($this->_entityIdFilter !== null) {
            $sql .= " AND e.{$idField} = :entity_id_filter";
            $params['entity_id_filter'] = $this->_entityIdFilter;
        }

        // Multiple entity IDs filter (same as _readFlatEntity)
        if (!empty($normalizedFilters['entity_ids']) && is_array($normalizedFilters['entity_ids'])) {
            $placeholders = [];
            foreach ($normalizedFilters['entity_ids'] as $i => $entityId) {
                $key = "entity_id_{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = (int) $entityId;
            }
            $sql .= " AND e.{$idField} IN (" . implode(',', $placeholders) . ')';
        }

        $sql .= " ORDER BY e.{$idField} ASC";

        if ($normalizedFilters['limit'] !== null) {
            $sql .= ' LIMIT ' . (int) $normalizedFilters['limit'];
        }
        if ($normalizedFilters['offset'] > 0) {
            $sql .= ' OFFSET ' . (int) $normalizedFilters['offset'];
        }

        try {
            $stmt = $this->_connection->prepare($sql);
            foreach ($params as $key => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue(":{$key}", $value, $type);
            }
            $stmt->execute();

            while ($row = $stmt->fetch()) {
                $entityId = $row[$idField];

                // Load EAV attribute values
                $row = $this->_loadEavAttributes($entityType, $config, $entityId, $row, $attributes);

                // Add entity-specific related data
                if ($entityType === 'product') {
                    $row = $this->_enrichProductData($row);
                } elseif ($entityType === 'category') {
                    $row = $this->_enrichCategoryData($row);
                } elseif ($entityType === 'customer') {
                    $row = $this->_enrichCustomerData($row);
                }

                yield $row;
            }
        } catch (PDOException $e) {
            throw new Maho_DataSync_Exception(
                "Database query failed: {$e->getMessage()}",
                Maho_DataSync_Exception::CODE_CONNECTION_FAILED,
            );
        }
    }

    /**
     * Load all EAV attribute values for an entity
     */
    protected function _loadEavAttributes(
        string $entityType,
        array $config,
        int $entityId,
        array $row,
        array $attributes,
    ): array {
        $entityTable = $config['entity_table'];
        $backends = ['varchar', 'int', 'text', 'decimal', 'datetime'];

        foreach ($backends as $backend) {
            $table = $this->_tablePrefix . $entityTable . '_' . $backend;

            // Check if table exists
            if (!$this->_tableExists($table)) {
                continue;
            }

            // Check if table has store_id column (product EAV has it, customer EAV doesn't)
            $hasStoreId = $this->_tableHasColumn($table, 'store_id');

            try {
                if ($hasStoreId) {
                    $sql = "SELECT attribute_id, value, store_id FROM {$table} WHERE entity_id = :entity_id";
                } else {
                    $sql = "SELECT attribute_id, value FROM {$table} WHERE entity_id = :entity_id";
                }

                $stmt = $this->_connection->prepare($sql);
                $stmt->execute(['entity_id' => $entityId]);

                while ($attrRow = $stmt->fetch()) {
                    $attributeId = $attrRow['attribute_id'];

                    if (!isset($attributes[$attributeId])) {
                        continue;
                    }

                    $attrCode = $attributes[$attributeId]['attribute_code'];

                    if ($hasStoreId) {
                        $storeId = $attrRow['store_id'];
                        // Store-scoped values: prefer store 0 (default), overwrite with specific store
                        if ($storeId == 0 || !isset($row[$attrCode])) {
                            $row[$attrCode] = $attrRow['value'];
                        }
                    } else {
                        // No store scope, just use the value
                        $row[$attrCode] = $attrRow['value'];
                    }
                }
            } catch (PDOException $e) {
                // Silently continue if EAV table query fails
                continue;
            }
        }

        // Product select/multiselect values are stored as source option_ids, which are
        // meaningless on the target (the same label often has a different option_id there).
        // Export them as their label so the importer resolves label -> target option_id.
        // Scoped to product: other entities' importers don't resolve labels, so translating
        // their select values (e.g. customer group/gender) would corrupt them.
        if ($entityType === 'product') {
            $row = $this->_translateSelectValuesToLabels($row, $attributes);
        }

        return $row;
    }

    /**
     * Replace select/multiselect attribute values in a product row with their option
     * label(s). A value that is not a known option_id is left untouched (it may already
     * be a label, or a stray value). Multiselect values stay comma-separated.
     *
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $attributes  keyed by attribute_id
     * @return array<string,mixed>
     */
    protected function _translateSelectValuesToLabels(array $row, array $attributes): array
    {
        foreach ($attributes as $attributeId => $meta) {
            $frontendInput = $meta['frontend_input'] ?? '';
            if ($frontendInput !== 'select' && $frontendInput !== 'multiselect') {
                continue;
            }
            $code = $meta['attribute_code'] ?? '';
            if ($code === '' || !isset($row[$code]) || $row[$code] === '') {
                continue;
            }

            $labels = $this->_getSourceOptionLabels((int) $attributeId);
            if ($labels === []) {
                continue;
            }

            if ($frontendInput === 'multiselect') {
                $out = [];
                foreach (explode(',', (string) $row[$code]) as $id) {
                    $id = trim($id);
                    if ($id === '') {
                        continue;
                    }
                    $out[] = $labels[$id] ?? $id;
                }
                $row[$code] = implode(',', $out);
            } else {
                $id = (string) $row[$code];
                if (isset($labels[$id])) {
                    $row[$code] = $labels[$id];
                }
            }
        }

        return $row;
    }

    /**
     * Load the source option_id => default-store label map for an attribute, cached.
     *
     * @return array<string,string>
     */
    protected function _getSourceOptionLabels(int $attributeId): array
    {
        if (!isset($this->_optionLabelCache[$attributeId])) {
            $optionTable = $this->_tablePrefix . 'eav_attribute_option';
            $valueTable  = $this->_tablePrefix . 'eav_attribute_option_value';
            $sql = "SELECT o.option_id, v.value
                    FROM {$optionTable} o
                    JOIN {$valueTable} v ON v.option_id = o.option_id AND v.store_id = 0
                    WHERE o.attribute_id = :attribute_id";
            $map = [];
            try {
                $stmt = $this->_connection->prepare($sql);
                $stmt->execute(['attribute_id' => $attributeId]);
                while ($r = $stmt->fetch()) {
                    $map[(string) $r['option_id']] = (string) $r['value'];
                }
            } catch (PDOException $e) {
                $map = [];
            }
            $this->_optionLabelCache[$attributeId] = $map;
        }

        return $this->_optionLabelCache[$attributeId];
    }

    /**
     * Check if a table has a specific column
     */
    protected function _tableHasColumn(string $tableName, string $columnName): bool
    {
        static $cache = [];
        $key = $tableName . '.' . $columnName;

        if (!isset($cache[$key])) {
            try {
                $stmt = $this->_connection->prepare(
                    'SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column',
                );
                $stmt->execute(['table' => $tableName, 'column' => $columnName]);
                $cache[$key] = $stmt->fetchColumn() > 0;
            } catch (PDOException $e) {
                $cache[$key] = false;
            }
        }

        return $cache[$key];
    }

    /**
     * Enrich product data with images, categories, stock, etc.
     */
    protected function _enrichProductData(array $row): array
    {
        $entityId = $row['entity_id'];

        // Load product images
        $row['images'] = $this->_loadProductImages($entityId);

        // Load category IDs
        $row['category_ids'] = $this->_loadProductCategoryIds($entityId);

        // Load stock data (flatten into row for Product entity)
        $stockData = $this->_loadProductStock($entityId);
        if (!empty($stockData)) {
            foreach ($stockData as $key => $value) {
                $row[$key] = $value;
            }
        }

        // Load tier prices
        $row['tier_prices'] = $this->_loadProductTierPrices($entityId);

        // Load group prices
        $row['group_prices'] = $this->_loadProductGroupPrices($entityId);

        // Load custom options
        $row['custom_options'] = $this->_loadProductCustomOptions($entityId);

        // Load configurable attributes and links
        if (($row['type_id'] ?? '') === 'configurable') {
            $row['configurable_attributes'] = $this->_loadConfigurableAttributes($entityId);
            $row['configurable_children_skus'] = $this->_loadConfigurableChildren($entityId);
        } elseif (($row['type_id'] ?? '') === 'simple') {
            // Check if this simple product is a child of a configurable
            // This enables Mode 2 linking when only children are in the sync batch
            $parentSku = $this->_loadConfigurableParentSku($entityId);
            if ($parentSku !== null) {
                $row['configurable_parent_sku'] = $parentSku;
            }
        }

        // Load grouped product links
        if (($row['type_id'] ?? '') === 'grouped') {
            $row['grouped_product_skus'] = $this->_loadGroupedProducts($entityId);
        }

        // Load website IDs
        $row['website_ids'] = $this->_loadProductWebsites($entityId);

        return $row;
    }

    /**
     * Load product images
     */
    protected function _loadProductImages(int $entityId): array
    {
        $mediaTable = $this->_tablePrefix . 'catalog_product_entity_media_gallery';
        $valueTable = $this->_tablePrefix . 'catalog_product_entity_media_gallery_value';

        $sql = "SELECT mg.value as file, mgv.label, mgv.position, mgv.disabled
                FROM {$mediaTable} mg
                LEFT JOIN {$valueTable} mgv ON mg.value_id = mgv.value_id AND mgv.store_id = 0
                WHERE mg.entity_id = :entity_id
                ORDER BY mgv.position ASC";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['entity_id' => $entityId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Load product category IDs
     */
    protected function _loadProductCategoryIds(int $entityId): array
    {
        $table = $this->_tablePrefix . 'catalog_category_product';
        $sql = "SELECT category_id FROM {$table} WHERE product_id = :product_id ORDER BY position ASC";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['product_id' => $entityId]);
            return array_column($stmt->fetchAll(), 'category_id');
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Load product stock data
     */
    protected function _loadProductStock(int $entityId): array
    {
        $table = $this->_tablePrefix . 'cataloginventory_stock_item';
        $sql = "SELECT qty, is_in_stock, min_qty, min_sale_qty, max_sale_qty,
                       use_config_min_qty, use_config_min_sale_qty, use_config_max_sale_qty,
                       use_config_manage_stock, backorders, use_config_backorders,
                       manage_stock, notify_stock_qty, use_config_notify_stock_qty
                FROM {$table} WHERE product_id = :product_id";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['product_id' => $entityId]);
            return $stmt->fetch() ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Load product tier prices
     */
    protected function _loadProductTierPrices(int $entityId): array
    {
        $table = $this->_tablePrefix . 'catalog_product_entity_tier_price';
        $sql = "SELECT customer_group_id, qty, value, website_id
                FROM {$table} WHERE entity_id = :entity_id ORDER BY qty ASC";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['entity_id' => $entityId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Load product group prices
     */
    protected function _loadProductGroupPrices(int $entityId): array
    {
        $table = $this->_tablePrefix . 'catalog_product_entity_group_price';

        if (!$this->_tableExists($table)) {
            return [];
        }

        $sql = "SELECT customer_group_id, value, website_id
                FROM {$table} WHERE entity_id = :entity_id";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['entity_id' => $entityId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Load product custom options
     */
    protected function _loadProductCustomOptions(int $entityId): array
    {
        $optionTable = $this->_tablePrefix . 'catalog_product_option';
        $titleTable = $this->_tablePrefix . 'catalog_product_option_title';
        $priceTable = $this->_tablePrefix . 'catalog_product_option_price';
        $valueTable = $this->_tablePrefix . 'catalog_product_option_type_value';
        $valueTitleTable = $this->_tablePrefix . 'catalog_product_option_type_title';
        $valuePriceTable = $this->_tablePrefix . 'catalog_product_option_type_price';

        $sql = "SELECT o.option_id, o.type, o.is_require, o.sort_order, o.sku,
                       t.title, p.price, p.price_type
                FROM {$optionTable} o
                LEFT JOIN {$titleTable} t ON o.option_id = t.option_id AND t.store_id = 0
                LEFT JOIN {$priceTable} p ON o.option_id = p.option_id AND p.store_id = 0
                WHERE o.product_id = :product_id
                ORDER BY o.sort_order ASC";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['product_id' => $entityId]);
            $options = [];

            while ($option = $stmt->fetch()) {
                $optionId = $option['option_id'];
                $option['values'] = [];

                // Load option values for select/radio/checkbox types
                if (in_array($option['type'], ['drop_down', 'radio', 'checkbox', 'multiple'])) {
                    $valuesSql = "SELECT v.option_type_id, v.sku, v.sort_order,
                                         vt.title, vp.price, vp.price_type
                                  FROM {$valueTable} v
                                  LEFT JOIN {$valueTitleTable} vt ON v.option_type_id = vt.option_type_id AND vt.store_id = 0
                                  LEFT JOIN {$valuePriceTable} vp ON v.option_type_id = vp.option_type_id AND vp.store_id = 0
                                  WHERE v.option_id = :option_id
                                  ORDER BY v.sort_order ASC";
                    $valuesStmt = $this->_connection->prepare($valuesSql);
                    $valuesStmt->execute(['option_id' => $optionId]);
                    $option['values'] = $valuesStmt->fetchAll();
                }

                $options[] = $option;
            }

            return $options;
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Load configurable product attributes
     */
    protected function _loadConfigurableAttributes(int $entityId): array
    {
        $table = $this->_tablePrefix . 'catalog_product_super_attribute';
        $labelTable = $this->_tablePrefix . 'catalog_product_super_attribute_label';
        $attrTable = $this->_tablePrefix . 'eav_attribute';

        $sql = "SELECT sa.product_super_attribute_id, sa.attribute_id, sa.position,
                       a.attribute_code, sal.value as label
                FROM {$table} sa
                JOIN {$attrTable} a ON sa.attribute_id = a.attribute_id
                LEFT JOIN {$labelTable} sal ON sa.product_super_attribute_id = sal.product_super_attribute_id
                    AND sal.store_id = 0
                WHERE sa.product_id = :product_id
                ORDER BY sa.position ASC";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['product_id' => $entityId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Load configurable product children SKUs
     */
    protected function _loadConfigurableChildren(int $entityId): array
    {
        $linkTable = $this->_tablePrefix . 'catalog_product_super_link';
        $productTable = $this->_tablePrefix . 'catalog_product_entity';

        $sql = "SELECT p.sku
                FROM {$linkTable} sl
                JOIN {$productTable} p ON sl.product_id = p.entity_id
                WHERE sl.parent_id = :parent_id";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['parent_id' => $entityId]);
            return array_column($stmt->fetchAll(), 'sku');
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Load the parent configurable SKU for a simple product (if any)
     *
     * Enables Mode 2 linking (configurable_parent_sku on child row) so that
     * incremental syncs that only process children can still establish links.
     */
    protected function _loadConfigurableParentSku(int $entityId): ?string
    {
        $linkTable = $this->_tablePrefix . 'catalog_product_super_link';
        $productTable = $this->_tablePrefix . 'catalog_product_entity';

        $sql = "SELECT p.sku
                FROM {$linkTable} sl
                JOIN {$productTable} p ON sl.parent_id = p.entity_id
                WHERE sl.product_id = :child_id
                LIMIT 1";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['child_id' => $entityId]);
            $result = $stmt->fetchColumn();
            return $result !== false ? (string) $result : null;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Load grouped product SKUs
     */
    protected function _loadGroupedProducts(int $entityId): array
    {
        $linkTable = $this->_tablePrefix . 'catalog_product_link';
        $productTable = $this->_tablePrefix . 'catalog_product_entity';

        // Link type 3 = grouped products
        $sql = "SELECT p.sku
                FROM {$linkTable} l
                JOIN {$productTable} p ON l.linked_product_id = p.entity_id
                WHERE l.product_id = :product_id AND l.link_type_id = 3";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['product_id' => $entityId]);
            return array_column($stmt->fetchAll(), 'sku');
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Load product website IDs
     */
    protected function _loadProductWebsites(int $entityId): array
    {
        $table = $this->_tablePrefix . 'catalog_product_website';
        $sql = "SELECT website_id FROM {$table} WHERE product_id = :product_id";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['product_id' => $entityId]);
            return array_column($stmt->fetchAll(), 'website_id');
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get custom fields configuration from JSON file
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
        }

        // Return defaults if no config file
        self::$_customFieldsConfig = [
            'order_fields' => ['fields' => []],
            'related_tables' => ['tables' => []],
        ];
        return self::$_customFieldsConfig;
    }

    /**
     * Enrich order data with items, addresses, and payment
     */
    protected function _enrichOrderData(array $row): array
    {
        $orderId = $row['entity_id'];

        // Load order items
        $row['items'] = $this->_loadOrderItems($orderId);

        // Load addresses
        $row['addresses'] = $this->_loadOrderAddresses($orderId);

        // Flatten billing address to billing_* fields
        if (isset($row['addresses']['billing'])) {
            $row = $this->_flattenAddress($row, $row['addresses']['billing'], 'billing');
        }

        // Flatten shipping address to shipping_* fields
        if (isset($row['addresses']['shipping'])) {
            $row = $this->_flattenAddress($row, $row['addresses']['shipping'], 'shipping');
        }

        // Load payment
        $row['payment'] = $this->_loadOrderPayment($orderId);

        // Flatten payment method
        if (!empty($row['payment']['method'])) {
            $row['payment_method'] = $row['payment']['method'];
        }

        // Load payment tracker payments (for MultiplePayment method)
        $row['payment_tracker_payments'] = $this->_loadPaymentTrackerPayments($orderId);

        // Load status history
        $row['status_history'] = $this->_loadOrderStatusHistory($orderId);

        // Load invoices
        $row['invoices'] = $this->_loadOrderInvoices($orderId);

        // Load shipments
        $row['shipments'] = $this->_loadOrderShipments($orderId);

        // Load configurable related tables
        $row = $this->_loadRelatedTables($row);

        // Preserve source increment_id - tell the Order entity to use this exact increment_id
        // instead of generating a new one (critical for maintaining order number consistency)
        $row['target_increment_id'] = $row['increment_id'];

        return $row;
    }

    /**
     * Load related tables configured in order_custom_fields.json
     */
    protected function _loadRelatedTables(array $row): array
    {
        $config = $this->_getCustomFieldsConfig();
        $relatedTables = $config['related_tables']['tables'] ?? [];

        foreach ($relatedTables as $tableName => $tableConfig) {
            $foreignKey = $tableConfig['foreign_key'] ?? null;
            $primaryKey = $tableConfig['primary_key'] ?? null;
            $fields = $tableConfig['fields'] ?? [];

            if (!$foreignKey || !$primaryKey || empty($fields)) {
                continue;
            }

            // Check if the order has a value for the foreign key
            $foreignKeyValue = $row[$foreignKey] ?? null;
            if (empty($foreignKeyValue)) {
                continue;
            }

            // Load the related record
            $relatedData = $this->_loadRelatedTableRecord($tableName, $primaryKey, $foreignKeyValue, $fields);
            if ($relatedData) {
                // Store with a key like "related_shipnote_note"
                $row['related_' . $tableName] = $relatedData;
            }
        }

        return $row;
    }

    /**
     * Load a single record from a related table
     */
    protected function _loadRelatedTableRecord(string $tableName, string $primaryKey, int|string $keyValue, array $fields): ?array
    {
        $table = $this->_tablePrefix . $tableName;

        // Check if table exists
        if (!$this->_tableExists($table)) {
            return null;
        }

        // Build field list
        $fieldList = implode(', ', array_map(fn($f) => "`{$f}`", $fields));
        $sql = "SELECT {$fieldList} FROM {$table} WHERE `{$primaryKey}` = :key_value LIMIT 1";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['key_value' => $keyValue]);
            $result = $stmt->fetch();
            return $result ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Flatten an address array into prefixed fields
     */
    protected function _flattenAddress(array $row, array $address, string $prefix): array
    {
        $fields = [
            'firstname', 'lastname', 'company', 'street', 'city',
            'region', 'region_id', 'postcode', 'country_id',
            'telephone', 'fax', 'email',
        ];

        foreach ($fields as $field) {
            if (isset($address[$field])) {
                $row[$prefix . '_' . $field] = $address[$field];
            }
        }

        return $row;
    }

    /**
     * Load order items
     */
    protected function _loadOrderItems(int $orderId): array
    {
        $table = $this->_tablePrefix . 'sales_flat_order_item';
        $sql = "SELECT * FROM {$table} WHERE order_id = :order_id ORDER BY item_id ASC";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['order_id' => $orderId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Load order addresses
     */
    protected function _loadOrderAddresses(int $orderId): array
    {
        $table = $this->_tablePrefix . 'sales_flat_order_address';
        $sql = "SELECT * FROM {$table} WHERE parent_id = :order_id";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['order_id' => $orderId]);
            $addresses = [];
            while ($addr = $stmt->fetch()) {
                $addresses[$addr['address_type']] = $addr;
            }
            return $addresses;
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Load order payment
     */
    protected function _loadOrderPayment(int $orderId): array
    {
        $table = $this->_tablePrefix . 'sales_flat_order_payment';
        $sql = "SELECT * FROM {$table} WHERE parent_id = :order_id";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['order_id' => $orderId]);
            return $stmt->fetch() ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Load payment tracker payments (MDN_PaymentTracker module)
     *
     * These records store the breakdown of multiple payment methods
     * for orders using the MultiplePayment method.
     */
    protected function _loadPaymentTrackerPayments(int $orderId): array
    {
        $table = $this->_tablePrefix . 'payment_tracker_payment';

        // Check if table exists
        try {
            $checkSql = "SHOW TABLES LIKE '{$table}'";
            $stmt = $this->_connection->query($checkSql);
            if ($stmt->rowCount() === 0) {
                return [];
            }
        } catch (PDOException $e) {
            return [];
        }

        $sql = "SELECT * FROM {$table} WHERE ptp_order_id = :order_id ORDER BY ptp_id ASC";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['order_id' => $orderId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Load order status history
     */
    protected function _loadOrderStatusHistory(int $orderId): array
    {
        $table = $this->_tablePrefix . 'sales_flat_order_status_history';
        $sql = "SELECT * FROM {$table} WHERE parent_id = :order_id ORDER BY created_at ASC";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['order_id' => $orderId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Load order invoices with their items
     */
    protected function _loadOrderInvoices(int $orderId): array
    {
        $table = $this->_tablePrefix . 'sales_flat_invoice';
        $sql = "SELECT * FROM {$table} WHERE order_id = :order_id ORDER BY entity_id ASC";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['order_id' => $orderId]);
            $invoices = $stmt->fetchAll();

            // Load items for each invoice
            $itemTable = $this->_tablePrefix . 'sales_flat_invoice_item';
            foreach ($invoices as &$invoice) {
                $itemSql = "SELECT * FROM {$itemTable} WHERE parent_id = :invoice_id ORDER BY entity_id ASC";
                $itemStmt = $this->_connection->prepare($itemSql);
                $itemStmt->execute(['invoice_id' => $invoice['entity_id']]);
                $invoice['items'] = $itemStmt->fetchAll();
            }

            return $invoices;
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Load order shipments with their items and tracks
     */
    protected function _loadOrderShipments(int $orderId): array
    {
        $table = $this->_tablePrefix . 'sales_flat_shipment';
        $sql = "SELECT * FROM {$table} WHERE order_id = :order_id ORDER BY entity_id ASC";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['order_id' => $orderId]);
            $shipments = $stmt->fetchAll();

            $itemTable = $this->_tablePrefix . 'sales_flat_shipment_item';
            $trackTable = $this->_tablePrefix . 'sales_flat_shipment_track';

            foreach ($shipments as &$shipment) {
                // Load items
                $itemSql = "SELECT * FROM {$itemTable} WHERE parent_id = :shipment_id ORDER BY entity_id ASC";
                $itemStmt = $this->_connection->prepare($itemSql);
                $itemStmt->execute(['shipment_id' => $shipment['entity_id']]);
                $shipment['items'] = $itemStmt->fetchAll();

                // Load tracks
                $trackSql = "SELECT * FROM {$trackTable} WHERE parent_id = :shipment_id ORDER BY entity_id ASC";
                $trackStmt = $this->_connection->prepare($trackSql);
                $trackStmt->execute(['shipment_id' => $shipment['entity_id']]);
                $shipment['tracks'] = $trackStmt->fetchAll();
            }

            return $shipments;
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Enrich invoice data with items
     */
    protected function _enrichInvoiceData(array $row): array
    {
        $invoiceId = $row['entity_id'];
        $table = $this->_tablePrefix . 'sales_flat_invoice_item';
        $sql = "SELECT * FROM {$table} WHERE parent_id = :invoice_id ORDER BY entity_id ASC";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['invoice_id' => $invoiceId]);
            $row['items'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            $row['items'] = [];
        }

        return $row;
    }

    /**
     * Enrich shipment data with items and tracks
     */
    protected function _enrichShipmentData(array $row): array
    {
        $shipmentId = $row['entity_id'];

        // Preserve source increment_id
        $row['target_increment_id'] = $row['increment_id'];

        // Items
        $itemTable = $this->_tablePrefix . 'sales_flat_shipment_item';
        try {
            $stmt = $this->_connection->prepare(
                "SELECT * FROM {$itemTable} WHERE parent_id = :shipment_id ORDER BY entity_id ASC",
            );
            $stmt->execute(['shipment_id' => $shipmentId]);
            $row['items'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            $row['items'] = [];
        }

        // Tracks
        $trackTable = $this->_tablePrefix . 'sales_flat_shipment_track';
        try {
            $stmt = $this->_connection->prepare(
                "SELECT * FROM {$trackTable} WHERE parent_id = :shipment_id ORDER BY entity_id ASC",
            );
            $stmt->execute(['shipment_id' => $shipmentId]);
            $row['tracks'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            $row['tracks'] = [];
        }

        return $row;
    }

    /**
     * Enrich credit memo data with items
     */
    protected function _enrichCreditmemoData(array $row): array
    {
        $creditmemoId = $row['entity_id'];

        // Preserve source increment_id
        $row['target_increment_id'] = $row['increment_id'];

        $table = $this->_tablePrefix . 'sales_flat_creditmemo_item';
        $sql = "SELECT * FROM {$table} WHERE parent_id = :creditmemo_id ORDER BY entity_id ASC";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['creditmemo_id' => $creditmemoId]);
            $row['items'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            $row['items'] = [];
        }

        return $row;
    }

    /**
     * Enrich category data
     */
    protected function _enrichCategoryData(array $row): array
    {
        $categoryId = $row['entity_id'];
        $table = $this->_tablePrefix . 'catalog_category_product';
        $productTable = $this->_tablePrefix . 'catalog_product_entity';

        // Load product assignments with positions, mapped by SKU for cross-instance compatibility
        try {
            $stmt = $this->_connection->prepare(
                "SELECT p.sku, cp.position FROM {$table} cp
                 JOIN {$productTable} p ON cp.product_id = p.entity_id
                 WHERE cp.category_id = :category_id
                 ORDER BY cp.position ASC",
            );
            $stmt->execute(['category_id' => $categoryId]);
            $row['category_products'] = $stmt->fetchAll();
            $row['product_count'] = count($row['category_products']);
        } catch (PDOException $e) {
            $row['category_products'] = [];
            $row['product_count'] = 0;
        }

        return $row;
    }

    /**
     * Enrich customer data with addresses
     */
    protected function _enrichCustomerData(array $row): array
    {
        $customerId = $row['entity_id'];

        // Load customer addresses
        $addresses = $this->_loadCustomerAddresses($customerId);

        // Get default billing/shipping address IDs from customer EAV data
        $defaultBillingId = $row['default_billing'] ?? null;
        $defaultShippingId = $row['default_shipping'] ?? null;

        // Mark addresses as default billing/shipping
        foreach ($addresses as &$address) {
            $addressId = $address['entity_id'] ?? null;
            if ($addressId) {
                $address['is_default_billing'] = ($defaultBillingId !== null && $addressId == $defaultBillingId);
                $address['is_default_shipping'] = ($defaultShippingId !== null && $addressId == $defaultShippingId);
            }
        }

        $row['addresses'] = $addresses;

        return $row;
    }

    /**
     * Load customer addresses
     */
    protected function _loadCustomerAddresses(int $customerId): array
    {
        $entityTable = $this->_tablePrefix . 'customer_address_entity';

        $sql = "SELECT * FROM {$entityTable} WHERE parent_id = :customer_id";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['customer_id' => $customerId]);
            $addresses = [];

            while ($row = $stmt->fetch()) {
                $addressId = $row['entity_id'];
                // Load EAV attributes for address
                $row = $this->_loadAddressEavAttributes($addressId, $row);
                $addresses[] = $row;
            }

            return $addresses;
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Load EAV attributes for customer address
     */
    protected function _loadAddressEavAttributes(int $addressId, array $row): array
    {
        $entityTable = 'customer_address_entity';
        $backends = ['varchar', 'int', 'text', 'datetime'];

        foreach ($backends as $backend) {
            $table = $this->_tablePrefix . $entityTable . '_' . $backend;

            if (!$this->_tableExists($table)) {
                continue;
            }

            $sql = "SELECT ea.attribute_code, v.value
                    FROM {$table} v
                    JOIN " . $this->_tablePrefix . 'eav_attribute ea ON v.attribute_id = ea.attribute_id
                    WHERE v.entity_id = :entity_id';

            try {
                $stmt = $this->_connection->prepare($sql);
                $stmt->execute(['entity_id' => $addressId]);

                while ($attrRow = $stmt->fetch()) {
                    $row[$attrRow['attribute_code']] = $attrRow['value'];
                }
            } catch (PDOException $e) {
                // Continue
            }
        }

        return $row;
    }

    /**
     * Enrich attribute data with options
     */
    protected function _enrichAttributeData(array $row): array
    {
        // Only for product attributes
        $entityTypeId = $this->_getEntityTypeId('catalog_product');
        if ($row['entity_type_id'] != $entityTypeId) {
            return $row;
        }

        $attributeId = $row['attribute_id'];

        // Load attribute options
        $optionTable = $this->_tablePrefix . 'eav_attribute_option';
        $valueTable = $this->_tablePrefix . 'eav_attribute_option_value';

        $sql = "SELECT o.option_id, v.value, o.sort_order
                FROM {$optionTable} o
                JOIN {$valueTable} v ON o.option_id = v.option_id AND v.store_id = 0
                WHERE o.attribute_id = :attribute_id
                ORDER BY o.sort_order ASC";

        try {
            $stmt = $this->_connection->prepare($sql);
            $stmt->execute(['attribute_id' => $attributeId]);
            $row['options'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            $row['options'] = [];
        }

        // Load additional attribute properties
        $catalogAttrTable = $this->_tablePrefix . 'catalog_eav_attribute';
        try {
            $stmt = $this->_connection->prepare(
                "SELECT * FROM {$catalogAttrTable} WHERE attribute_id = :attribute_id",
            );
            $stmt->execute(['attribute_id' => $attributeId]);
            $catalogAttr = $stmt->fetch();
            if ($catalogAttr) {
                $row = array_merge($row, $catalogAttr);
            }
        } catch (PDOException $e) {
            // Continue
        }

        return $row;
    }

    /**
     * Enrich CMS block data with store associations
     */
    protected function _enrichCmsBlockData(array $row): array
    {
        $storeTable = $this->_tablePrefix . 'cms_block_store';

        try {
            $stmt = $this->_connection->prepare(
                "SELECT store_id FROM {$storeTable} WHERE block_id = :block_id",
            );
            $stmt->execute(['block_id' => $row['block_id']]);
            $row['store_ids'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            $row['store_ids'] = [0];
        }

        return $row;
    }

    /**
     * Enrich CMS page data with store associations
     */
    protected function _enrichCmsPageData(array $row): array
    {
        $storeTable = $this->_tablePrefix . 'cms_page_store';

        try {
            $stmt = $this->_connection->prepare(
                "SELECT store_id FROM {$storeTable} WHERE page_id = :page_id",
            );
            $stmt->execute(['page_id' => $row['page_id']]);
            $row['store_ids'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            $row['store_ids'] = [0];
        }

        return $row;
    }

    /**
     * Get entity type ID by code
     */
    protected function _getEntityTypeId(string $entityTypeCode): ?int
    {
        if (!isset($this->_entityTypeIds[$entityTypeCode])) {
            $table = $this->_tablePrefix . 'eav_entity_type';
            $sql = "SELECT entity_type_id FROM {$table} WHERE entity_type_code = :code";

            try {
                $stmt = $this->_connection->prepare($sql);
                $stmt->execute(['code' => $entityTypeCode]);
                $this->_entityTypeIds[$entityTypeCode] = (int) $stmt->fetchColumn() ?: null;
            } catch (PDOException $e) {
                $this->_entityTypeIds[$entityTypeCode] = null;
            }
        }

        return $this->_entityTypeIds[$entityTypeCode];
    }

    /**
     * Get all attributes for an entity type
     */
    protected function _getEntityAttributes(int $entityTypeId): array
    {
        if (!isset($this->_attributeCache[$entityTypeId])) {
            $table = $this->_tablePrefix . 'eav_attribute';
            $sql = "SELECT attribute_id, attribute_code, backend_type, frontend_input
                    FROM {$table} WHERE entity_type_id = :entity_type_id";

            try {
                $stmt = $this->_connection->prepare($sql);
                $stmt->execute(['entity_type_id' => $entityTypeId]);
                $this->_attributeCache[$entityTypeId] = [];

                while ($row = $stmt->fetch()) {
                    $this->_attributeCache[$entityTypeId][$row['attribute_id']] = $row;
                }
            } catch (PDOException $e) {
                $this->_attributeCache[$entityTypeId] = [];
            }
        }

        return $this->_attributeCache[$entityTypeId];
    }

    /**
     * Check if a table exists
     */
    protected function _tableExists(string $tableName): bool
    {
        static $cache = [];

        if (!isset($cache[$tableName])) {
            try {
                $stmt = $this->_connection->prepare(
                    'SELECT COUNT(*) FROM information_schema.tables
                     WHERE table_schema = DATABASE() AND table_name = :table',
                );
                $stmt->execute(['table' => $tableName]);
                $cache[$tableName] = $stmt->fetchColumn() > 0;
            } catch (PDOException $e) {
                $cache[$tableName] = false;
            }
        }

        return $cache[$tableName];
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function count(string $entityType, array $filters = []): ?int
    {
        $this->_ensureConfigured();

        $entityType = strtolower($entityType);
        if (!isset($this->_entityTypes[$entityType])) {
            return null;
        }

        $config = $this->_entityTypes[$entityType];
        $table = $this->_tablePrefix . $config['entity_table'];

        try {
            $stmt = $this->_connection->query("SELECT COUNT(*) FROM {$table}");
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getInfo(): array
    {
        $info = parent::getInfo();

        if ($this->_connection !== null) {
            try {
                $info['server_version'] = $this->_connection->getAttribute(PDO::ATTR_SERVER_VERSION);
                $info['connection_status'] = $this->_connection->getAttribute(PDO::ATTR_CONNECTION_STATUS);
            } catch (PDOException $e) {
                $info['connection_error'] = $e->getMessage();
            }
        }

        $info['table_prefix'] = $this->_tablePrefix;

        return $info;
    }

    /**
     * Get the PDO connection
     */
    public function getConnection(): ?PDO
    {
        return $this->_connection;
    }

    /**
     * Get table name with prefix
     */
    public function getTableName(string $tableName): string
    {
        return $this->_tablePrefix . $tableName;
    }
}
