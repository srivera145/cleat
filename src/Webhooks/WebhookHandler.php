<?php

declare(strict_types=1);

namespace Cleat\Webhooks;

use Cleat\Charge;
use Cleat\Cleat;
use Cleat\Enums\ChargeStatus;
use Cleat\Enums\RefundMethod;
use Cleat\Enums\RefundStatus;
use Cleat\Exceptions\CleatException;
use Cleat\Gateway\AuthorizeNetGateway;
use Cleat\Gateway\GatewayResult;
use Cleat\Http\Response;
use Cleat\Invoice;
use Cleat\Refund;
use Cleat\Support\Db;
use Cleat\Support\Log;
use PDOException;
use Throwable;

/**
 * Authorize.net webhook ingest.
 *
 *   $response = (new WebhookHandler())->handle(file_get_contents('php://input'), getallheaders());
 *
 * 1. Verify X-ANET-Signature ("sha512=" + uppercase hex HMAC-SHA512 of the
 *    raw body) with hash_equals. Mismatch -> 401.
 * 2. Store the event keyed on notificationId. A notification that was already
 *    processed -> 200 with no processing.
 * 3. Reconcile against cleat_charges by transaction id. Unknown types are
 *    stored and ignored. A processing error returns 500 so Authorize.net
 *    retries; the stored row stays unprocessed until a retry succeeds.
 */
final class WebhookHandler
{
    /** @param array<string, string|list<string>> $headers */
    public function handle(string $rawBody, array $headers): Response
    {
        $key = (string) Cleat::config('authorizenet.signature_key', '');
        $signature = self::header($headers, 'X-ANET-Signature');
        if ($key === '' || $signature === null || !self::verify($rawBody, $signature, $key)) {
            Log::warning('Webhook rejected: missing or invalid X-ANET-Signature');
            return Response::text('Invalid signature', 401);
        }

        $event = AuthorizeNetGateway::decode($rawBody);
        if ($event === null || !isset($event['notificationId'], $event['eventType'])) {
            return Response::text('Malformed notification', 400);
        }
        $eventId = (string) $event['notificationId'];
        $type = (string) $event['eventType'];
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        try {
            Db::insert('cleat_webhook_events', [
                'gateway_event_id' => mb_substr($eventId, 0, 191),
                'event_type' => mb_substr($type, 0, 191),
                'payload' => $event,
                'created_at' => Cleat::now(),
            ]);
        } catch (PDOException $e) {
            if (!Db::isDuplicateKey($e)) {
                throw $e;
            }
        }

        $lock = 'cleat:webhook:' . sha1($eventId);
        if (!Db::lock($lock, 10)) {
            return Response::text('Busy, retry later', 503);
        }
        try {
            $processed = Db::value('SELECT processed_at FROM cleat_webhook_events WHERE gateway_event_id = ?', [$eventId]);
            if ($processed !== null) {
                return Response::text('Duplicate notification', 200);
            }
            $this->process($type, $payload);
            Db::run('UPDATE cleat_webhook_events SET processed_at = ? WHERE gateway_event_id = ?', [Cleat::now(), $eventId]);
        } catch (Throwable $e) {
            Log::error('Webhook processing failed; Authorize.net will retry', ['event_type' => $type, 'exception' => $e::class, 'message' => $e->getMessage()]);
            return Response::text('Processing failed', 500);
        } finally {
            Db::unlock($lock);
        }
        return Response::text('OK', 200);
    }

    /**
     * Authorize.net documents the key as the 128-hex-character Signature Key.
     * The spec for Cleat says to key the HMAC with hex2bin(signature_key);
     * Authorize.net's published community examples key it with the hex
     * string itself. Until this is checked against a live sandbox both forms
     * are accepted. Each needs the secret, so neither weakens the check.
     */
    public static function verify(string $rawBody, string $header, string $signatureKey): bool
    {
        if (preg_match('/^sha512=([A-Fa-f0-9]{128})$/D', trim($header), $m) !== 1) {
            return false;
        }
        $given = strtoupper($m[1]);
        $keys = [];
        if (strlen($signatureKey) % 2 === 0 && ctype_xdigit($signatureKey)) {
            $keys[] = (string) hex2bin($signatureKey);
        }
        $keys[] = $signatureKey;
        $valid = false;
        foreach ($keys as $key) {
            // Evaluate every candidate so timing does not reveal which form matched.
            $valid = hash_equals(strtoupper(hash_hmac('sha512', $rawBody, $key)), $given) || $valid;
        }
        return $valid;
    }

    /** @param array<string, mixed> $p */
    private function process(string $type, array $p): void
    {
        match ($type) {
            'net.authorize.payment.authcapture.created' => $this->authCaptured($p),
            'net.authorize.payment.refund.created' => $this->refunded($p),
            'net.authorize.payment.void.created' => $this->voided($p),
            'net.authorize.payment.fraud.approved' => $this->fraudResolved($p, true),
            'net.authorize.payment.fraud.declined' => $this->fraudResolved($p, false),
            'net.authorize.customer.paymentProfile.deleted' => $this->paymentProfileDeleted($p),
            default => null, // stored above, otherwise ignored
        };
    }

    /**
     * A capture happened. Usually Cleat already recorded it. If not, it is a
     * charge whose response never arrived (timeout, crash): match it by
     * invoice number and amount and settle it, so the money is not lost and
     * the customer is not charged again.
     *
     * @param array<string, mixed> $p
     */
    private function authCaptured(array $p): void
    {
        $transId = (string) ($p['id'] ?? '');
        $code = (int) ($p['responseCode'] ?? 0);
        if ($transId === '' || !in_array($code, [1, 4], true)) {
            return;
        }
        $known = Charge::findByTransactionId($transId);
        if ($known !== null) {
            if ($known->status === ChargeStatus::Pending) {
                $known->invoice()->settleCharge($known, $code === 1 ? ChargeStatus::Succeeded : ChargeStatus::Held, $transId);
            }
            return;
        }
        $number = isset($p['invoiceNumber']) ? (string) $p['invoiceNumber'] : null;
        $invoice = $number !== null ? Invoice::findByNumber($number) : null;
        if ($invoice === null || !isset($p['authAmount'])) {
            Log::info('authcapture webhook did not match any Cleat invoice', ['transaction_id' => $transId]);
            return;
        }
        $amount = AuthorizeNetGateway::cents((string) $p['authAmount']);
        $rows = Db::all(
            "SELECT * FROM cleat_charges WHERE invoice_id = ? AND amount = ? AND gateway_transaction_id IS NULL
             AND status IN ('pending', 'failed') ORDER BY id",
            [$invoice->id, $amount],
        );
        // An attempt whose answer never arrived is the one this capture belongs
        // to; match those first, oldest first, even if already reconciled as
        // "not captured" (the webhook is the gateway's final word).
        $match = null;
        foreach ($rows as $row) {
            if ($row['status'] === 'failed' && GatewayResult::isUnknownOutcome($row['reason_code'], includeVerified: true)) {
                $match = $row;
                break;
            }
        }
        if ($match === null) {
            foreach ($rows as $row) {
                if ($row['status'] !== 'pending') {
                    continue;
                }
                if (Db::date($row['created_at']) > Cleat::now()->modify('-5 minutes')) {
                    // Probably still in flight: its own response is about to be
                    // recorded. Fail so Authorize.net redelivers later.
                    throw new CleatException('Matching charge is still in flight; retry the notification later.');
                }
                $match = $row; // left pending by a crash
                break;
            }
        }
        if ($match === null) {
            Log::warning('authcapture webhook found the invoice but no unresolved charge for it', ['invoice_id' => $invoice->id, 'transaction_id' => $transId]);
            return;
        }
        $invoice->settleCharge(Charge::fromRow($match), $code === 1 ? ChargeStatus::Succeeded : ChargeStatus::Held, $transId);
    }

    /** @param array<string, mixed> $p */
    private function fraudResolved(array $p, bool $approved): void
    {
        $transId = (string) ($p['id'] ?? '');
        $charge = $transId !== '' ? Charge::findByTransactionId($transId) : null;
        if ($charge === null || $charge->status !== ChargeStatus::Held) {
            return;
        }
        $approved
            ? $charge->invoice()->settleCharge($charge, ChargeStatus::Succeeded, $transId)
            : $charge->invoice()->settleCharge($charge, ChargeStatus::Failed, $transId, 'fraud_declined', 'Declined after fraud review.');
    }

    /**
     * A refund made outside Cleat (e.g. in the merchant interface). Refunds
     * Cleat made itself are already recorded under the same transaction id.
     *
     * @param array<string, mixed> $p
     */
    private function refunded(array $p): void
    {
        $refundTransId = (string) ($p['id'] ?? '');
        if ($refundTransId === '' || Refund::findByTransactionId($refundTransId) !== null) {
            return;
        }
        $details = Cleat::gateway()->getTransaction($refundTransId);
        $original = $details->get('ref_transaction_id');
        $charge = is_string($original) ? Charge::findByTransactionId($original) : null;
        if ($charge === null) {
            Log::info('refund webhook did not match a Cleat charge', ['transaction_id' => $refundTransId]);
            return;
        }
        $this->deferIfRefundInFlight($charge);
        $amount = isset($p['authAmount']) ? AuthorizeNetGateway::cents((string) $p['authAmount']) : (int) $details->get('amount', 0);
        $this->recordExternalRefund($charge, $amount, RefundMethod::Refund, $refundTransId);
    }

    /** @param array<string, mixed> $p */
    private function voided(array $p): void
    {
        $transId = (string) ($p['id'] ?? '');
        $charge = $transId !== '' ? Charge::findByTransactionId($transId) : null;
        if ($charge === null || $charge->status === ChargeStatus::Voided) {
            return;
        }
        $this->deferIfRefundInFlight($charge);
        if ($charge->status === ChargeStatus::Succeeded) {
            $this->recordExternalRefund($charge, $charge->amount - $charge->refundedAmount(), RefundMethod::Void, $transId);
        } else {
            $charge->settle(ChargeStatus::Voided, null);
        }
    }

    /**
     * Charge::refund() is between its gateway call and recording the result:
     * this notification is probably about that very refund. Recording it now
     * would count it twice, so fail and let Authorize.net redeliver; by then
     * the refund carries its transaction id and the redelivery is a no-op.
     */
    private function deferIfRefundInFlight(Charge $charge): void
    {
        if (Db::value("SELECT id FROM cleat_refunds WHERE charge_id = ? AND status = 'pending' LIMIT 1", [$charge->id]) !== null) {
            throw new CleatException('A refund for this charge is still being recorded; retry the notification later.');
        }
    }

    private function recordExternalRefund(Charge $charge, int $amount, RefundMethod $method, string $transactionId): void
    {
        if ($amount <= 0) {
            return;
        }
        Db::transaction(function () use ($charge, $amount, $method, $transactionId): void {
            Db::run('SELECT id FROM cleat_charges WHERE id = ? FOR UPDATE', [$charge->id]);
            $now = Cleat::now();
            Db::insert('cleat_refunds', [
                'charge_id' => $charge->id,
                'amount' => $amount,
                'method' => $method,
                'status' => RefundStatus::Succeeded,
                'gateway_transaction_id' => $transactionId,
                'idempotency_key' => sprintf('ch_%d_external_%s_%s', $charge->id, $method->value, $transactionId),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $status = match (true) {
                $method === RefundMethod::Void => ChargeStatus::Voided,
                $charge->refundedAmount() >= $charge->amount => ChargeStatus::Refunded,
                default => $charge->status,
            };
            Db::update('cleat_charges', ['status' => $status, 'updated_at' => $now], ['id' => $charge->id]);
        });
    }

    /** @param array<string, mixed> $p */
    private function paymentProfileDeleted(array $p): void
    {
        $profileId = isset($p['customerProfileId']) ? (string) $p['customerProfileId'] : '';
        $paymentProfileId = isset($p['id']) ? (string) $p['id'] : '';
        if ($profileId === '' || $paymentProfileId === '') {
            return;
        }
        Db::run(
            'UPDATE cleat_customers SET gateway_payment_id = NULL, card_brand = NULL, card_last_four = NULL, card_exp = NULL, updated_at = ?
             WHERE gateway_customer_id = ? AND gateway_payment_id = ?',
            [Cleat::now(), $profileId, $paymentProfileId],
        );
    }

    /** @param array<string, string|list<string>> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0 || strcasecmp(str_replace('_', '-', (string) $key), 'HTTP-' . $name) === 0) {
                return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }
        return null;
    }
}
