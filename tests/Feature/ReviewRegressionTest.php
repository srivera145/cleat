<?php

declare(strict_types=1);

namespace Cleat\Tests\Feature;

use Cleat\Charge;
use Cleat\Customer;
use Cleat\Enums\ChargeStatus;
use Cleat\Enums\InvoiceStatus;
use Cleat\Enums\SubscriptionStatus;
use Cleat\Events\InvoicePaid;
use Cleat\Events\PaymentSucceeded;
use Cleat\Exceptions\ChargeInProgressException;
use Cleat\Exceptions\CleatException;
use Cleat\Exceptions\PaymentFailedException;
use Cleat\Http\CheckoutPage;
use Cleat\Http\Csrf;
use Cleat\Http\InvoicePayPage;
use Cleat\Invoice;
use Cleat\PaymentLink;
use Cleat\Subscription;
use Cleat\Tests\Support\TestCase;
use Cleat\Webhooks\WebhookHandler;

/**
 * Regression tests for the issues found in the independent review of the
 * money paths. Each test names the scenario it locks down.
 */
final class ReviewRegressionTest extends TestCase
{
    /** #1: a timeout that did capture is found on the retry, never charged twice. */
    public function test_pay_page_retry_after_a_captured_timeout_does_not_charge_again(): void
    {
        $invoice = $this->openInvoice();
        $first = $this->paySubmit($invoice, self::opaque('tok_timeout_captured'));
        $this->assertSame(402, $first->status);
        $this->assertStringContainsString('confirm your payment with the card network', $first->body);
        $this->assertStringContainsString('be charged twice', $first->body);
        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);

        $retry = $this->paySubmit($invoice, self::opaque('tok_visa'));

        $this->assertSame(200, $retry->status);
        $this->assertStringContainsString('Payment received. Thank you!', $retry->body);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(1, $this->gateway->chargeCount(), 'one gateway charge in total');
        $charge = $invoice->charges()[0];
        $this->assertSame(ChargeStatus::Succeeded, $charge->status);
        $this->assertNotNull($charge->gateway_transaction_id);
        $this->assertCount(1, $invoice->charges());
    }

    /** #1: a timeout that did not capture is verified, then the retry charges normally. */
    public function test_pay_page_retry_after_an_uncaptured_timeout_charges_once(): void
    {
        $invoice = $this->openInvoice();
        $this->paySubmit($invoice, self::opaque('tok_timeout'));
        $retry = $this->paySubmit($invoice, self::opaque('tok_visa'));

        $this->assertSame(200, $retry->status);
        $charges = $invoice->refresh()->charges();
        $this->assertSame('timeout_verified', $charges[0]->reason_code);
        $this->assertSame(ChargeStatus::Succeeded, $charges[1]->status);
        $this->assertSame(2, $this->gateway->chargeCount());
    }

    /** #1: when the gateway cannot be asked, nobody may charge again. */
    public function test_unknown_outcome_blocks_retries_while_the_gateway_cannot_be_checked(): void
    {
        $invoice = $this->customer()->newInvoice()->addItem('Work', 5000)->finalize();
        $this->gateway->failLookups = true;
        $this->gateway->setPaymentProfileToken($invoice->customer()->gateway_customer_id, $invoice->customer()->gateway_payment_id, 'tok_timeout');
        try {
            $invoice->pay();
        } catch (PaymentFailedException) {
        }
        $this->gateway->setPaymentProfileToken($invoice->customer()->gateway_customer_id, $invoice->customer()->gateway_payment_id, 'tok_visa');

        try {
            $invoice->pay();
            $this->fail('expected ChargeInProgressException');
        } catch (ChargeInProgressException $e) {
            $this->assertStringContainsString('not known yet', $e->getMessage());
        }
        $this->assertSame(409, $this->paySubmit($invoice, self::opaque('tok_visa'))->status);
        try {
            $invoice->void();
            $this->fail('void must not hide an unconfirmed charge');
        } catch (ChargeInProgressException) {
        }
        $this->assertSame(1, $this->gateway->chargeCount());

        $this->gateway->failLookups = false;
        $this->assertNotNull($invoice->pay());
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
    }

    /** #1: a checkout that timed out (but captured) keeps its invoice; the retry finishes it. */
    public function test_checkout_retry_after_a_captured_timeout_finishes_the_first_attempt(): void
    {
        $this->oneTimePrice('ebook', 1900);
        $link = PaymentLink::create('ebook', ['max_uses' => 5]);

        $first = $this->checkoutSubmit($link, 'buyer@example.com', 'tok_timeout_captured');
        $this->assertSame(402, $first->status);
        $this->assertSame(0, $this->rows('cleat_invoices', "status = 'void'"), 'an unknown outcome is not voided');

        $retry = $this->checkoutSubmit($link, 'buyer@example.com', 'tok_visa');

        $this->assertSame(200, $retry->status);
        $this->assertStringContainsString('Payment received', $retry->body);
        $this->assertSame(1, $this->gateway->chargeCount(), 'the buyer is charged once');
        $this->assertSame(1, $this->rows('cleat_invoices'));
        $this->assertSame(1, $this->rows('cleat_invoices', "status = 'paid'"));
        $this->assertSame(1, $link->refresh()->use_count);
    }

    /** #1: a checkout timeout that did not capture is closed, and the retry is a fresh purchase. */
    public function test_checkout_retry_after_an_uncaptured_timeout_starts_over(): void
    {
        $this->oneTimePrice('ebook', 1900);
        $link = PaymentLink::create('ebook');
        $this->checkoutSubmit($link, 'buyer@example.com', 'tok_timeout');
        $retry = $this->checkoutSubmit($link, 'buyer@example.com', 'tok_visa');

        $this->assertSame(200, $retry->status);
        $this->assertSame(1, $this->rows('cleat_invoices', "status = 'void'"));
        $this->assertSame(1, $this->rows('cleat_invoices', "status = 'paid'"));
        $this->assertSame(2, $this->gateway->chargeCount());
    }

    /** #1 (follow-up): the capture webhook lands before the buyer retries; the retry shows that purchase, no second charge. */
    public function test_checkout_retry_after_the_capture_webhook_does_not_charge_again(): void
    {
        $this->oneTimePrice('ebook', 1900);
        $link = PaymentLink::create('ebook', ['max_uses' => 5]);
        $this->assertSame(402, $this->checkoutSubmit($link, 'late@example.com', 'tok_timeout')->status);
        $invoice = Invoice::fromRow($this->pdo->query('SELECT * FROM cleat_invoices')->fetch(\PDO::FETCH_ASSOC));

        // Authorize.net did capture it; the webhook says so.
        $body = $this->webhook('net.authorize.payment.authcapture.created', ['responseCode' => 1, 'authAmount' => 19.00, 'invoiceNumber' => $invoice->number, 'id' => '60123']);
        $this->assertSame(200, (new WebhookHandler())->handle($body, ['X-ANET-Signature' => $this->sign($body)])->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame('timeout_captured', $invoice->charges()[0]->reason_code);

        $retry = $this->checkoutSubmit($link, 'late@example.com', 'tok_visa');

        $this->assertSame(200, $retry->status);
        $this->assertStringContainsString('Payment received', $retry->body);
        $this->assertSame(1, $this->gateway->chargeCount(), 'no second charge');
        $this->assertSame(1, $this->rows('cleat_invoices'));
        $this->assertSame(1, $link->refresh()->use_count);
        $this->assertCount(1, $this->eventsOf(\Cleat\Events\CheckoutCompleted::class));
    }

    /** #1 (follow-up): a late capture completes the checkout even if the buyer never comes back. */
    public function test_late_capture_completes_the_checkout_without_the_buyer(): void
    {
        $this->monthlyPrice('team', 2900);
        $link = PaymentLink::create('team');
        $this->assertSame(402, $this->checkoutSubmit($link, 'gone@example.com', 'tok_timeout')->status);
        $subscription = Subscription::fromRow($this->pdo->query('SELECT * FROM cleat_subscriptions')->fetch(\PDO::FETCH_ASSOC));
        $this->assertSame(SubscriptionStatus::Incomplete, $subscription->status, 'not canceled: the outcome is unknown');
        $invoice = $subscription->latestInvoice();
        $this->assertSame(InvoiceStatus::Open, $invoice->status);

        $body = $this->webhook('net.authorize.payment.authcapture.created', ['responseCode' => 1, 'authAmount' => 29.00, 'invoiceNumber' => $invoice->number, 'id' => '60124']);
        (new WebhookHandler())->handle($body, ['X-ANET-Signature' => $this->sign($body)]);

        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame(1, $link->refresh()->use_count);
        $completed = $this->eventsOf(\Cleat\Events\CheckoutCompleted::class);
        $this->assertCount(1, $completed);
        $this->assertSame($subscription->id, $completed[0]->subscription->id);
    }

    /** #1 (follow-up): a webhook and a reconciliation settling the same charge act once. */
    public function test_settling_the_same_charge_twice_acts_once(): void
    {
        $invoice = Customer::create(['email' => 'twice@example.com'])->newInvoice()->addItem('Work', 3000)->finalize();
        try {
            $invoice->payWithToken(self::opaque('tok_timeout'), false, '1.1.1.1');
        } catch (PaymentFailedException) {
        }
        $charge = $invoice->charges()[0];
        Invoice::findOrFail($invoice->id)->settleCharge($charge, ChargeStatus::Succeeded, '60555');
        Invoice::findOrFail($invoice->id)->settleCharge($charge, ChargeStatus::Succeeded, '60555');

        $this->assertSame(3000, $invoice->refresh()->amount_paid);
        $this->assertCount(1, $this->eventsOf(InvoicePaid::class));
        $this->assertSame([], array_filter($this->logs, static fn ($l) => $l['level'] === 'error'));
    }

    /** #1: money landing on a void invoice is logged loudly, not dropped. */
    public function test_capture_on_a_void_invoice_is_logged(): void
    {
        $invoice = Customer::create(['email' => 'v@example.com'])->newInvoice()->addItem('Work', 1500)->finalize();
        try {
            $invoice->payWithToken(self::opaque('tok_timeout'), false, '1.1.1.1');
        } catch (PaymentFailedException) {
        }
        $invoice->reconcile(); // verified: nothing captured
        $invoice->void();
        $body = $this->webhook('net.authorize.payment.authcapture.created', ['responseCode' => 1, 'authAmount' => 15.00, 'invoiceNumber' => $invoice->number, 'id' => '60077']);
        (new WebhookHandler())->handle($body, ['X-ANET-Signature' => $this->sign($body)]);

        $this->assertSame(InvoiceStatus::Void, $invoice->refresh()->status);
        $this->assertSame(ChargeStatus::Succeeded, $invoice->charges()[0]->status, 'the captured money is recorded');
        $alerts = array_filter($this->logs, static fn ($l) => $l['level'] === 'error' && str_contains($l['message'], 'not open; refund it'));
        $this->assertCount(1, $alerts);
    }

    /** #2: a webhook that settled the charge mid-flight is not overwritten, and events fire once. */
    public function test_record_step_does_not_overwrite_a_charge_the_webhook_settled(): void
    {
        $invoice = $this->customer()->newInvoice()->addItem('Work', 2500)->finalize();
        $this->gateway->whileCharging = function () use ($invoice): void {
            $pending = Charge::fromRow($this->pdo->query("SELECT * FROM cleat_charges WHERE status = 'pending'")->fetch(\PDO::FETCH_ASSOC));
            Invoice::findOrFail($invoice->id)->settleCharge($pending, ChargeStatus::Succeeded, 'from_webhook');
        };
        $this->gateway->setPaymentProfileToken($invoice->customer()->gateway_customer_id, $invoice->customer()->gateway_payment_id, 'tok_timeout');

        $charge = $invoice->pay(); // the call times out, but the webhook already settled it

        $this->assertSame(ChargeStatus::Succeeded, $charge->status);
        $this->assertSame('from_webhook', $charge->gateway_transaction_id, 'the webhook answer stands');
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertCount(1, $this->eventsOf(PaymentSucceeded::class));
        $this->assertCount(1, $this->eventsOf(InvoicePaid::class));
    }

    /** #2: an authcapture webhook for a charge still in flight is deferred (500), and its redelivery is harmless. */
    public function test_authcapture_for_an_in_flight_charge_is_deferred(): void
    {
        $invoice = $this->customer()->newInvoice()->addItem('Work', 2500)->finalize();
        $responses = [];
        $this->gateway->whileCharging = function () use ($invoice, &$responses): void {
            $body = $this->webhook('net.authorize.payment.authcapture.created', ['responseCode' => 1, 'authAmount' => 25.00, 'invoiceNumber' => $invoice->number, 'id' => '60088'], 'notif-inflight');
            $responses[] = (new WebhookHandler())->handle($body, ['X-ANET-Signature' => $this->sign($body)])->status;
        };
        $invoice->pay();
        $this->assertSame([500], $responses);
        $this->assertCount(1, $this->eventsOf(PaymentSucceeded::class));
        $this->assertCount(1, $this->mailer->sent, 'one receipt');

        $body = $this->webhook('net.authorize.payment.authcapture.created', ['responseCode' => 1, 'authAmount' => 25.00, 'invoiceNumber' => $invoice->number, 'id' => '60088'], 'notif-inflight');
        $this->assertSame(200, (new WebhookHandler())->handle($body, ['X-ANET-Signature' => $this->sign($body)])->status);
        $this->assertCount(1, $this->eventsOf(PaymentSucceeded::class));
    }

    /** #3: an invoice cannot be written off while a charge on it is in flight. */
    public function test_mark_uncollectible_refuses_while_a_charge_is_in_flight(): void
    {
        $invoice = $this->customer()->newInvoice()->addItem('Work', 2500)->finalize();
        $this->gateway->whileCharging = function () use ($invoice): void {
            try {
                Invoice::findOrFail($invoice->id)->markUncollectible();
                $this->fail('markUncollectible must refuse while charging');
            } catch (ChargeInProgressException) {
                $this->addToAssertionCount(1);
            }
        };
        $invoice->pay();
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(2500, $invoice->amount_paid);
    }

    /** #4: canceling a subscription that never started closes its unpaid first invoice. */
    public function test_cancelling_an_incomplete_subscription_voids_its_invoice(): void
    {
        $this->monthlyPrice();
        $customer = $this->customer('declined@example.com', 'tok_decline');
        $payUrl = null;
        try {
            $customer->newSubscription('premium-monthly')->create();
        } catch (PaymentFailedException $e) {
            $payUrl = $e->payUrl;
        }
        $subscription = Subscription::fromRow($this->pdo->query('SELECT * FROM cleat_subscriptions')->fetch(\PDO::FETCH_ASSOC));
        $this->assertSame(SubscriptionStatus::Incomplete, $subscription->status);

        $subscription->cancel();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->refresh()->status);
        $invoice = $subscription->latestInvoice();
        $this->assertSame(InvoiceStatus::Void, $invoice->status);
        $this->assertSame(410, (new InvoicePayPage('s'))->show(basename($payUrl))->status, 'the old pay link cannot charge for nothing');
    }

    /** #5: a refund webhook arriving before Cleat records its own refund is deferred, then a no-op. */
    public function test_refund_webhook_during_an_in_flight_refund_is_not_double_counted(): void
    {
        $customer = Customer::create(['email' => 'r@example.com']);
        $charge = $customer->newInvoice()->addItem('Order', 10000)->finalize()->payWithToken(self::opaque('tok_visa'), false, '1.1.1.1');
        $this->gateway->settle();

        // Deliver the refund notification while Cleat's refund row is still pending.
        $this->pdo->exec("INSERT INTO cleat_refunds (charge_id, amount, method, status, idempotency_key, created_at, updated_at)
            VALUES ({$charge->id}, 4000, 'refund', 'pending', 'ch_{$charge->id}_refund_1', '2026-01-15 12:00:00', '2026-01-15 12:00:00')");
        $this->gateway->addTransaction('60099', 4000, '4242', 'refundPendingSettlement', $charge->gateway_transaction_id);
        $body = $this->webhook('net.authorize.payment.refund.created', ['responseCode' => 1, 'authAmount' => 40.00, 'id' => '60099'], 'notif-refund');
        $this->assertSame(500, (new WebhookHandler())->handle($body, ['X-ANET-Signature' => $this->sign($body)])->status);

        // Cleat records its refund (with the gateway's id); the redelivery then changes nothing.
        $this->pdo->exec("UPDATE cleat_refunds SET status = 'succeeded', gateway_transaction_id = '60099' WHERE charge_id = {$charge->id}");
        $this->assertSame(200, (new WebhookHandler())->handle($body, ['X-ANET-Signature' => $this->sign($body)])->status);
        $this->assertSame(4000, Charge::findOrFail($charge->id)->refundedAmount());
    }

    /** #6: an invoice prefix too long for Authorize.net's 20-character invoice number is refused. */
    public function test_invoice_prefix_must_leave_room_for_the_number(): void
    {
        $this->reconfigure(['invoice_prefix' => 'ACME-INV-26-']); // 12: fine
        $this->expectException(CleatException::class);
        $this->reconfigure(['invoice_prefix' => 'ACME-INVOICE-2026-']);
    }

    private function openInvoice(): Invoice
    {
        return Customer::create(['email' => 'payer@example.com', 'name' => 'Payer'])->newInvoice()->addItem('Work', 4200)->send();
    }

    private function paySubmit(Invoice $invoice, array $opaque): \Cleat\Http\Response
    {
        $csrf = (new Csrf(self::APP_KEY))->issue($invoice->public_token, 's', $this->clock->now());
        return (new InvoicePayPage('s'))->submit($invoice->public_token, ['_csrf' => $csrf, 'payment_method' => 'new'] + $opaque, '198.51.100.1');
    }

    private function checkoutSubmit(PaymentLink $link, string $email, string $token): \Cleat\Http\Response
    {
        $csrf = (new Csrf(self::APP_KEY))->issue($link->public_token, 's', $this->clock->now());
        return (new CheckoutPage('s'))->submit($link->public_token, ['_csrf' => $csrf, 'email' => $email, 'name' => 'B'] + self::opaque($token), '198.51.100.2');
    }

    private function webhook(string $type, array $payload, ?string $id = null): string
    {
        return json_encode(['notificationId' => $id ?? 'n-' . bin2hex(random_bytes(5)), 'eventType' => $type, 'payload' => $payload], JSON_UNESCAPED_SLASHES);
    }

    private function sign(string $body): string
    {
        return 'sha512=' . strtoupper(hash_hmac('sha512', $body, hex2bin(self::SIGNATURE_KEY)));
    }
}
