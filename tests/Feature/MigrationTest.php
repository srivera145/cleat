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

            $this->assertSame(['001_create_cleat_tables.sql'], Schema::migrate($pdo));
            Schema::migrate($pdo); // again: IF NOT EXISTS / INSERT IGNORE make it a no-op

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

    public function test_statement_splitter_handles_inline_comments(): void
    {
        $sql = "-- header\nCREATE TABLE a (\n  id INT, -- extra\n  b INT\n);\n\nINSERT IGNORE INTO a VALUES (1, 2);\n";
        $this->assertSame(["CREATE TABLE a (\n  id INT,\n  b INT\n)", 'INSERT IGNORE INTO a VALUES (1, 2)'], Schema::statements($sql));
    }
}
