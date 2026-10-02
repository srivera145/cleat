<?php

declare(strict_types=1);

namespace Cleat\Tests\Feature;

use Cleat\Customer;
use Cleat\Enums\ChargeSource;
use Cleat\Enums\InvoiceStatus;
use Cleat\Enums\SubscriptionStatus;
use Cleat\Events\InvoiceCreated;
use Cleat\Events\InvoicePaid;
use Cleat\Events\InvoiceSent;
use Cleat\Events\PaymentSucceeded;
use Cleat\Http\InvoicePayPage;
use Cleat\Http\Response;
use Cleat\Invoice;
use Cleat\Tests\Support\TestCase;

final class InvoicePayPageTest extends TestCase
{
    private const SESSION = 'host-session-123';
    private const IP = '198.51.100.7';

    /** Self-check 9, part 1: send() emails a pay link and charges nothing. */
    public function test_send_emails_a_pay_link_without_charging(): void
    {
        $this->oneTimePrice('setup-fee', 5000);
        $customer = Customer::create(['email' => 'client@example.com', 'name' => 'Client Co']);

        $invoice = $customer->newInvoice()
            ->addItem('Consulting, March', 120000)
            ->addPrice('setup-fee', 2)
            ->memo('Thanks for your business.')
            ->dueIn(14)
            ->send();

        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame('CLT-000001', $invoice->number);
        $this->assertSame(130000, $invoice->total);
        $this->assertSame('2026-01-29', $invoice->due_at->format('Y-m-d'));
        $this->assertNotNull($invoice->sent_at);
        $this->assertSame(0, $this->gateway->chargeCount(), 'send() never charges');
        $this->assertCount(1, $this->eventsOf(InvoiceCreated::class));
        $this->assertCount(1, $this->eventsOf(InvoiceSent::class));

        $mail = $this->mailer->last();
        $this->assertSame('client@example.com', $mail['to']);
        $this->assertSame('Invoice CLT-000001 from Acme Tools', $mail['subject']);
        $this->assertSame($invoice->paymentUrl(), self::payLinkIn($mail['html']));
        $this->assertStringContainsString('$1,300.00', $mail['html']);
        $this->assertStringContainsString('Pay online: ' . $invoice->paymentUrl(), $mail['text']);
        $this->assertStringContainsString('v:roundrect', $mail['html'], 'bulletproof button for Outlook');
    }

    /** Self-check 9, part 2: pay with a new token, without saving it. */
    public function test_pay_with_new_card_without_saving(): void
    {
        $invoice = $this->sentInvoice();
        $response = $this->submit($invoice, ['payment_method' => 'new'] + self::opaque('tok_visa'));

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Payment received. Thank you!', $response->body);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $charge = $invoice->charges()[0];
        $this->assertSame(ChargeSource::OneTimeToken, $charge->source);
        $this->assertSame(self::IP, $charge->ip_address);
        $this->assertSame('inv_' . $invoice->id . '_customer_1', $charge->idempotency_key);
        $this->assertFalse($invoice->customer()->hasPaymentMethod(), 'card not saved');
        $this->assertSame([], $this->gateway->callsTo('createPaymentProfile'));
        $this->assertCount(1, $this->eventsOf(PaymentSucceeded::class));
        $this->assertCount(1, $this->eventsOf(InvoicePaid::class));
    }

    /** Self-check 9, part 3: pay with a new token and save it to the CIM profile. */
    public function test_pay_with_new_card_and_save_it(): void
    {
        $invoice = $this->sentInvoice();
        $response = $this->submit($invoice, ['payment_method' => 'new', 'save_card' => '1'] + self::opaque('tok_mastercard'));

        $this->assertSame(200, $response->status);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(ChargeSource::StoredProfile, $invoice->charges()[0]->source);
        $customer = $invoice->customer();
        $this->assertTrue($customer->hasPaymentMethod());
        $this->assertSame('Mastercard ending 4444', $customer->paymentMethodLabel());
        $this->assertSame('2030-12', $customer->card_exp);
    }

    /** Self-check 9, part 4: pay with the saved card. */
    public function test_pay_with_saved_card(): void
    {
        $customer = $this->customer('saved@example.com', 'tok_visa');
        $invoice = $customer->newInvoice()->addItem('Retainer', 25000)->send();

        $page = (new InvoicePayPage(self::SESSION))->show($invoice->public_token);
        $this->assertStringContainsString('Pay with Visa ending 4242', $page->body);
        $this->assertStringContainsString('Save this card for future payments', $page->body);

        $response = $this->submit($invoice, ['payment_method' => 'saved']);
        $this->assertSame(200, $response->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(ChargeSource::StoredProfile, $invoice->charges()[0]->source);
        $this->assertSame(25000, $this->gateway->callsTo('chargeProfile')[0]['args']['amount']);
    }

    /** Self-check 9, part 5: a past_due subscription returns to active when its invoice is paid on the page. */
    public function test_paying_a_past_due_invoice_reactivates_the_subscription(): void
    {
        $this->monthlyPrice();
        $this->at('2026-01-01 00:00:00');
        $customer = $this->customer('late@example.com');
        $subscription = $customer->newSubscription('premium-monthly')->create();
        $this->gateway->setPaymentProfileToken($customer->gateway_customer_id, $customer->gateway_payment_id, 'tok_decline');
        $this->runAt('2026-02-01 00:00:00');
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->refresh()->status);
        $invoice = $subscription->latestInvoice();

        $this->at('2026-02-01 09:00:00');
        $response = $this->submit($invoice, ['payment_method' => 'new', 'save_card' => '1'] + self::opaque('tok_visa'));

        $this->assertSame(200, $response->status);
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNull($invoice->next_attempt_at, 'scheduled retry cleared');
        $this->assertSame(0, $this->runAt('2026-02-02 00:00:00')->retries);
    }

    /** Self-check 10: expired, void and paid links return 410, 410 and the already-paid view. */
    public function test_expired_void_and_paid_links(): void
    {
        $expired = $this->sentInvoice();
        $void = $this->sentInvoice('void@example.com');
        $paid = $this->sentInvoice('paid@example.com');
        $void->void();
        $this->submit($paid, ['payment_method' => 'new'] + self::opaque('tok_visa'));

        $page = new InvoicePayPage(self::SESSION);
        $this->assertSame(410, $page->show($void->public_token)->status);
        $paidView = $page->show($paid->public_token);
        $this->assertSame(200, $paidView->status);
        $this->assertStringContainsString('This invoice is paid', $paidView->body);
        $this->assertStringNotContainsString('cleat-pay-form', $paidView->body);
        $this->assertNull($paid->refresh()->paymentUrl(), 'paid invoices have no pay URL');
        $this->assertNull($void->refresh()->paymentUrl());

        $this->assertSame(200, $page->show($expired->public_token)->status);
        $this->at('2026-02-14 12:00:01'); // invoice_link_days = 30 from Jan 15 12:00
        $gone = $page->show($expired->public_token);
        $this->assertSame(410, $gone->status);
        $this->assertStringContainsString('This link has expired', $gone->body);
        $this->assertSame(410, $this->submit($expired, ['payment_method' => 'new'] + self::opaque('tok_visa'), csrfFromShow: false)->status);
        $chargedExpired = array_filter($this->gateway->callsTo('chargeToken'), static fn ($c) => $c['args']['invoice_number'] === $expired->number);
        $this->assertSame([], $chargedExpired, 'an expired link is never charged');
    }

    public function test_uncollectible_draft_and_unknown_tokens(): void
    {
        $page = new InvoicePayPage(self::SESSION);
        $this->assertSame(404, $page->show(str_repeat('a', 64))->status);
        $this->assertSame(404, $page->show('not-a-token')->status);
        $this->assertSame(404, $page->show('../../etc/passwd')->status);

        $customer = Customer::create(['email' => 'x@example.com']);
        $draft = $customer->newInvoice()->addItem('Draft only', 100)->draft();
        $this->assertNull($draft->number, 'drafts have no number yet');
        $this->assertSame(404, $page->show($draft->public_token)->status);

        $open = $this->sentInvoice('u@example.com');
        $open->markUncollectible();
        $this->assertSame(410, $page->show($open->public_token)->status);
    }

    public function test_decline_shows_a_generic_message_and_never_the_gateway_reason(): void
    {
        $invoice = $this->sentInvoice();
        $response = $this->submit($invoice, ['payment_method' => 'new'] + self::opaque('tok_insufficient'));

        $this->assertSame(402, $response->status);
        $this->assertStringContainsString('Your card was declined.', $response->body);
        $this->assertStringNotContainsString('insufficient funds', $response->body);
        $this->assertStringContainsString('cleat-pay-form', $response->body, 'form re-rendered');
        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);
        $this->assertSame('insufficient funds', $invoice->charges()[0]->reason_text, 'kept server side');
    }

    public function test_held_payment_shows_review_and_does_not_mark_paid(): void
    {
        $invoice = $this->sentInvoice();
        $response = $this->submit($invoice, ['payment_method' => 'new'] + self::opaque('tok_held'));
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Payment received for review', $response->body);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame('held', $invoice->charges()[0]->status->value);

        // A second attempt is refused while the held charge is unresolved.
        $again = $this->submit($invoice, ['payment_method' => 'new'] + self::opaque('tok_visa'));
        $this->assertSame(409, $again->status);
        $this->assertSame(1, $this->gateway->chargeCount());
    }

    public function test_security_headers_and_csp(): void
    {
        $invoice = $this->sentInvoice();
        $response = (new InvoicePayPage(self::SESSION))->show($invoice->public_token);

        $this->assertSame('no-store', $response->header('Cache-Control'));
        $this->assertSame('DENY', $response->header('X-Frame-Options'));
        $this->assertSame('no-referrer', $response->header('Referrer-Policy'));
        $csp = (string) $response->header('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString('https://jstest.authorize.net', $csp);
        $this->assertStringContainsString('https://cdn.jsdelivr.net', $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        preg_match("/'nonce-([A-Za-z0-9_-]+)'/", $csp, $m);
        $this->assertNotEmpty($m[1] ?? null);
        $this->assertStringContainsString('<script nonce="' . $m[1] . '">', $response->body, 'inline script carries the nonce');
        $this->assertStringContainsString('<style nonce="' . $m[1] . '">', $response->body);
    }

    public function test_paying_twice_never_charges_twice(): void
    {
        $invoice = $this->sentInvoice();
        $this->submit($invoice, ['payment_method' => 'new'] + self::opaque('tok_visa'));
        $second = $this->submit($invoice, ['payment_method' => 'new'] + self::opaque('tok_visa'), csrfFromShow: false);
        $this->assertSame(200, $second->status);
        $this->assertStringContainsString('This invoice is paid', $second->body);
        $this->assertSame(1, $this->gateway->chargeCount());
    }

    private function sentInvoice(string $email = 'client@example.com'): Invoice
    {
        $customer = Customer::create(['email' => $email, 'name' => 'Client']);
        return $customer->newInvoice()->addItem('Design work', 45000)->dueIn(14)->send();
    }

    private function submit(Invoice $invoice, array $post, bool $csrfFromShow = true): Response
    {
        $page = new InvoicePayPage(self::SESSION);
        if ($csrfFromShow) {
            $html = $page->show($invoice->public_token)->body;
            preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
            $post['_csrf'] = $m[1] ?? '';
        } else {
            $post['_csrf'] = (new \Cleat\Http\Csrf(self::APP_KEY))->issue($invoice->public_token, self::SESSION, $this->clock->now());
        }
        return (new InvoicePayPage(self::SESSION))->submit($invoice->public_token, $post, self::IP);
    }
}
