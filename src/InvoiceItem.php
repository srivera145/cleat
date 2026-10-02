<?php

declare(strict_types=1);

namespace Cleat;

use Cleat\Support\Db;
use DateTimeImmutable;

/**
 * One line on an invoice. Every property is readonly and Cleat has no
 * method that edits or deletes a line: lines are added while the invoice is
 * a draft and are frozen once it is finalized.
 *
 * A line with invoice_id null is a pending item (a proration credit waiting
 * for the subscription's next renewal invoice). Attaching it to that invoice
 * is the only change a line ever sees.
 */
final class InvoiceItem
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $invoice_id,
        public readonly ?int $subscription_id,
        public readonly ?int $price_id,
        public readonly string $description,
        public readonly int $quantity,
        public readonly int $unit_amount,
        public readonly int $amount,
        public readonly bool $is_proration,
        public readonly ?DateTimeImmutable $period_start,
        public readonly ?DateTimeImmutable $period_end,
        public readonly DateTimeImmutable $created_at,
    ) {
    }

    /**
     * @internal Use InvoiceBuilder, or let swap()/renewals create lines.
     * @param array{
     *     invoice_id?: ?int, subscription_id?: ?int, price_id?: ?int, description: string,
     *     quantity?: int, unit_amount: int, is_proration?: bool,
     *     period_start?: ?DateTimeImmutable, period_end?: ?DateTimeImmutable
     * } $attributes
     */
    public static function create(array $attributes): self
    {
        $quantity = (int) ($attributes['quantity'] ?? 1);
        $unit = (int) $attributes['unit_amount'];
        $id = Db::insert('cleat_invoice_items', [
            'invoice_id' => $attributes['invoice_id'] ?? null,
            'subscription_id' => $attributes['subscription_id'] ?? null,
            'price_id' => $attributes['price_id'] ?? null,
            'description' => mb_substr(trim($attributes['description']), 0, 500),
            'quantity' => $quantity,
            'unit_amount' => $unit,
            'amount' => Money::of($unit)->multiply($quantity)->amount,
            'is_proration' => (bool) ($attributes['is_proration'] ?? false),
            'period_start' => $attributes['period_start'] ?? null,
            'period_end' => $attributes['period_end'] ?? null,
            'created_at' => Cleat::now(),
        ]);
        return self::fromRow(Db::one('SELECT * FROM cleat_invoice_items WHERE id = ?', [$id]));
    }

    /** @return list<self> */
    public static function forInvoice(int $invoiceId): array
    {
        return array_map(
            [self::class, 'fromRow'],
            Db::all('SELECT * FROM cleat_invoice_items WHERE invoice_id = ? ORDER BY id', [$invoiceId]),
        );
    }

    /** @return list<self> pending items waiting for this subscription's next invoice */
    public static function pendingFor(int $subscriptionId): array
    {
        return array_map(
            [self::class, 'fromRow'],
            Db::all('SELECT * FROM cleat_invoice_items WHERE subscription_id = ? AND invoice_id IS NULL ORDER BY id', [$subscriptionId]),
        );
    }

    public function money(string $currency): Money
    {
        return Money::of($this->amount, $currency);
    }

    public function unitMoney(string $currency): Money
    {
        return Money::of($this->unit_amount, $currency);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            $row['invoice_id'] !== null ? (int) $row['invoice_id'] : null,
            $row['subscription_id'] !== null ? (int) $row['subscription_id'] : null,
            $row['price_id'] !== null ? (int) $row['price_id'] : null,
            (string) $row['description'],
            (int) $row['quantity'],
            (int) $row['unit_amount'],
            (int) $row['amount'],
            (bool) $row['is_proration'],
            Db::date($row['period_start']),
            Db::date($row['period_end']),
            Db::date($row['created_at']),
        );
    }
}
