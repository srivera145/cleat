<?php

declare(strict_types=1);

namespace Cleat;

use Cleat\Enums\RefundMethod;
use Cleat\Enums\RefundStatus;
use Cleat\Exceptions\NotFoundException;
use Cleat\Support\Db;
use DateTimeImmutable;

/**
 * One refund attempt against a charge. $method says what actually happened:
 * a 'refund' of a settled transaction, or a 'void' of an unsettled one.
 */
final class Refund
{
    public function __construct(
        public readonly int $id,
        public readonly int $charge_id,
        public readonly int $amount,
        public RefundMethod $method,
        public RefundStatus $status,
        public ?string $gateway_transaction_id,
        public ?string $reason_code,
        public ?string $reason_text,
        public readonly string $idempotency_key,
        public readonly DateTimeImmutable $created_at,
        public DateTimeImmutable $updated_at,
    ) {
    }

    public static function find(int $id): ?self
    {
        $row = Db::one('SELECT * FROM cleat_refunds WHERE id = ?', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findOrFail(int $id): self
    {
        return self::find($id) ?? throw new NotFoundException("Refund $id not found.");
    }

    public static function findByTransactionId(string $transactionId): ?self
    {
        $row = Db::one('SELECT * FROM cleat_refunds WHERE gateway_transaction_id = ? ORDER BY id LIMIT 1', [$transactionId]);
        return $row === null ? null : self::fromRow($row);
    }

    /** @return list<self> */
    public static function forCharge(int $chargeId): array
    {
        return array_map([self::class, 'fromRow'], Db::all('SELECT * FROM cleat_refunds WHERE charge_id = ? ORDER BY id', [$chargeId]));
    }

    public function succeeded(): bool
    {
        return $this->status === RefundStatus::Succeeded;
    }

    public function wasVoid(): bool
    {
        return $this->method === RefundMethod::Void;
    }

    public function charge(): Charge
    {
        return Charge::findOrFail($this->charge_id);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['charge_id'],
            (int) $row['amount'],
            RefundMethod::from((string) $row['method']),
            RefundStatus::from((string) $row['status']),
            $row['gateway_transaction_id'] !== null ? (string) $row['gateway_transaction_id'] : null,
            $row['reason_code'] !== null ? (string) $row['reason_code'] : null,
            $row['reason_text'] !== null ? (string) $row['reason_text'] : null,
            (string) $row['idempotency_key'],
            Db::date($row['created_at']),
            Db::date($row['updated_at']),
        );
    }
}
