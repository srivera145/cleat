<?php

declare(strict_types=1);

namespace Cleat\Tests\Feature;

use Cleat\Charge;
use Cleat\Customer;
use Cleat\Enums\ChargeStatus;
use Cleat\Enums\InvoiceStatus;
use Cleat\Enums\RefundMethod;
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

    public function test_signature_accepts_the_spec_form_and_header_case(): void
    {
        $body = $this->body('net.authorize.customer.created', ['id' => '9']);
        $this->assertSame(200, (new WebhookHandler())->handle($body, ['x-anet-signature' => $this->sign($body)])->status);
        $body2 = $this->body('net.authorize.customer.created', ['id' => '10']);
        $lower = 'sha512=' . strtolower(substr($this->sign($body2), 7));
        $this->assertSame(200, (new WebhookHandler())->handle($body2, ['HTTP_X_ANET_SIGNATURE' => $lower])->status);
        $this->assertTrue(WebhookHandler::verify($body, 'sha512=' . strtoupper(hash_hmac('sha512', $body, self::SIGNATURE_KEY)), self::SIGNATURE_KEY), 'raw-key form also accepted until verified against a live sandbox');
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

    /** "sha512=" + uppercase hex HMAC-SHA512 keyed with hex2bin(signature_key), as the spec gives it. */
    private function sign(string $body): string
    {
        return 'sha512=' . strtoupper(hash_hmac('sha512', $body, hex2bin(self::SIGNATURE_KEY)));
    }
}
