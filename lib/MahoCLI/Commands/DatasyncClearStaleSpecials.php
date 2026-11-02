<?php

/**
 * Maho
 *
 * @package    MahoCLI
 * @copyright  Copyright (c) 2026 Mageaustralia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoCLI\Commands;

use Mage;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Clear "stale" catalog special prices — a product that carries a special_price
 * on THIS install while the same SKU on the legacy live site no longer has one.
 *
 * Background: DataSync writes values but does not null out a special_price that
 * was later *removed* on live, so retired specials get stuck here and the
 * product renders "on special" when live shows the regular price. This command
 * closes that gap by matching SKU→SKU and clearing every special_price this
 * install still holds that live has cleared to NULL.
 *
 * Only clears where the SKU EXISTS on live and its live special_price IS NULL.
 * A SKU absent from live, or with a (possibly different) live special, is left
 * untouched. Every cleared value is first written to a rollback .sql under var/.
 *
 * Idempotent: re-running after a clear finds nothing and is a no-op. Defaults to
 * a dry run; pass --apply to write. Re-run to catch specials the source
 * retires after the last run.
 */
#[AsCommand(
    name: 'datasync:clear-stale-specials',
    description: 'Clear special prices this install still holds that the same SKU on live has cleared (with rollback backup)',
)]
class DatasyncClearStaleSpecials extends BaseMahoCommand
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Actually clear the special prices. Without this the command only reports what it would do (dry run).',
            )
            ->addOption(
                'live-local-xml',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to the source site app/etc/local.xml used to read the source (read-only) database credentials. Required.',
                null,
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initMaho();

        $apply    = (bool) $input->getOption('apply');
        $localXml = (string) $input->getOption('live-local-xml');
        $mode     = $apply ? 'APPLY' : 'DRY RUN';
        $output->writeln("<info>Clear stale specials — {$mode}</info>");

        if ($localXml === '' || !is_file($localXml)) {
            $output->writeln('<error>Source local.xml not found; pass --live-local-xml=/path/to/app/etc/local.xml</error>');
            return self::FAILURE;
        }
        $xml  = simplexml_load_file($localXml);
        $conn = $xml->global->resources->default_setup->connection;
        try {
            $live = new PDO(
                "mysql:host={$conn->host};dbname={$conn->dbname}",
                (string) $conn->username,
                (string) $conn->password,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            );
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not connect to live DB: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }

        $write = Mage::getSingleton('core/resource')->getConnection('core_write');

        $attrId = (int) $write->fetchOne(
            "SELECT a.attribute_id FROM eav_attribute a
             JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id
             WHERE t.entity_type_code = 'catalog_product' AND a.attribute_code = 'special_price'",
        );

        // Every non-null special_price row on this install (any store scope).
        $devRows = $write->fetchAll(
            'SELECT d.value_id, d.entity_id, d.store_id, d.value AS special, pe.sku
             FROM catalog_product_entity_decimal d
             JOIN catalog_product_entity pe ON pe.entity_id = d.entity_id
             WHERE d.attribute_id = ? AND d.value IS NOT NULL',
            [$attrId],
        );
        if ($devRows === []) {
            $output->writeln('No special_price values set on this install. Nothing to do.');
            return self::SUCCESS;
        }

        // Live special_price (store 0) for those SKUs.
        $liveAttrId = (int) $live->query(
            "SELECT a.attribute_id FROM eav_attribute a
             JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id
             WHERE t.entity_type_code = 'catalog_product' AND a.attribute_code = 'special_price'",
        )->fetchColumn();

        $skus = array_values(array_unique(array_column($devRows, 'sku')));
        $liveSpecial = [];   // sku => value|null ; absent key = not on live
        foreach (array_chunk($skus, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $live->prepare(
                "SELECT pe.sku, d.value
                 FROM catalog_product_entity pe
                 LEFT JOIN catalog_product_entity_decimal d
                   ON d.entity_id = pe.entity_id AND d.attribute_id = {$liveAttrId} AND d.store_id = 0
                 WHERE pe.sku IN ({$ph})",
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $r) {
                $liveSpecial[(string) $r['sku']] = $r['value'];   // null when live has no special
            }
        }

        // Stale = SKU exists on live AND live special is NULL.
        $stale = [];
        foreach ($devRows as $r) {
            if (!array_key_exists($r['sku'], $liveSpecial)) {
                continue;                       // not on live — leave it
            }
            if ($liveSpecial[$r['sku']] === null) {
                $stale[] = $r;
            }
        }

        $skuCount = count(array_unique(array_column($stale, 'sku')));
        $output->writeln('Stale special rows: ' . count($stale) . " (across {$skuCount} SKUs)");
        if ($stale === []) {
            $output->writeln('<info>Nothing to clear — this install is in sync with live.</info>');
            return self::SUCCESS;
        }

        // Rollback backup — always written, even on a dry run.
        $ts     = date('Ymd-His');
        $backup = Mage::getBaseDir('var') . "/specials-backup-{$ts}.sql";
        $fh     = fopen($backup, 'w');
        fwrite($fh, "-- Rollback for datasync:clear-stale-specials ({$ts}). attribute_id={$attrId}\n");
        foreach ($stale as $r) {
            fwrite($fh, sprintf(
                "INSERT INTO catalog_product_entity_decimal (attribute_id,store_id,entity_id,value) VALUES (%d,%d,%d,%s) ON DUPLICATE KEY UPDATE value=VALUES(value); -- %s\n",
                $attrId,
                (int) $r['store_id'],
                (int) $r['entity_id'],
                $write->quote($r['special']),
                $r['sku'],
            ));
        }
        fclose($fh);
        $output->writeln("Rollback written: {$backup}");

        if (!$apply) {
            foreach (array_slice($stale, 0, 50) as $r) {
                $output->writeln("  {$r['sku']} store{$r['store_id']} special={$r['special']} -> live NULL");
            }
            if (count($stale) > 50) {
                $output->writeln('  ... (' . (count($stale) - 50) . ' more)');
            }
            $output->writeln('<comment>DRY RUN — re-run with --apply to clear.</comment>');
            return self::SUCCESS;
        }

        $valueIds = array_map('intval', array_column($stale, 'value_id'));
        foreach (array_chunk($valueIds, 500) as $chunk) {
            $write->delete('catalog_product_entity_decimal', $write->quoteInto('value_id IN (?)', $chunk));
        }
        $output->writeln('<info>Cleared ' . count($valueIds) . ' special_price row(s).</info>');

        // Reindex price for the affected products so the storefront updates.
        $entityIds = array_values(array_unique(array_map('intval', array_column($stale, 'entity_id'))));
        try {
            Mage::getResourceSingleton('catalog/product_indexer_price')->reindexProductIds($entityIds);
            $output->writeln('Reindexed price for ' . count($entityIds) . ' product(s).');
        } catch (\Throwable $e) {
            $output->writeln('<comment>Price reindex failed (' . $e->getMessage() . ') — run index:reindex catalog_product_price manually.</comment>');
        }

        return self::SUCCESS;
    }
}
