<?php

declare(strict_types=1);

namespace Cleat\Tests\Support;

use PDO;

final class TestDatabase
{
    private static ?PDO $pdo = null;

    public static function connect(bool $createDatabase = false): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $host = getenv('CLEAT_TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('CLEAT_TEST_DB_PORT') ?: '3306';
        $name = getenv('CLEAT_TEST_DB_NAME') ?: 'cleat_test';
        $user = getenv('CLEAT_TEST_DB_USER') ?: 'root';
        $pass = getenv('CLEAT_TEST_DB_PASS') ?: '';

        if ($createDatabase) {
            $server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        self::$pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        // MySQL 8's default sql_mode, so tests catch anything MySQL 8 would reject.
        self::$pdo->exec("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        self::$pdo->exec("SET SESSION time_zone = '+00:00'");
        return self::$pdo;
    }

    /** @return array{host: string, port: string, name: string, user: string, pass: string} */
    public static function settings(): array
    {
        return [
            'host' => getenv('CLEAT_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('CLEAT_TEST_DB_PORT') ?: '3306',
            'name' => getenv('CLEAT_TEST_DB_NAME') ?: 'cleat_test',
            'user' => getenv('CLEAT_TEST_DB_USER') ?: 'root',
            'pass' => getenv('CLEAT_TEST_DB_PASS') ?: '',
        ];
    }

    public static function truncate(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'cleat_payouts', 'cleat_transfers', 'cleat_refunds', 'cleat_charges', 'cleat_invoice_items',
            'cleat_invoices', 'cleat_payment_links', 'cleat_subscriptions', 'cleat_connected_accounts',
            'cleat_prices', 'cleat_products', 'cleat_customers', 'cleat_webhook_events', 'cleat_rate_limits',
        ] as $table) {
            $pdo->exec("DELETE FROM $table");
        }
        $pdo->exec("UPDATE cleat_sequences SET value = 0 WHERE name = 'invoice'");
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
