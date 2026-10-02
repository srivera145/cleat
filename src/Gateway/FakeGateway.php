<?php

declare(strict_types=1);

namespace Cleat\Gateway;

use Cleat\Enums\GatewayStatus;

/**
 * A deterministic in-memory gateway for development and tests. The opaque
 * token decides the outcome:
 *
 *   tok_decline        responseCode 2, declined
 *   tok_insufficient   responseCode 2, reason text 'insufficient funds'
 *   tok_error          responseCode 3, processing error
 *   tok_held           responseCode 4, held for fraud review          (extra)
 *   tok_timeout        the request times out; nothing was captured    (extra)
 *   tok_timeout_captured  times out, but the gateway did capture it  (extra)
 *   tok_invalid        cannot be saved to a customer profile          (extra)
 *   tok_visa / tok_mastercard / tok_amex / tok_discover   approve, with that brand
 *   anything else      approves as Visa ending 4242
 *
 * A saved card keeps its token's behaviour, so a profile saved from
 * tok_decline declines every renewal. setPaymentProfileToken() changes it
 * mid-test. Transactions start unsettled; settle() settles them, which
 * matters for the refund-or-void decision.
 */
final class FakeGateway implements GatewayInterface
{
    public const DESCRIPTOR = 'COMMON.ACCEPT.INAPP.PAYMENT';

    /** Test cards offered by the hosted pages in fake mode. */
    public const TEST_TOKENS = [
        'tok_visa' => 'Visa 4242 (approves)',
        'tok_mastercard' => 'Mastercard 4444 (approves)',
        'tok_decline' => 'Declined card',
        'tok_insufficient' => 'Insufficient funds',
        'tok_error' => 'Processing error',
        'tok_held' => 'Held for fraud review',
    ];

    private const CARDS = [
        'tok_visa' => ['Visa', '4242'],
        'tok_mastercard' => ['MasterCard', '4444'],
        'tok_amex' => ['AmericanExpress', '0005'],
        'tok_discover' => ['Discover', '0012'],
    ];

    /** @var array<string, array{email: string, payment_profiles: array<string, string>}> */
    private array $profiles = [];

    /** @var array<string, array{amount: int, settled: bool, status: string, last_four: string, invoice_number: string, refunded: int, ref: ?string}> */
    private array $transactions = [];

    /** @var list<array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    /**
     * Test hook, called once per charge while the charge is "in flight"
     * (after the pending row is committed, before the result returns). Lets a
     * test start a second, competing request at exactly that moment.
     *
     * @var (\Closure(): void)|null
     */
    public ?\Closure $whileCharging = null;

    /** When true, findTransaction() fails as if the gateway could not be reached. */
    public bool $failLookups = false;

    public function __construct(private readonly int $delayMs = 0)
    {
    }

    public function createCustomerProfile(string $merchantCustomerId, string $email, ?string $description = null): GatewayResult
    {
        $this->record(__FUNCTION__, ['merchant_customer_id' => $merchantCustomerId, 'email' => $email]);
        $id = 'fake_cus_' . bin2hex(random_bytes(6));
        $this->profiles[$id] = ['email' => $email, 'payment_profiles' => []];
        return GatewayResult::ok(['customer_profile_id' => $id]);
    }

    public function createPaymentProfile(string $profileId, array $opaqueData): GatewayResult
    {
        $token = (string) $opaqueData['dataValue'];
        $this->record(__FUNCTION__, ['profile_id' => $profileId, 'card' => $this->outcome($token)]);
        if ($token === 'tok_invalid') {
            return new GatewayResult(false, GatewayStatus::Declined, null, null, 'E00027', 'The transaction was unsuccessful.');
        }
        $this->profiles[$profileId] ??= ['email' => '', 'payment_profiles' => []];
        $ppId = 'fake_pp_' . bin2hex(random_bytes(6));
        $this->profiles[$profileId]['payment_profiles'][$ppId] = $token;
        [$brand, $last4] = self::CARDS[$token] ?? ['Visa', '4242'];
        return GatewayResult::ok([
            'payment_profile_id' => $ppId,
            'card_brand' => $brand,
            'card_last_four' => $last4,
            'card_exp' => '2030-12',
        ]);
    }

    public function deletePaymentProfile(string $profileId, string $paymentProfileId): GatewayResult
    {
        $this->record(__FUNCTION__, ['profile_id' => $profileId, 'payment_profile_id' => $paymentProfileId]);
        unset($this->profiles[$profileId]['payment_profiles'][$paymentProfileId]);
        return GatewayResult::ok();
    }

    public function chargeProfile(string $profileId, string $paymentProfileId, int $amountCents, string $invoiceNumber, string $idempotencyKey): GatewayResult
    {
        $this->pause();
        $token = $this->profiles[$profileId]['payment_profiles'][$paymentProfileId] ?? null;
        $this->record(__FUNCTION__, [
            'profile_id' => $profileId, 'payment_profile_id' => $paymentProfileId, 'amount' => $amountCents,
            'invoice_number' => $invoiceNumber, 'idempotency_key' => $idempotencyKey, 'card' => $token === null ? null : $this->outcome($token),
        ]);
        if ($token === null) {
            // A profile from another process (or one the test never created) still charges
            // deterministically: treat it as a good card.
            $token = 'tok_visa';
        }
        return $this->authCapture($token, $amountCents, $invoiceNumber);
    }

    public function chargeToken(array $opaqueData, int $amountCents, string $invoiceNumber, string $idempotencyKey, ?string $email = null, ?string $ip = null): GatewayResult
    {
        $this->pause();
        $token = (string) $opaqueData['dataValue'];
        $this->record(__FUNCTION__, [
            'amount' => $amountCents, 'invoice_number' => $invoiceNumber, 'idempotency_key' => $idempotencyKey,
            'email' => $email, 'ip' => $ip, 'card' => $this->outcome($token),
        ]);
        return $this->authCapture($token, $amountCents, $invoiceNumber);
    }

    public function refund(string $transactionId, int $amountCents, string $cardLastFour, string $idempotencyKey): GatewayResult
    {
        $this->record(__FUNCTION__, ['transaction_id' => $transactionId, 'amount' => $amountCents, 'idempotency_key' => $idempotencyKey]);
        $t = $this->transactions[$transactionId] ?? null;
        if ($t === null) {
            return $this->txResult(3, '16', 'The transaction cannot be found.');
        }
        if (!$t['settled'] || $t['last_four'] !== $cardLastFour) {
            return $this->txResult(3, '54', 'The referenced transaction does not meet the criteria for issuing a credit.');
        }
        if ($t['refunded'] + $amountCents > $t['amount']) {
            return $this->txResult(3, '55', 'The sum of credits against the referenced transaction would exceed original debit amount.');
        }
        $this->transactions[$transactionId]['refunded'] += $amountCents;
        $id = $this->newTransaction($amountCents, $t['invoice_number'], $t['last_four'], 'refundPendingSettlement', $transactionId);
        return $this->txResult(1, '1', 'This transaction has been approved.', $id);
    }

    public function void(string $transactionId, string $idempotencyKey): GatewayResult
    {
        $this->record(__FUNCTION__, ['transaction_id' => $transactionId, 'idempotency_key' => $idempotencyKey]);
        $t = $this->transactions[$transactionId] ?? null;
        if ($t === null) {
            return $this->txResult(3, '16', 'The transaction cannot be found.');
        }
        if ($t['settled']) {
            return $this->txResult(3, '54', 'The referenced transaction does not meet the criteria for issuing a credit.');
        }
        $this->transactions[$transactionId]['status'] = 'voided';
        return $this->txResult(1, '1', 'This transaction has been approved.', $transactionId);
    }

    public function getTransaction(string $transactionId): GatewayResult
    {
        $this->record(__FUNCTION__, ['transaction_id' => $transactionId]);
        $t = $this->transactions[$transactionId] ?? null;
        if ($t === null) {
            return GatewayResult::error('E00040', 'The record cannot be found.');
        }
        return new GatewayResult(true, GatewayStatus::Ok, $transactionId, null, null, null, [], [
            'status' => $t['status'],
            'amount' => $t['amount'],
            'card_last_four' => $t['last_four'],
            'invoice_number' => $t['invoice_number'],
            'ref_transaction_id' => $t['ref'],
        ]);
    }

    public function findTransaction(string $invoiceNumber, int $amountCents, \DateTimeImmutable $since): GatewayResult
    {
        $this->record(__FUNCTION__, ['invoice_number' => $invoiceNumber, 'amount' => $amountCents]);
        if ($this->failLookups) {
            return GatewayResult::error('network', 'Could not reach the payment gateway.');
        }
        foreach ($this->transactions as $id => $t) {
            if ($t['invoice_number'] === $invoiceNumber && $t['amount'] === $amountCents && $t['ref'] === null) {
                $status = match ($t['status']) {
                    'capturedPendingSettlement', 'settledSuccessfully' => 'approved',
                    'FDSPendingReview' => 'held',
                    default => 'not_captured',
                };
                return GatewayResult::ok(['transaction_id' => $id, 'status' => $status]);
            }
        }
        return GatewayResult::ok(['transaction_id' => null, 'status' => 'not_captured']);
    }

    // --- Test controls -----------------------------------------------------

    /** Settle one transaction, or all of them. Refunds need a settled transaction. */
    public function settle(?string $transactionId = null): void
    {
        foreach ($this->transactions as $id => $t) {
            if ($transactionId === null || $id === $transactionId) {
                $this->transactions[$id]['settled'] = true;
                if ($t['status'] === 'capturedPendingSettlement') {
                    $this->transactions[$id]['status'] = 'settledSuccessfully';
                }
            }
        }
    }

    /** Change how a saved card behaves from now on (e.g. it starts declining). */
    public function setPaymentProfileToken(string $profileId, string $paymentProfileId, string $token): void
    {
        $this->profiles[$profileId]['payment_profiles'][$paymentProfileId] = $token;
    }

    /** Register a transaction made elsewhere, e.g. in the merchant interface. */
    public function addTransaction(string $transactionId, int $amountCents, string $lastFour, string $status, ?string $refTransactionId = null, string $invoiceNumber = ''): void
    {
        $this->transactions[$transactionId] = [
            'amount' => $amountCents, 'settled' => true, 'status' => $status, 'last_four' => $lastFour,
            'invoice_number' => $invoiceNumber, 'refunded' => 0, 'ref' => $refTransactionId,
        ];
    }

    /** @return list<array{method: string, args: array<string, mixed>}> */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $c) => $c['method'] === $method));
    }

    public function chargeCount(): int
    {
        return count($this->callsTo('chargeProfile')) + count($this->callsTo('chargeToken'));
    }

    // --- Internals ---------------------------------------------------------

    private function authCapture(string $token, int $amountCents, string $invoiceNumber): GatewayResult
    {
        [, $last4] = self::CARDS[$token] ?? ['Visa', '4242'];
        return match ($this->outcome($token)) {
            'timeout' => GatewayResult::timeout(),
            'timeout_captured' => (function () use ($amountCents, $invoiceNumber, $last4): GatewayResult {
                // The money moved, but the response was lost on the way back.
                $this->newTransaction($amountCents, $invoiceNumber, $last4, 'capturedPendingSettlement');
                return GatewayResult::timeout();
            })(),
            'decline' => $this->txResult(2, '2', 'This transaction has been declined.'),
            'insufficient' => $this->txResult(2, '2', 'insufficient funds'),
            'error' => $this->txResult(3, '19', 'An error occurred during processing. Please try again in 5 minutes.'),
            'held' => $this->txResult(4, '252', 'Your order has been received. Thank you for your business!',
                $this->newTransaction($amountCents, $invoiceNumber, $last4, 'FDSPendingReview')),
            default => $this->txResult(1, '1', 'This transaction has been approved.',
                $this->newTransaction($amountCents, $invoiceNumber, $last4, 'capturedPendingSettlement'), 'XXXX' . $last4),
        };
    }

    private function outcome(string $token): string
    {
        return match ($token) {
            'tok_decline' => 'decline',
            'tok_insufficient' => 'insufficient',
            'tok_error' => 'error',
            'tok_held' => 'held',
            'tok_timeout' => 'timeout',
            'tok_timeout_captured' => 'timeout_captured',
            'tok_invalid' => 'invalid',
            default => 'approve',
        };
    }

    private function newTransaction(int $amount, string $invoiceNumber, string $lastFour, string $status, ?string $ref = null): string
    {
        $id = 'fake_' . bin2hex(random_bytes(6));
        $this->transactions[$id] = [
            'amount' => $amount, 'settled' => false, 'status' => $status, 'last_four' => $lastFour,
            'invoice_number' => $invoiceNumber, 'refunded' => 0, 'ref' => $ref,
        ];
        return $id;
    }

    private function txResult(int $code, string $reasonCode, string $reasonText, ?string $transactionId = null, ?string $accountNumber = null): GatewayResult
    {
        $status = match ($code) {
            1 => GatewayStatus::Approved,
            2 => GatewayStatus::Declined,
            4 => GatewayStatus::Held,
            default => GatewayStatus::Error,
        };
        $raw = ['transactionResponse' => [
            'responseCode' => (string) $code,
            'transId' => $transactionId ?? '0',
            'accountNumber' => $accountNumber,
            ($code === 1 || $code === 4 ? 'messages' : 'errors') => [[
                $code === 1 || $code === 4 ? 'code' : 'errorCode' => $reasonCode,
                $code === 1 || $code === 4 ? 'description' : 'errorText' => $reasonText,
            ]],
        ], 'messages' => ['resultCode' => $code === 1 ? 'Ok' : 'Error'], 'fake' => true];

        return new GatewayResult($code === 1, $status, $transactionId, $code, $reasonCode, $reasonText, $raw, [
            'account_number' => $accountNumber,
        ]);
    }

    /** @param array<string, mixed> $args */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }

    private function pause(): void
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }
        if ($this->whileCharging !== null) {
            $hook = $this->whileCharging;
            $this->whileCharging = null; // once
            $hook();
        }
    }
}
