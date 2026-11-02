<?php

/**
 * Maho
 *
 * @package    MahoCLI
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoCLI\Commands;

use Mage;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'datasync:passwords',
    description: 'Sync customer password hashes from live, matched by email',
)]
class DatasyncPasswords extends Command
{
    /**
     * Customers are matched by EMAIL, never by entity_id: the two databases are
     * independent and their customer ids do not correspond.
     *
     * Password hashes are portable between the two systems because
     * Mage_Core_Model_Encryption::validateHash() tries every historical hash version
     * (bcrypt -> SHA512 -> SHA256 -> MD5) before failing, so a hash written by the old
     * platform still authenticates here. Nothing is re-hashed; the stored value is
     * copied verbatim and re-hashing happens naturally on the customer's next login.
     */
    private const BATCH_SIZE = 500;

    protected function configure(): void
    {
        $this
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'Live database host')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'Live database name')
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'Live database user')
            ->addOption('db-pass', null, InputOption::VALUE_REQUIRED, 'Live database password')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows per batch', (string) self::BATCH_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Mage::app('admin');

        $dryRun    = (bool) $input->getOption('dry-run');
        $batchSize = max(1, (int) $input->getOption('batch-size'));

        try {
            $live = $this->connectToLive($input);
        } catch (\Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $dev = $this->connectToDev();

        // Attribute ids are resolved separately per database: they are assigned at
        // install time and are not guaranteed to match between the two systems.
        $liveAttr = $this->resolveAttributes($live);
        $devAttr  = $this->resolveAttributes($dev);
        if (!$liveAttr || !$devAttr) {
            $output->writeln('<error>Could not resolve customer password attributes.</error>');
            return Command::FAILURE;
        }

        $output->writeln($dryRun ? '<comment>DRY RUN - no changes will be written</comment>' : 'Syncing password hashes...');

        $liveRows = $this->fetchLive($live, $liveAttr);
        $devRows  = $this->fetchDev($dev, $devAttr);
        $output->writeln(sprintf('  live: %d with a hash, dev: %d with a hash', count($liveRows), count($devRows)));

        $pending    = [];
        $identical  = 0;
        $rejected   = 0;
        foreach ($devRows as $email => $devRow) {
            if (!isset($liveRows[$email])) {
                continue;
            }
            $liveRow = $liveRows[$email];

            // Only copy hashes in a format this platform can actually validate. This
            // silently excludes anything a non-Magento-lineage source might produce,
            // which would otherwise lock the customer out of their account.
            if (!$this->isSupportedHash($liveRow['hash'])) {
                $rejected++;
                continue;
            }
            if (hash_equals($liveRow['hash'], $devRow['hash'])) {
                $identical++;
                continue;
            }
            $pending[] = [
                'entity_id'  => $devRow['entity_id'],
                'hash'       => $liveRow['hash'],
                'created_at' => $liveRow['created_at'],
            ];
        }

        $liveOnly = count(array_diff_key($liveRows, $devRows));
        $devOnly  = count(array_diff_key($devRows, $liveRows));

        $output->writeln(sprintf('  identical (skipped)   : %d', $identical));
        $output->writeln(sprintf('  to update             : %d', count($pending)));
        if ($rejected) {
            $output->writeln(sprintf('  <comment>unsupported hash format: %d (skipped)</comment>', $rejected));
        }
        if ($liveOnly) {
            $output->writeln(sprintf('  <comment>on live but missing from dev: %d (run a customer sync)</comment>', $liveOnly));
        }
        if ($devOnly) {
            $output->writeln(sprintf('  dev-only customers    : %d (left untouched)', $devOnly));
        }

        if ($dryRun || !$pending) {
            return Command::SUCCESS;
        }

        $written = 0;
        foreach (array_chunk($pending, $batchSize) as $chunk) {
            $dev->beginTransaction();
            try {
                $this->writeChunk($dev, $devAttr, $chunk);
                $dev->commit();
            } catch (\Exception $e) {
                $dev->rollBack();
                $output->writeln('<error>Batch failed, rolled back: ' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }
            $written += count($chunk);
            $output->writeln(sprintf('  %d/%d', $written, count($pending)));
        }

        $output->writeln(sprintf('<info>Updated %d customer password hashes.</info>', $written));
        return Command::SUCCESS;
    }

    private function writeChunk(PDO $dev, array $attr, array $chunk): void
    {
        $hashSql = 'INSERT INTO customer_entity_varchar (entity_type_id, attribute_id, entity_id, value)
                    VALUES (:etype, :attr, :eid, :val)
                    ON DUPLICATE KEY UPDATE value = VALUES(value)';
        $timeSql = 'INSERT INTO customer_entity_int (entity_type_id, attribute_id, entity_id, value)
                    VALUES (:etype, :attr, :eid, :val)
                    ON DUPLICATE KEY UPDATE value = VALUES(value)';
        $hashStmt = $dev->prepare($hashSql);
        $timeStmt = $dev->prepare($timeSql);

        foreach ($chunk as $row) {
            $hashStmt->execute([
                ':etype' => $attr['entity_type_id'],
                ':attr'  => $attr['password_hash'],
                ':eid'   => $row['entity_id'],
                ':val'   => $row['hash'],
            ]);
            // password_created_at drives session invalidation. Carrying the live value
            // keeps "sessions issued before the password changed" meaningful; without it
            // a synced customer would keep older sessions alive.
            if ($row['created_at'] !== null && $attr['password_created_at']) {
                $timeStmt->execute([
                    ':etype' => $attr['entity_type_id'],
                    ':attr'  => $attr['password_created_at'],
                    ':eid'   => $row['entity_id'],
                    ':val'   => (int) $row['created_at'],
                ]);
            }
        }
    }

    /**
     * Accept only hash shapes Mage_Core_Model_Encryption can validate:
     * bcrypt, or "<hex digest>:<salt>", or a bare hex digest.
     */
    private function isSupportedHash(string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$')) {
            return true;
        }
        $head = explode(':', $hash, 2)[0];
        return in_array(strlen($head), [32, 64, 128], true) && ctype_xdigit($head);
    }

    private function resolveAttributes(PDO $pdo): array
    {
        $sql = "SELECT a.attribute_code, a.attribute_id, t.entity_type_id
                FROM eav_attribute a
                JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id
                WHERE t.entity_type_code = 'customer'
                  AND a.attribute_code IN ('password_hash', 'password_created_at')";
        $out = ['password_hash' => null, 'password_created_at' => null, 'entity_type_id' => null];
        foreach ($pdo->query($sql) as $row) {
            $out[$row['attribute_code']] = (int) $row['attribute_id'];
            $out['entity_type_id'] = (int) $row['entity_type_id'];
        }
        return $out['password_hash'] ? $out : [];
    }

    private function fetchLive(PDO $pdo, array $attr): array
    {
        $sql = "SELECT LOWER(e.email) AS email, v.value AS hash, i.value AS created_at
                FROM customer_entity e
                JOIN customer_entity_varchar v
                  ON v.entity_id = e.entity_id AND v.attribute_id = {$attr['password_hash']}
                LEFT JOIN customer_entity_int i
                  ON i.entity_id = e.entity_id AND i.attribute_id = " . (int) $attr['password_created_at'] . "
                WHERE v.value IS NOT NULL AND v.value != ''";
        $out = [];
        foreach ($pdo->query($sql) as $row) {
            $out[$row['email']] = ['hash' => $row['hash'], 'created_at' => $row['created_at']];
        }
        return $out;
    }

    private function fetchDev(PDO $pdo, array $attr): array
    {
        $sql = "SELECT LOWER(e.email) AS email, e.entity_id, v.value AS hash
                FROM customer_entity e
                JOIN customer_entity_varchar v
                  ON v.entity_id = e.entity_id AND v.attribute_id = {$attr['password_hash']}
                WHERE v.value IS NOT NULL AND v.value != ''";
        $out = [];
        foreach ($pdo->query($sql) as $row) {
            $out[$row['email']] = ['entity_id' => (int) $row['entity_id'], 'hash' => $row['hash']];
        }
        return $out;
    }

    private function connectToDev(): PDO
    {
        $cfg = Mage::getConfig()->getResourceConnectionConfig('default_setup');
        return new PDO(
            "mysql:host={$cfg->host};dbname={$cfg->dbname};charset=utf8mb4",
            (string) $cfg->username,
            (string) $cfg->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    private function connectToLive(InputInterface $input): PDO
    {
        $host = $input->getOption('db-host');
        $name = $input->getOption('db-name');
        $user = $input->getOption('db-user');
        $pass = $input->getOption('db-pass');

        if (!$name || !$user) {
            $envFile = Mage::getBaseDir() . '/.env.local';
            if (file_exists($envFile)) {
                $env = [];
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$k, $v] = explode('=', $line, 2);
                    $env[trim($k)] = trim($v);
                }
                $host = $host ?: ($env['DATASYNC_LIVE_HOST'] ?? 'localhost');
                $name = $name ?: ($env['DATASYNC_LIVE_DB'] ?? null);
                $user = $user ?: ($env['DATASYNC_LIVE_USER'] ?? null);
                $pass = $pass ?: ($env['DATASYNC_LIVE_PASS'] ?? null);
            }
        }

        if (!$name || !$user) {
            throw new \Exception('Database credentials not provided. Use --db-* options or set DATASYNC_LIVE_* in .env.local');
        }

        return new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 10,
            ],
        );
    }
}
