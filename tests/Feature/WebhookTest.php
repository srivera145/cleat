<?php

declare(strict_types=1);

namespace Cleat\Tests\Feature;

use Cleat\Charge;
use Cleat\Customer;
use Cleat\Enums\ChargeStatus;
use Cleat\Enums\InvoiceStatus;
use Cleat\Enums\RefundMethod;
use Cleat\Enums\SubscriptionStatus;
use Cleat\Events\InvoicePaid;
use Cleat\Events\PaymentFailed;
use Cleat\Events\PaymentSucceeded;
use Cleat\Invoice;
use Cleat\Refund;
use Cleat\Tests\Support\TestCase;
use Cleat\Webhooks\WebhookHandler;

/** Self-check 15, plus the other event types. */
final class WebhookTest extends TestCase
{
    public function test_bad_or_missing_signature_is_401(): void
    {
        $body = $this->body('net.authorize.payment.authcapture.created', ['id' => '1']);
        $handler = new WebhookHandler();
        $this->assertSame(401, $handler->handle($body, [])->status);
        $this->assertSame(401, $handler->handle($body, ['X-ANET-Signature' => 'sha512=' . str_repeat('A', 128)])->status);
        $this->assertSame(401, $handler->handle($body, ['X-ANET-Signature' => 'garbage'])->status);
        $this->assertSame(401, $handler->handle($body . ' ', ['X-ANET-Signature' => $this->sign($body)])->status, 'body tampered after signing');
        $this->assertSame(0, $this->rows('cleat_webhook_events'), 'unverified events are not stored');
    }

    public function test_signature_accepts_header_case_variants(): void
    {
        $body = $this->body('net.authorize.customer.created', ['id' => '9']);
        $this->assertSame(200, (new WebhookHandler())->handle($body, ['x-anet-signature' => $this->sign($body)])->status);
        $body2 = $this->body('net.authorize.customer.created', ['id' => '10']);
        $lower = 'sha512=' . strtolower(substr($this->sign($body2), 7));
        $this->assertSame(200, (new WebhookHandler())->handle($body2, ['HTTP_X_ANET_SIGNATURE' => $lower])->status);
    }

    /** Sandbox regression: Authorize.net keys the HMAC with the hex string, never hex2bin() of it. */
    public function test_real_sandbox_notification_verifies_only_with_the_hex_string_key(): void
    {
        $fixture = self::fixture('authcapture-created');
        $this->assertTrue(WebhookHandler::verify($fixture['body'], $fixture['signature'], self::SIGNATURE_KEY));

        $binaryKeyed = 'sha512=' . strtoupper(hash_hmac('sha512', $fixture['body'], (string) hex2bin(self::SIGNATURE_KEY)));
        $this->assertFalse(WebhookHandler::verify($fixture['body'], $binaryKeyed, self::SIGNATURE_KEY), 'hex2bin(key) is not how Authorize.net signs');
        $this->assertSame(401, (new WebhookHandler())->handle($fixture['body'], ['X-ANET-Signature' => $binaryKeyed])->status);
        $this->assertSame(0, $this->rows('cleat_webhook_events'));
    }

    /**
     * Sandbox regression: the real payload (amount as a JSON number, invoice
     * number, merchantReferenceId) settles the timed-out charge it belongs to,
     * and a second delivery of the same notification changes nothing.
     */
    public function test_real_sandbox_authcapture_settles_a_timed_out_charge_once(): void
    {
        $fixture = self::fixture('authcapture-created');
        $event = json_decode($fixture['body'], true);

        // The sandbox state it was captured from: invoice CLT-000011 for $8.68,
        // whose charge reached Authorize.net but whose response was lost.
        $this->pdo->exec("UPDATE cleat_sequences SET value = 10 WHERE name = 'invoice'");
        $customer = Customer::create(['email' => 'sandbox@example.com']);
        $invoice = $customer->newInvoice()->addItem('Sandbox lost response', 868)->finalize();
        $this->assertSame($event['payload']['invoiceNumber'], $invoice->number);
        try {
            $invoice->payWithToken(self::opaque('tok_timeout'), false, '203.0.113.10');
        } catch (\Cleat\Exceptions\PaymentFailedException) {
        }
        $this->assertSame('timeout', $invoice->charges()[0]->reason_code);

        $headers = ['X-ANET-Signature' => $fixture['signature']];
        $first = (new WebhookHandler())->handle($fixture['body'], $headers);
        $second = (new WebhookHandler())->handle($fixture['body'], $headers);

        $this->assertSame([200, 'OK'], [$first->status, $first->body]);
        $this->assertSame([200, 'Duplicate notification'], [$second->status, $second->body]);
        $charge = $invoice->charges()[0];
        $this->assertSame(ChargeStatus::Succeeded, $charge->status);
        $this->assertSame('80060639975', $charge->gateway_transaction_id);
        $this->assertSame('timeout_captured', $charge->reason_code);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(868, $invoice->amount_paid);
        $this->assertCount(1, $invoice->charges());
        $this->assertSame(1, $this->rows('cleat_webhook_events', 'gateway_event_id = ? AND processed_at IS NOT NULL', [$event['notificationId']]));
        $this->assertCount(1, $this->eventsOf(PaymentSucceeded::class), 'processed once');
    }

    public function test_same_notification_twice_is_processed_once(): void
    {
        [$invoice, $charge] = $this->heldCharge();
        $body = $this->body('net.authorize.payment.fraud.approved', ['id' => $charge->gateway_transaction_id, 'responseCode' => 1], 'notif-dup-1');

        $first = (new WebhookHandler())->handle($body, ['X-ANET-Signature' => $this->sign($body)]);
        $second = (new WebhookHandler())->handle($body, ['X-ANET-Signature' => $this->sign($body)]);

        $this->assertSame(200, $first->status);
        $this->assertSame('OK', $first->body);
        $this->assertSame(200, $second->status);
        $this->assertSame('Duplicate notification', $second->body);
        $this->assertSame(1, $this->rows('cleat_webhook_events'));
        $this->assertCount(1, $this->eventsOf(PaymentSucceeded::class), 'side effects happened once');
        $this->assertCount(1, $this->eventsOf(InvoicePaid::class));
    }

    public function test_fraud_approved_settles_a_held_charge(): void
    {
        [$invoice, $charge] = $this->heldCharge();
        $this->assertSame(InvoiceStatus::Open, $invoice->status);

        $body = $this->body('net.authorize.payment.fraud.approved', ['id' => $charge->gateway_transaction_id, 'responseCode' => 1]);
        $this->assertSame(200, (new WebhookHandler())->handle($body, ['X-ANET-Signature' => $this->sign($body)])->status);

        $this->assertSame(ChargeStatus::Succeeded, Charge::findOrFail($charge->id)->status);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(4200, $invoice->amount_paid);
        $this->assertNotNull(Invoice::findOrFail($invoice->id)->paid_at);
        $this->assertNotNull($this->rows('cleat_webhook_events', 'processed_at IS NOT NULL'));
    }

    public function test_fraud_declined_fails_the_held_charge(): void
    {
        [$invoice, $charge] = $this->heldCharge();
        $body = $this->body('net.authorize.payment.fraud.declined', ['id' => $charge->gateway_transaction_id, 'responseCode' => 2]);
        (new WebhookHandler())->handle($body, ['X-ANET-Signature' => $this->sign($body)]);

        $charge = Charge::findOrFail($charge->id);
        $this->assertSame(ChargeStatus::Failed, $charge->status);
        $this->assertSame('fraud_declined', $charge->reason_code);
        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);
        $this->assertCount(1, $this->eventsOf(PaymentFailed::class));
    }

    /**
     * Sandbox regression: declining a held charge in the merchant interface
     * sends void.created a few milliseconds before fraud.declined (real
     * payloads). A renewal declined that way must still enter dunning.
     */
    public function test_fds_decline_with_void_first_still_runs_dunning_on_a_held_renewal(): void
    {
        $charge = $this->heldRenewal();

        foreach (['void-created-held', 'fraud-declined'] as $name) {
            $fixture = self::fixture($name);
            $this->assertSame(200, (new WebhookHandler())->handle($fixture['body'], ['X-ANET-Signature' => $fixture['signature']])->status);
        }

        $charge = Charge::findOrFail($charge->id);
        $this->assertSame(ChargeStatus::Failed, $charge->status);
        $this->assertSame('fraud_declined', $charge->reason_code);
        $invoice = $charge->invoice();
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertNotNull($invoice->next_attempt_at, 'a retry is scheduled');
        $this->assertSame(SubscriptionStatus::PastDue, $invoice->subscription()->status);
        $failed = $this->eventsOf(PaymentFailed::class);
        $this->assertCount(1, $failed, 'once, although both notifications arrived');
        $this->assertTrue($failed[0]->willRetry);
        $this->assertSame($invoice->paymentUrl(), self::payLinkIn($this->mailer->last()['html']), 'payment-failed email with the pay link');
        $this->assertSame(0, $this->rows('cleat_refunds'), 'a declined review is not a refund');
        $this->assertSame(2, $this->rows('cleat_webhook_events', 'processed_at IS NOT NULL'));
    }

    /** The same pair in the other order: the void must not overwrite the decline. */
    public function test_fds_decline_with_fraud_declined_first_then_void_leaves_the_charge_failed(): void
    {
        [$invoice, $charge] = $this->heldCharge();
        $this->pdo->prepare('UPDATE cleat_charges SET gateway_transaction_id = ? WHERE id = ?')->execute(['80060642126', $charge->id]);

        foreach (['fraud-declined', 'void-created-held'] as $name) {
            $fixture = self::fixture($name);
            $this->assertSame(200, (new WebhookHandler())->handle($fixture['body'], ['X-ANET-Signature' => $fixture['signature']])->status);
        }

        $charge = Charge::findOrFail($charge->id);
        $this->assertSame(ChargeStatus::Failed, $charge->status);
        $this->assertSame('fraud_declined', $charge->reason_code);
        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);
        $this->assertCount(1, $this->eventsOf(PaymentFailed::class));
        $this->assertSame(0, $this->rows('cleat_refunds'));
    }

    public function test_authcapture_reconciles_a_timed_out_charge(): void
    {
        $customer = Customer::create(['email' => 'slow@example.com']);
        $invoice = $customer->newInvoice()->addItem('Order', 12345)->finalize();
        try {
            $invoice->payWithToken(self::opaque('tok_timeout'), false, '192.0.2.1');
        } catch (\Cleat\Exceptions\PaymentFailedException) {
        }
        $charge = $invoice->charges()[0];
        $this->assertSame('timeout', $charge->reason_code);

        // Authorize.net did capture it; the webhook tells us.
        $body = $this->body('net.authorize.payment.authcapture.created', [
            'responseCode' => 1, 'authAmount' => 123.45, 'invoiceNumber' => $invoice->number, 'entityName' => 'transaction', 'id' => '60099887766',
        ]);
        $this->assertSame(200, (new WebhookHandler())->handle($body, ['X-ANET-Signature' => $this->sign($body)])->status);

        $charge = Charge::findOrFail($charge->id);
        $this->assertSame(ChargeStatus::Succeeded, $charge->status);
        $this->assertSame('60099887766', $charge->gateway_transaction_id);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
    }

    public function test_payment_profile_deleted_clears_the_card(): void
    {
        $customer = $this->customer('card@example.com');
        $body = $this->body('net.authorize.customer.paymentProfile.deleted', [
            'customerProfileId' => $customer->gateway_customer_id, 'entityName' => 'customerPaymentProfile', 'id' => $customer->gateway_payment_id,
        ]);
        (new WebhookHandler())->handle($body, ['X-ANET-Signature' => $this->sign($body)]);
        $customer = $customer->refresh();
        $this->assertFalse($customer->hasPaymentMethod());
        $this->assertNull($customer->card_last_four);
        $this->assertNull($customer->card_brand);
    }

    public function test_refund_and_void_made_outside_cleat_are_recorded(): void
    {
        $customer = Customer::create(['email' => 'refund@example.com']);
        $invoice = $customer->newInvoice()->addItem('Order', 10000)->finalize();
        $charge = $invoice->payWithToken(self::opaque('tok_visa'), false, '192.0.2.1');

        $this->gateway->addTransaction('60011111111', 4000, '4242', 'refundSettledSuccessfully', $charge->gateway_transaction_id);
        $refund = $this->body('net.authorize.payment.refund.created', ['responseCode' => 1, 'authAmount' => 40.00, 'id' => '60011111111']);
        (new WebhookHandler())->handle($refund, ['X-ANET-Signature' => $this->sign($refund)]);
        $this->assertSame(4000, Charge::findOrFail($charge->id)->refundedAmount());
        $this->assertSame(ChargeStatus::Succeeded, Charge::findOrFail($charge->id)->status, 'partially refunded');

        $other = $customer->newInvoice()->addItem('Order 2', 5000)->finalize();
        $otherCharge = $other->payWithToken(self::opaque('tok_visa'), false, '192.0.2.1');
        $void = $this->body('net.authorize.payment.void.created', ['responseCode' => 1, 'authAmount' => 50.00, 'id' => $otherCharge->gateway_transaction_id]);
        (new WebhookHandler())->handle($void, ['X-ANET-Signature' => $this->sign($void)]);
        $this->assertSame(ChargeStatus::Voided, Charge::findOrFail($otherCharge->id)->status);
        $this->assertSame(RefundMethod::Void, Refund::forCharge($otherCharge->id)[0]->method);
    }

    public function test_unknown_event_types_are_stored_and_ignored(): void
    {
        $body = $this->body('net.authorize.customer.subscription.created', ['id' => '1']);
        $response = (new WebhookHandler())->handle($body, ['X-ANET-Signature' => $this->sign($body)]);
        $this->assertSame(200, $response->status);
        $this->assertSame(1, $this->rows('cleat_webhook_events', 'event_type = ? AND processed_at IS NOT NULL', ['net.authorize.customer.subscription.created']));
    }

    public function test_missing_signature_key_rejects_everything(): void
    {
        $this->reconfigure(['authorizenet' => ['signature_key' => '']]);
        $body = $this->body('net.authorize.customer.created', ['id' => '1']);
        $this->assertSame(401, (new WebhookHandler())->handle($body, ['X-ANET-Signature' => $this->sign($body)])->status);
    }

    /** @return array{0: Invoice, 1: Charge} */
    private function heldCharge(): array
    {
        $customer = Customer::create(['email' => 'review@example.com']);
        $invoice = $customer->newInvoice()->addItem('Big order', 4200)->finalize();
        $charge = $invoice->payWithToken(self::opaque('tok_held'), false, '192.0.2.1');
        $this->assertSame(ChargeStatus::Held, $charge->status);
        $this->assertNotNull($charge->gateway_transaction_id);
        return [$invoice->refresh(), $charge];
    }

    /** A renewal held for review, carrying the transaction id of the real sandbox notifications. */
    private function heldRenewal(): Charge
    {
        $this->monthlyPrice(amount: 65000);
        $this->at('2026-01-01 00:00:00');
        $customer = $this->customer('renewal@example.com');
        $subscription = $customer->newSubscription('premium-monthly')->create();
        $this->gateway->setPaymentProfileToken((string) $customer->gateway_customer_id, (string) $customer->gateway_payment_id, 'tok_held');
        $this->runAt('2026-02-01 00:00:00');

        $invoice = $subscription->refresh()->latestInvoice();
        $charges = $invoice->charges();
        $charge = $charges[array_key_last($charges)];
        $this->assertSame(ChargeStatus::Held, $charge->status);
        $this->assertNull($invoice->next_attempt_at, 'nothing is scheduled while the charge is held');
        $this->pdo->prepare('UPDATE cleat_charges SET gateway_transaction_id = ? WHERE id = ?')->execute(['80060642126', $charge->id]);
        $this->mailer->clear();
        $this->events = [];
        return Charge::findOrFail($charge->id);
    }

    private function body(string $type, array $payload, ?string $id = null): string
    {
        return json_encode([
            'notificationId' => $id ?? 'notif-' . bin2hex(random_bytes(6)),
            'eventType' => $type,
            'eventDate' => '2026-01-15T12:00:00.000Z',
            'webhookId' => 'wh-1',
            'payload' => $payload,
        ], JSON_UNESCAPED_SLASHES);
    }

    /** "sha512=" + uppercase hex HMAC-SHA512 keyed with the signature key string, as Authorize.net signs. */
    private function sign(string $body): string
    {
        return 'sha512=' . strtoupper(hash_hmac('sha512', $body, self::SIGNATURE_KEY));
    }

    /** @return array{source: string, signature: string, body: string} */
    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__) . "/fixtures/webhooks/$name.json"), true, flags: JSON_THROW_ON_ERROR);
    }
}
