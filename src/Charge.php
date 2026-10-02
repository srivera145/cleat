<?php

declare(strict_types=1);

namespace Cleat;

use Cleat\Enums\ChargeSource;
use Cleat\Enums\ChargeStatus;
use Cleat\Enums\RefundMethod;
use Cleat\Enums\RefundStatus;
use Cleat\Events\ChargeRefunded;
use Cleat\Exceptions\ChargeInProgressException;
use Cleat\Exceptions\CleatException;
use Cleat\Exceptions\NotFoundException;
use Cleat\Exceptions\RefundFailedException;
use Cleat\Gateway\GatewayResult;
use Cleat\Support\Db;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One attempt to collect money for an invoice. A row is written as
 * 'pending' (with its idempotency key) before the gateway is called, then
 * updated with the outcome.
 */
final class Charge
{
    /** @param array<string, mixed>|null $raw_response */
    public function __construct(
        public readonly int $id,
        public readonly int $invoice_id,
        public readonly int $customer_id,
        public readonly int $amount,
        public ChargeStatus $status,
        public readonly ChargeSource $source,
        public ?string $gateway_transaction_id,
        public ?int $response_code,
        public ?string $reason_code,
        public ?string $reason_text,
        public readonly string $idempotency_key,
        public readonly ?string $ip_address,
        public readonly ?int $connected_account_id,
        public readonly ?int $application_fee_amount,
        public ?array $raw_response,
        public readonly DateTimeImmutable $created_at,
        public DateTimeImmutable $updated_at,
    ) {
    }

    public static function find(int $id): ?self
    {
        $row = Db::one('SELECT * FROM cleat_charges WHERE id = ?', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findOrFail(int $id): self
    {
        return self::find($id) ?? throw new NotFoundException("Charge $id not found.");
    }

    public static function findByTransactionId(string $transactionId): ?self
    {
        $row = Db::one('SELECT * FROM cleat_charges WHERE gateway_transaction_id = ? ORDER BY id LIMIT 1', [$transactionId]);
        return $row === null ? null : self::fromRow($row);
    }

    /** @return list<self> */
    public static function forInvoice(int $invoiceId): array
    {
        return array_map([self::class, 'fromRow'], Db::all('SELECT * FROM cleat_charges WHERE invoice_id = ? ORDER BY id', [$invoiceId]));
    }

    public function invoice(): Invoice
    {
        return Invoice::findOrFail($this->invoice_id);
    }

    public function customer(): Customer
    {
        return Customer::findOrFail($this->customer_id);
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->invoice()->currency);
    }

    public function succeeded(): bool
    {
        return $this->status === ChargeStatus::Succeeded;
    }

    /** Sum of succeeded refunds and voids. */
    public function refundedAmount(): int
    {
        return (int) Db::value("SELECT COALESCE(SUM(amount), 0) FROM cleat_refunds WHERE charge_id = ? AND status = 'succeeded'", [$this->id]);
    }

    /**
     * Refund all of the charge, or part of it.
     *
     * Authorize.net only refunds settled transactions. When the transaction
     * has not settled yet (error 54) and the refund is for the full amount,
     * Cleat voids it instead. The returned Refund's $method says which
     * happened. A partial refund of an unsettled transaction cannot be voided
     * and fails until settlement.
     *
     * @throws RefundFailedException
     */
    public function refund(?int $amountCents = null): Refund
    {
        if (Db::inTransaction()) {
            throw new CleatException('Refunds call the gateway and must not run inside an open database transaction.');
        }

        // Phase 1: claim. Lock the charge, validate, write a pending refund row.
        $refund = Db::transaction(function () use ($amountCents): Refund {
            $row = Db::one('SELECT * FROM cleat_charges WHERE id = ? FOR UPDATE', [$this->id]);
            $this->sync($row);
            if ($this->status !== ChargeStatus::Succeeded) {
                throw new CleatException(sprintf('Only succeeded charges can be refunded; charge %d is %s.', $this->id, $this->status->value));
            }
            if ($this->gateway_transaction_id === null) {
                throw new CleatException(sprintf('Charge %d has no gateway transaction id to refund.', $this->id));
            }
            if (Db::value("SELECT id FROM cleat_refunds WHERE charge_id = ? AND status = 'pending' LIMIT 1", [$this->id]) !== null) {
                throw new ChargeInProgressException(sprintf('Charge %d already has a refund in progress.', $this->id));
            }
            $refundable = $this->amount - $this->refundedAmount();
            $amount = $amountCents ?? $refundable;
            if ($amount <= 0 || $amount > $refundable) {
                throw new InvalidArgumentException(sprintf('Refund amount must be between 1 and %d cents.', $refundable));
            }
            $n = 1 + (int) Db::value('SELECT COUNT(*) FROM cleat_refunds WHERE charge_id = ?', [$this->id]);
            $now = Cleat::now();
            $id = Db::insert('cleat_refunds', [
                'charge_id' => $this->id,
                'amount' => $amount,
                'method' => RefundMethod::Refund,
                'status' => RefundStatus::Pending,
                'idempotency_key' => sprintf('ch_%d_refund_%d', $this->id, $n),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return Refund::findOrFail($id);
        });

        // Phase 2: gateway, outside any transaction.
        $gateway = Cleat::gateway();
        $method = RefundMethod::Refund;
        $lastFour = $this->cardLastFour();
        $result = $lastFour !== null
            ? $gateway->refund($this->gateway_transaction_id, $refund->amount, $lastFour, $refund->idempotency_key)
            : GatewayResult::error('no_card', 'The card number on the original transaction could not be determined.');

        $fullAndFirst = $refund->amount === $this->amount && $this->refundedAmount() === 0;
        if (!$result->success && $result->reasonCode === '54' && $fullAndFirst) {
            $method = RefundMethod::Void;
            $result = $gateway->void($this->gateway_transaction_id, $refund->idempotency_key);
        }

        // Phase 3: record.
        $succeeded = $result->success;
        Db::transaction(function () use ($refund, $result, $method, $succeeded): void {
            Db::run('SELECT id FROM cleat_charges WHERE id = ? FOR UPDATE', [$this->id]);
            Db::update('cleat_refunds', [
                'method' => $method,
                'status' => $succeeded ? RefundStatus::Succeeded : RefundStatus::Failed,
                'gateway_transaction_id' => $method === RefundMethod::Void ? $this->gateway_transaction_id : $result->transactionId,
                'reason_code' => $result->reasonCode,
                'reason_text' => $result->reasonText !== null ? mb_substr($result->reasonText, 0, 500) : null,
                'raw_response' => $result->raw,
                'updated_at' => Cleat::now(),
            ], ['id' => $refund->id]);
            if ($succeeded) {
                $status = match (true) {
                    $method === RefundMethod::Void => ChargeStatus::Voided,
                    $this->refundedAmount() >= $this->amount => ChargeStatus::Refunded,
                    default => ChargeStatus::Succeeded,
                };
                Db::update('cleat_charges', ['status' => $status, 'updated_at' => Cleat::now()], ['id' => $this->id]);
                $this->status = $status;
            }
        });

        $refund = Refund::findOrFail($refund->id);
        if (!$succeeded) {
            $message = $result->reasonCode === '54'
                ? 'The transaction has not settled yet. Only a full refund can be done now (as a void); partial refunds work after settlement.'
                : sprintf('Refund failed: %s (%s).', $result->reasonText ?? 'unknown error', $result->reasonCode ?? 'n/a');
            throw new RefundFailedException($message, $refund, $result);
        }
        Cleat::events()->dispatch(new ChargeRefunded($this, $refund));
        return $refund;
    }

    /**
     * @internal Insert the pending row. Called inside the invoice's claim transaction.
     * @param array{invoice_id: int, customer_id: int, amount: int, source: ChargeSource, idempotency_key: string, ip_address: ?string, connected_account_id?: ?int, application_fee_amount?: ?int} $attributes
     */
    public static function insertPending(array $attributes): self
    {
        $now = Cleat::now();
        $id = Db::insert('cleat_charges', [
            'invoice_id' => $attributes['invoice_id'],
            'customer_id' => $attributes['customer_id'],
            'amount' => $attributes['amount'],
            'status' => ChargeStatus::Pending,
            'source' => $attributes['source'],
            'idempotency_key' => $attributes['idempotency_key'],
            'ip_address' => $attributes['ip_address'] !== null ? substr($attributes['ip_address'], 0, 45) : null,
            'connected_account_id' => $attributes['connected_account_id'] ?? null,
            'application_fee_amount' => $attributes['application_fee_amount'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return self::findOrFail($id);
    }

    /** @internal Write the gateway outcome onto the row. */
    public function applyResult(GatewayResult $result, ChargeStatus $status): void
    {
        $fields = [
            'status' => $status,
            'gateway_transaction_id' => $result->transactionId,
            'response_code' => $result->responseCode,
            'reason_code' => $result->reasonCode !== null ? mb_substr($result->reasonCode, 0, 32) : null,
            'reason_text' => $result->reasonText !== null ? mb_substr($result->reasonText, 0, 500) : null,
            'raw_response' => $result->raw === [] ? null : $result->raw,
            'updated_at' => Cleat::now(),
        ];
        Db::update('cleat_charges', $fields, ['id' => $this->id]);
        $this->status = $status;
        $this->gateway_transaction_id = $result->transactionId;
        $this->response_code = $result->responseCode;
        $this->reason_code = $fields['reason_code'];
        $this->reason_text = $fields['reason_text'];
        $this->raw_response = $result->raw === [] ? null : $result->raw;
    }

    /** @internal Settle a pending/held/timed-out charge from a webhook. */
    public function settle(ChargeStatus $status, ?string $transactionId, ?string $reasonCode = null, ?string $reasonText = null): void
    {
        $fields = ['status' => $status, 'updated_at' => Cleat::now()];
        if ($transactionId !== null) {
            $fields['gateway_transaction_id'] = $transactionId;
            $this->gateway_transaction_id = $transactionId;
        }
        if ($reasonCode !== null) {
            $fields['reason_code'] = $reasonCode;
            $fields['reason_text'] = $reasonText;
            $this->reason_code = $reasonCode;
            $this->reason_text = $reasonText;
        }
        Db::update('cleat_charges', $fields, ['id' => $this->id]);
        $this->status = $status;
    }

    /** Last four card digits for refunds: from the charge response, the gateway, then the customer. */
    public function cardLastFour(): ?string
    {
        $account = (string) ($this->raw_response['transactionResponse']['accountNumber'] ?? '');
        $digits = preg_replace('/\D/', '', $account) ?? '';
        if (strlen($digits) >= 4) {
            return substr($digits, -4);
        }
        if ($this->gateway_transaction_id !== null) {
            $details = Cleat::gateway()->getTransaction($this->gateway_transaction_id);
            $last4 = $details->get('card_last_four');
            if ($details->success && is_string($last4) && strlen($last4) === 4) {
                return $last4;
            }
        }
        return $this->customer()->card_last_four;
    }

    /** @param array<string, mixed>|null $row */
    private function sync(?array $row): void
    {
        if ($row === null) {
            throw new NotFoundException("Charge {$this->id} not found.");
        }
        $this->status = ChargeStatus::from((string) $row['status']);
        $this->gateway_transaction_id = $row['gateway_transaction_id'] !== null ? (string) $row['gateway_transaction_id'] : null;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['invoice_id'],
            (int) $row['customer_id'],
            (int) $row['amount'],
            ChargeStatus::from((string) $row['status']),
            ChargeSource::from((string) $row['source']),
            $row['gateway_transaction_id'] !== null ? (string) $row['gateway_transaction_id'] : null,
            $row['response_code'] !== null ? (int) $row['response_code'] : null,
            $row['reason_code'] !== null ? (string) $row['reason_code'] : null,
            $row['reason_text'] !== null ? (string) $row['reason_text'] : null,
            (string) $row['idempotency_key'],
            $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
            $row['connected_account_id'] !== null ? (int) $row['connected_account_id'] : null,
            $row['application_fee_amount'] !== null ? (int) $row['application_fee_amount'] : null,
            $row['raw_response'] !== null ? json_decode((string) $row['raw_response'], true) : null,
            Db::date($row['created_at']),
            Db::date($row['updated_at']),
        );
    }
}
