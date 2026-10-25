<?php

declare(strict_types=1);

namespace Cleat\Tests\Feature;

use Cleat\Support\Schema;
use Cleat\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** Self-check 2 (MariaDB half): the migration runs cleanly, twice, on an empty database in strict mode. */
final class MigrationTest extends TestCase
{
    private const DB = 'cleat_migration_check';

    public function test_migration_runs_cleanly_and_is_idempotent(): void
    {
        $s = TestDatabase::settings();
        $server = new PDO("mysql:host={$s['host']};port={$s['port']};charset=utf8mb4", $s['user'], $s['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec('DROP DATABASE IF EXISTS ' . self::DB);
        $server->exec('CREATE DATABASE ' . self::DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $pdo = new PDO("mysql:host={$s['host']};port={$s['port']};dbname=" . self::DB . ';charset=utf8mb4', $s['user'], $s['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

            $this->assertSame(['001_create_cleat_tables.sql', '002_stored_credentials.sql', '003_period_end_changes.sql'], Schema::migrate($pdo));
            Schema::migrate($pdo); // again: IF NOT EXISTS / INSERT IGNORE / the column checks make it a no-op
            $this->assertSame([
                ['cleat_charges', 'network_transaction_id', 'varchar(255)', 'YES'],
                ['cleat_customers', 'original_network_transaction_id', 'varchar(255)', 'YES'],
            ], $this->networkColumns($pdo));
            Schema::assertMigrated($pdo);

            $tables = $pdo->query("SELECT table_name, engine, table_collation FROM information_schema.tables WHERE table_schema = '" . self::DB . "' ORDER BY table_name")->fetchAll(PDO::FETCH_NUM);
            $this->assertCount(15, $tables);
            foreach ($tables as [$name, $engine, $collation]) {
                $this->assertSame('InnoDB', $engine, $name);
                $this->assertStringStartsWith('utf8mb4', $collation, $name);
            }
            $fks = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = '" . self::DB . "'")->fetchColumn();
            $this->assertSame(20, $fks);
            $this->assertSame('0', (string) $pdo->query("SELECT value FROM cleat_sequences WHERE name = 'invoice'")->fetchColumn());

            $money = $pdo->query("SELECT table_name, column_name, data_type FROM information_schema.columns WHERE table_schema = '" . self::DB . "'
                AND column_name IN ('amount','subtotal','tax','total','amount_paid','unit_amount','application_fee_amount')")->fetchAll(PDO::FETCH_NUM);
            $this->assertNotEmpty($money);
            foreach ($money as [$table, $column, $type]) {
                $this->assertSame('bigint', strtolower($type), "$table.$column is integer cents");
            }
            $timestamps = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '" . self::DB . "' AND data_type = 'timestamp'")->fetchColumn();
            $this->assertSame('0', (string) $timestamps, 'DATETIME only, so the session time zone cannot shift stored UTC values');

            // Foreign keys are enforced.
            $this->expectException(\PDOException::class);
            $pdo->exec("INSERT INTO cleat_prices (product_id, lookup_key, amount, billing_type, created_at) VALUES (999, 'orphan', 100, 'one_time', NOW())");
        } finally {
            $server->exec('DROP DATABASE IF EXISTS ' . self::DB);
        }
    }

    /** Migration 002 on a live 0.1.1 database: additive, rows untouched, safe to run twice. */
    public function test_002_upgrades_a_populated_0_1_1_database(): void
    {
        $s = TestDatabase::settings();
        $server = new PDO("mysql:host={$s['host']};port={$s['port']};charset=utf8mb4", $s['user'], $s['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec('DROP DATABASE IF EXISTS ' . self::DB);
        $server->exec('CREATE DATABASE ' . self::DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $pdo = new PDO("mysql:host={$s['host']};port={$s['port']};dbname=" . self::DB . ';charset=utf8mb4', $s['user'], $s['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

            // A 0.1.1 database: only migration 001, with a customer, an invoice and a charge.
            foreach (Schema::statements((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/001_create_cleat_tables.sql')) as $sql) {
                $pdo->exec($sql);
            }
            $pdo->exec("INSERT INTO cleat_customers (email, gateway_customer_id, gateway_payment_id, card_brand, card_last_four, card_exp, created_at, updated_at)
                VALUES ('old@example.com', '900', '800', 'Visa', '4242', '2030-12', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
            $pdo->exec("INSERT INTO cleat_invoices (number, public_token, customer_id, status, currency, total, due_at, created_at, updated_at)
                VALUES ('CLT-000001', REPEAT('a', 64), 1, 'paid', 'USD', 2900, '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
            $pdo->exec("INSERT INTO cleat_charges (invoice_id, customer_id, amount, status, source, gateway_transaction_id, idempotency_key, created_at, updated_at)
                VALUES (1, 1, 2900, 'succeeded', 'stored_profile', '60000000001', 'inv_1_attempt_1', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
            $before = $this->snapshot($pdo);
            $this->assertSame([], $this->networkColumns($pdo));
            try {
                Schema::assertMigrated($pdo);
                $this->fail('assertMigrated() must refuse a database without migration 002');
            } catch (\Cleat\Exceptions\CleatException $e) {
                $this->assertStringContainsString('002_stored_credentials.sql', $e->getMessage());
            }

            $this->assertSame(['001_create_cleat_tables.sql', '002_stored_credentials.sql', '003_period_end_changes.sql'], Schema::migrate($pdo));
            Schema::migrate($pdo);

            $this->assertCount(2, $this->networkColumns($pdo));
            $after = $this->snapshot($pdo);
            foreach ($before as $table => $rows) {
                foreach ($rows as $i => $row) {
                    $this->assertSame($row, array_intersect_key($after[$table][$i], $row), "$table row $i unchanged");
                }
            }
            $this->assertNull($after['cleat_customers'][0]['original_network_transaction_id']);
            $this->assertNull($after['cleat_charges'][0]['network_transaction_id']);
            Schema::assertMigrated($pdo);
        } finally {
            $server->exec('DROP DATABASE IF EXISTS ' . self::DB);
        }
    }

    /** Migration 003 on a live 0.1.2 database: two nullable columns, rows untouched, safe to run twice. */
    public function test_003_upgrades_a_populated_0_1_2_database(): void
    {
        $s = TestDatabase::settings();
        $server = new PDO("mysql:host={$s['host']};port={$s['port']};charset=utf8mb4", $s['user'], $s['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec('DROP DATABASE IF EXISTS ' . self::DB);
        $server->exec('CREATE DATABASE ' . self::DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $pdo = new PDO("mysql:host={$s['host']};port={$s['port']};dbname=" . self::DB . ';charset=utf8mb4', $s['user'], $s['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

            // A 0.1.2 database: migrations 001 and 002, with a trialing subscription.
            foreach (['001_create_cleat_tables.sql', '002_stored_credentials.sql'] as $file) {
                foreach (Schema::statements((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $file)) as $sql) {
                    $pdo->exec($sql);
                }
            }
            $pdo->exec("INSERT INTO cleat_customers (email, created_at, updated_at) VALUES ('old@example.com', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
            $pdo->exec("INSERT INTO cleat_products (name, created_at, updated_at) VALUES ('Plan', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
            $pdo->exec("INSERT INTO cleat_prices (product_id, lookup_key, amount, billing_type, billing_interval, created_at)
                VALUES (1, 'plan-monthly', 1200, 'recurring', 'month', '2026-01-01 00:00:00')");
            $pdo->exec("INSERT INTO cleat_subscriptions (customer_id, price_id, status, anchor_day, trial_ends_at, current_period_start, current_period_end, created_at, updated_at)
                VALUES (1, 1, 'trialing', 1, '2026-01-15 00:00:00', '2026-01-01 00:00:00', '2026-01-15 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
            $before = $pdo->query('SELECT * FROM cleat_subscriptions')->fetchAll(PDO::FETCH_ASSOC);
            try {
                Schema::assertMigrated($pdo);
                $this->fail('assertMigrated() must refuse a database without migration 003');
            } catch (\Cleat\Exceptions\CleatException $e) {
                $this->assertStringContainsString('003_period_end_changes.sql', $e->getMessage());
            }

            Schema::migrate($pdo);
            Schema::migrate($pdo);

            $columns = $pdo->query("SELECT column_name, LOWER(column_type), is_nullable FROM information_schema.columns
                WHERE table_schema = '" . self::DB . "' AND table_name = 'cleat_subscriptions'
                AND column_name IN ('scheduled_price_id', 'trial_ending_notified_at') ORDER BY column_name")->fetchAll(PDO::FETCH_NUM);
            $this->assertSame([['scheduled_price_id', 'bigint(20) unsigned', 'YES'], ['trial_ending_notified_at', 'datetime', 'YES']], array_map(
                static fn (array $c) => [$c[0], str_replace('bigint unsigned', 'bigint(20) unsigned', $c[1]), $c[2]],
                $columns,
            ));
            $after = $pdo->query('SELECT * FROM cleat_subscriptions')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame($before[0], array_intersect_key($after[0], $before[0]), 'existing row unchanged');
            $this->assertNull($after[0]['scheduled_price_id']);
            $this->assertNull($after[0]['trial_ending_notified_at']);
            Schema::assertMigrated($pdo);
        } finally {
            $server->exec('DROP DATABASE IF EXISTS ' . self::DB);
        }
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> */
    private function networkColumns(PDO $pdo): array
    {
        return $pdo->query("SELECT table_name, column_name, LOWER(column_type), is_nullable FROM information_schema.columns
            WHERE table_schema = '" . self::DB . "' AND column_name IN ('original_network_transaction_id', 'network_transaction_id') ORDER BY table_name")->fetchAll(PDO::FETCH_NUM);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(PDO $pdo): array
    {
        $rows = [];
        foreach (['cleat_customers', 'cleat_invoices', 'cleat_charges'] as $table) {
            $rows[$table] = $pdo->query("SELECT * FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    }

    public function test_statement_splitter_handles_inline_comments(): void
    {
        $sql = "-- header\nCREATE TABLE a (\n  id INT, -- extra\n  b INT\n);\n\nINSERT IGNORE INTO a VALUES (1, 2);\n";
        $this->assertSame(["CREATE TABLE a (\n  id INT,\n  b INT\n)", 'INSERT IGNORE INTO a VALUES (1, 2)'], Schema::statements($sql));
    }
}
