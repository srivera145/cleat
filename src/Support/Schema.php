<?php

declare(strict_types=1);

namespace Cleat\Support;

use PDO;

/**
 * Runs Cleat's .sql migrations. Most hosts run database/migrations/*.sql
 * with their own migration tool; this exists for tests and for hosts that
 * have none.
 */
final class Schema
{
    /** @return list<string> the files that were executed */
    public static function migrate(PDO $pdo): array
    {
        $ran = [];
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: [] as $file) {
            foreach (self::statements((string) file_get_contents($file)) as $sql) {
                $pdo->exec($sql);
            }
            $ran[] = basename($file);
        }
        return $ran;
    }

    /** Drops every Cleat table. Tests only. */
    public static function dropAll(PDO $pdo): void
    {
        $tables = [
            'cleat_payouts', 'cleat_transfers', 'cleat_refunds', 'cleat_charges', 'cleat_invoice_items',
            'cleat_invoices', 'cleat_payment_links', 'cleat_subscriptions', 'cleat_connected_accounts',
            'cleat_prices', 'cleat_products', 'cleat_customers', 'cleat_webhook_events',
            'cleat_rate_limits', 'cleat_sequences',
        ];
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS $table");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Splits a migration into statements. Cleat's migrations contain no
     * semicolons inside strings or routines, so splitting on ';' at the end of
     * a line is exact.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn (string $line) => !str_starts_with(ltrim($line), '--'),
        );
        $statements = [];
        foreach (preg_split('/;\s*$/m', implode("\n", $lines)) ?: [] as $chunk) {
            $chunk = trim(preg_replace('/\s+--[^\n]*$/m', '', $chunk) ?? '');
            if ($chunk !== '') {
                $statements[] = $chunk;
            }
        }
        return $statements;
    }
}
