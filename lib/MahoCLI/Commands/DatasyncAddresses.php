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
    name: 'datasync:addresses',
    description: 'Backfill customer address books from live, matched by email',
)]
class DatasyncAddresses extends Command
{
    /**
     * The bulk customer migration did not carry address books, and the incremental sync
     * only touches customers that change at source - so the vast majority of customers
     * arrived with an empty address book. This backfills them.
     *
     * ID TRACING - the important part:
     *   - Customers are matched by EMAIL. The two databases are independent and their
     *     customer entity_ids do not correspond.
     *   - Addresses are INSERTED, so they get fresh dev entity_ids. Live address ids are
     *     never reused.
     *   - default_billing / default_shipping are ADDRESS ids. Copying live's values
     *     verbatim would point at the wrong address (or a non-existent one), so live's
     *     defaults are resolved to the live address, then re-pointed at the DEV id that
     *     address became. That mapping is kept per customer.
     *
     * Only customers whose dev address book is EMPTY are touched, so re-running cannot
     * duplicate addresses.
     */
    private const BATCH = 200;

    protected function configure(): void
    {
        $this
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'Live database host')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'Live database name')
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'Live database user')
            ->addOption('db-pass', null, InputOption::VALUE_REQUIRED, 'Live database password')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Only process the first N customers (testing)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Mage::app('admin');
        Mage::register('isSecureArea', true);

        $dryRun = (bool) $input->getOption('dry-run');
        $limit  = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;

        try {
            $live = $this->connectToLive($input);
        } catch (\Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $dev = $this->connectToDev();

        $output->writeln($dryRun ? '<comment>DRY RUN - no changes will be written</comment>' : 'Backfilling address books...');

        // dev customers that currently have NO addresses
        $devEmpty = [];
        $sql = 'SELECT LOWER(e.email) email, e.entity_id
                FROM customer_entity e
                LEFT JOIN customer_address_entity a ON a.parent_id = e.entity_id
                WHERE a.entity_id IS NULL';
        foreach ($dev->query($sql) as $r) {
            $devEmpty[$r['email']] = (int) $r['entity_id'];
        }

        // live customers that HAVE addresses
        $liveHas = [];
        $sql = 'SELECT LOWER(e.email) email, e.entity_id, COUNT(a.entity_id) n
                FROM customer_entity e
                JOIN customer_address_entity a ON a.parent_id = e.entity_id
                GROUP BY e.entity_id';
        foreach ($live->query($sql) as $r) {
            $liveHas[$r['email']] = ['id' => (int) $r['entity_id'], 'n' => (int) $r['n']];
        }

        $todo = [];
        foreach ($liveHas as $email => $info) {
            if (isset($devEmpty[$email])) {
                $todo[] = ['email' => $email, 'live' => $info['id'], 'dev' => $devEmpty[$email], 'n' => $info['n']];
            }
        }

        $output->writeln(sprintf('  dev customers with an empty address book : %d', count($devEmpty)));
        $output->writeln(sprintf('  of those, live has addresses for         : %d', count($todo)));
        $output->writeln(sprintf('  addresses to copy                        : %d', array_sum(array_column($todo, 'n'))));

        if ($limit !== null) {
            $todo = array_slice($todo, 0, $limit);
            $output->writeln(sprintf('  <comment>--limit: processing only %d</comment>', count($todo)));
        }
        if ($dryRun || !$todo) {
            return Command::SUCCESS;
        }

        $liveAttrs = $this->addressAttributes($live);
        $done = $copied = $failed = 0;

        foreach (array_chunk($todo, self::BATCH) as $chunk) {
            foreach ($chunk as $job) {
                try {
                    $copied += $this->copyCustomerAddresses($live, $job, $liveAttrs);
                } catch (\Exception $e) {
                    $failed++;
                    $output->writeln(sprintf('<error>  %s: %s</error>', $job['email'], $e->getMessage()));
                }
                $done++;
            }
            $output->writeln(sprintf('  %d/%d customers, %d addresses', $done, count($todo), $copied));
        }

        $output->writeln(sprintf('<info>Copied %d addresses for %d customers (%d failed).</info>', $copied, $done - $failed, $failed));
        return Command::SUCCESS;
    }

    /**
     * @return int number of addresses created
     */
    private function copyCustomerAddresses(PDO $live, array $job, array $attrs): int
    {
        $customer = Mage::getModel('customer/customer')->load($job['dev']);
        if (!$customer->getId()) {
            throw new \Exception('dev customer vanished');
        }
        // Re-check rather than trusting the snapshot: never add to a non-empty book.
        if (count($customer->getAddresses()) > 0) {
            return 0;
        }

        $rows = $this->loadLiveAddresses($live, $job['live'], $attrs);
        if (!$rows) {
            return 0;
        }

        [$defBilling, $defShipping] = $this->liveDefaults($live, $job['live']);

        $newDefaultBilling = $newDefaultShipping = null;
        $created = 0;

        foreach ($rows as $liveAddressId => $data) {
            $address = Mage::getModel('customer/address');
            foreach ($data as $code => $value) {
                $address->setData($code, $value);
            }
            $address->setId(null);
            $address->setCustomerId($customer->getId());   // dev id, never live's
            $address->setParentId($customer->getId());
            $address->save();
            $created++;

            // Re-point the defaults at the id this address was given HERE.
            if ($defBilling !== null && (int) $liveAddressId === $defBilling) {
                $newDefaultBilling = (int) $address->getId();
            }
            if ($defShipping !== null && (int) $liveAddressId === $defShipping) {
                $newDefaultShipping = (int) $address->getId();
            }
        }

        // The original migration copied default_billing / default_shipping - which are
        // LIVE address ids - without copying the addresses, so nearly every customer
        // carries a pointer to an address that does not exist here. Re-point it at the
        // dev address it became; if it cannot be resolved (the source itself is dangling),
        // clear it rather than leave the customer claiming a default that isn't there.
        $ownIds = [];
        foreach ($customer->getAddresses() as $a) {
            $ownIds[] = (int) $a->getId();
        }

        $res = $customer->getResource();
        foreach ([
            ['default_billing', 'setDefaultBilling', $newDefaultBilling, $customer->getDefaultBilling()],
            ['default_shipping', 'setDefaultShipping', $newDefaultShipping, $customer->getDefaultShipping()],
        ] as [$code, $setter, $resolved, $current]) {
            if ($resolved) {
                $customer->$setter($resolved);
                $res->saveAttribute($customer, $code);
            } elseif ($current !== null && $current !== '' && !in_array((int) $current, $ownIds, true)) {
                $customer->$setter(null);
                $res->saveAttribute($customer, $code);
            }
        }

        return $created;
    }

    /**
     * @return array<int, array<string, mixed>> live address id => [attribute_code => value]
     */
    private function loadLiveAddresses(PDO $live, int $liveCustomerId, array $attrs): array
    {
        $st = $live->prepare('SELECT entity_id, created_at FROM customer_address_entity WHERE parent_id = ?');
        $st->execute([$liveCustomerId]);
        $ids = [];
        foreach ($st as $r) {
            $ids[(int) $r['entity_id']] = ['created_at' => $r['created_at']];
        }
        if (!$ids) {
            return [];
        }

        $in = implode(',', array_map('intval', array_keys($ids)));
        foreach (['varchar', 'int', 'text', 'decimal', 'datetime'] as $backend) {
            $sql = "SELECT entity_id, attribute_id, value FROM customer_address_entity_{$backend}
                    WHERE entity_id IN ({$in})";
            foreach ($live->query($sql) as $r) {
                $code = $attrs[(int) $r['attribute_id']] ?? null;
                if ($code !== null) {
                    $ids[(int) $r['entity_id']][$code] = $r['value'];
                }
            }
        }
        return $ids;
    }

    /**
     * @return array{0: ?int, 1: ?int} live default billing / shipping ADDRESS ids
     */
    private function liveDefaults(PDO $live, int $liveCustomerId): array
    {
        $sql = "SELECT a.attribute_code, v.value
                FROM customer_entity_int v
                JOIN eav_attribute a ON a.attribute_id = v.attribute_id
                JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id
                WHERE t.entity_type_code = 'customer'
                  AND a.attribute_code IN ('default_billing','default_shipping')
                  AND v.entity_id = ?";
        $st = $live->prepare($sql);
        $st->execute([$liveCustomerId]);
        $out = ['default_billing' => null, 'default_shipping' => null];
        foreach ($st as $r) {
            $out[$r['attribute_code']] = $r['value'] === null ? null : (int) $r['value'];
        }
        return [$out['default_billing'], $out['default_shipping']];
    }

    /**
     * attribute_id => attribute_code for customer_address, resolved on the LIVE side.
     * Ids are assigned at install time and are not guaranteed to match between systems,
     * so values are mapped back to codes before being set on a dev model.
     *
     * @return array<int, string>
     */
    private function addressAttributes(PDO $pdo): array
    {
        $sql = "SELECT a.attribute_id, a.attribute_code
                FROM eav_attribute a
                JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id
                WHERE t.entity_type_code = 'customer_address'";
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $code = $r['attribute_code'];
            // Never carry identity or linkage columns across - they belong to the source DB.
            if (in_array($code, ['entity_id', 'parent_id', 'increment_id'], true)) {
                continue;
            }
            $out[(int) $r['attribute_id']] = $code;
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
