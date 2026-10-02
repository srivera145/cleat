<?php

declare(strict_types=1);

namespace Cleat\Tests\Feature;

use Cleat\Customer;
use Cleat\Enums\InvoiceStatus;
use Cleat\Enums\SubscriptionStatus;
use Cleat\Events\InvoicePaid;
use Cleat\Events\PaymentFailed;
use Cleat\Events\PaymentSucceeded;
use Cleat\Events\SubscriptionCanceled;
use Cleat\Events\SubscriptionPastDue;
use Cleat\Http\InvoicePayPage;
use Cleat\Invoice;
use Cleat\Subscription;
use Cleat\Tests\Support\TestCase;

final class DunningTest extends TestCase
{
    private Customer $jane;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->monthlyPrice(amount: 2900);
        $this->at('2026-01-01 00:00:00');
        $this->jane = $this->customer();
        $this->subscription = $this->jane->newSubscription('premium-monthly')->create();
        $this->assertSame(SubscriptionStatus::Active, $this->subscription->status);
        // From now on the saved card declines.
        $this->gateway->setPaymentProfileToken($this->jane->gateway_customer_id, $this->jane->gateway_payment_id, 'tok_decline');
        $this->mailer->clear();
        $this->events = [];
    }

    /** Self-check 6: past_due, retries at +1/+3/+7 days, email with a working pay URL, final failure -> unpaid. */
    public function test_decline_with_dunning_enabled_runs_the_full_schedule(): void
    {
        $this->runAt('2026-02-01 00:00:00');

        $invoice = $this->subscription->refresh()->latestInvoice();
        $this->assertSame(SubscriptionStatus::PastDue, $this->subscription->status);
        $this->assertTrue($this->subscription->pastDue());
        $this->assertFalse($this->subscription->active(), 'grace_days is 0');
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame(1, $invoice->attempt_count);
        $this->assertSame('2026-02-02 00:00:00', $invoice->next_attempt_at->format('Y-m-d H:i:s'));

        $failed = $this->eventsOf(PaymentFailed::class);
        $this->assertCount(1, $failed);
        $this->assertSame(1, $failed[0]->attemptNumber);
        $this->assertTrue($failed[0]->willRetry);
        $this->assertCount(1, $this->eventsOf(SubscriptionPastDue::class));

        // The failure email carries a working pay URL.
        $mail = $this->mailer->last();
        $this->assertSame('jane@example.com', $mail['to']);
        $this->assertStringContainsString('Payment failed', $mail['subject']);
        $link = self::payLinkIn($mail['html']);
        $this->assertNotNull($link, 'payment_failed email contains the pay link');
        $this->assertSame($invoice->paymentUrl(), $link);
        $token = basename($link);
        $page = (new InvoicePayPage('sess'))->show($token);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Pay $29.00', $page->body);
        $this->assertStringContainsString('February 2, 2026', $mail['html'], 'next retry date');

        // Not due yet: nothing happens.
        $this->assertSame(0, $this->runAt('2026-02-01 23:59:00')->retries);

        // +1 day
        $this->runAt('2026-02-02 00:00:00');
        $invoice->refresh();
        $this->assertSame(2, $invoice->attempt_count);
        $this->assertSame('2026-02-04 00:00:00', $invoice->next_attempt_at->format('Y-m-d H:i:s'));

        $this->assertSame(0, $this->runAt('2026-02-03 12:00:00')->retries);

        // +3 days
        $this->runAt('2026-02-04 00:00:00');
        $invoice->refresh();
        $this->assertSame(3, $invoice->attempt_count);
        $this->assertSame('2026-02-08 00:00:00', $invoice->next_attempt_at->format('Y-m-d H:i:s'));

        // +7 days: the last retry fails.
        $this->runAt('2026-02-08 00:00:00');
        $invoice->refresh();
        $this->subscription->refresh();
        $this->assertSame(4, $invoice->attempt_count);
        $this->assertSame(InvoiceStatus::Uncollectible, $invoice->status);
        $this->assertNull($invoice->next_attempt_at);
        $this->assertSame(SubscriptionStatus::Unpaid, $this->subscription->status);

        $failed = $this->eventsOf(PaymentFailed::class);
        $this->assertSame([1, 2, 3, 4], array_map(static fn (PaymentFailed $e) => $e->attemptNumber, $failed));
        $this->assertSame([true, true, true, false], array_map(static fn (PaymentFailed $e) => $e->willRetry, $failed));
        $pastDue = $this->eventsOf(SubscriptionPastDue::class);
        $this->assertCount(2, $pastDue, 'once on entering past_due, once on becoming unpaid');
        $this->assertSame(SubscriptionStatus::Unpaid, $pastDue[1]->subscription->status);

        $this->assertSame(4, $this->rows('cleat_charges', "invoice_id = ? AND status = 'failed'", [$invoice->id]));
        $this->assertSame(['inv_' . $invoice->id . '_attempt_1', 'inv_' . $invoice->id . '_attempt_4'], [
            $this->pdo->query("SELECT idempotency_key FROM cleat_charges WHERE invoice_id = {$invoice->id} ORDER BY id LIMIT 1")->fetchColumn(),
            $this->pdo->query("SELECT idempotency_key FROM cleat_charges WHERE invoice_id = {$invoice->id} ORDER BY id DESC LIMIT 1")->fetchColumn(),
        ]);
        $this->assertCount(3, $this->mailer->sent, 'one email per scheduled retry; none after the final failure');

        // No more attempts, ever.
        $this->assertSame(0, $this->runAt('2026-03-01 00:00:00')->retries);
        $this->assertSame(0, $this->runAt('2026-03-01 00:00:00')->renewals, 'unpaid subscriptions do not renew');

        // The uncollectible invoice's link is gone.
        $this->assertSame(410, (new InvoicePayPage('sess'))->show($token)->status);
    }

    public function test_final_action_canceled(): void
    {
        $this->reconfigure(['automation' => ['final_action' => 'canceled', 'retry_attempts' => [2]]]);
        $this->runAt('2026-02-01 00:00:00');
        $this->runAt('2026-02-03 00:00:00');
        $this->subscription->refresh();
        $this->assertSame(SubscriptionStatus::Canceled, $this->subscription->status);
        $this->assertNotNull($this->subscription->canceled_at);
        $this->assertCount(1, $this->eventsOf(SubscriptionCanceled::class));
    }

    public function test_successful_retry_reactivates_and_sends_receipt(): void
    {
        $this->runAt('2026-02-01 00:00:00');
        $this->assertSame(SubscriptionStatus::PastDue, $this->subscription->refresh()->status);
        $this->gateway->setPaymentProfileToken($this->jane->gateway_customer_id, $this->jane->gateway_payment_id, 'tok_visa');
        $this->mailer->clear();

        $report = $this->runAt('2026-02-02 00:00:00');

        $this->assertSame(1, $report->retries);
        $this->assertSame(1, $report->paymentsSucceeded);
        $invoice = $this->subscription->refresh()->latestInvoice();
        $this->assertSame(SubscriptionStatus::Active, $this->subscription->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNull($invoice->next_attempt_at);
        $this->assertCount(1, $this->eventsOf(PaymentSucceeded::class));
        $this->assertCount(1, $this->eventsOf(InvoicePaid::class));
        $this->assertStringStartsWith('Receipt from Acme Tools', $this->mailer->last()['subject']);
    }

    /** Self-check 7: dunning disabled changes nothing, schedules nothing, sends nothing, but still fires PaymentFailed. */
    public function test_decline_with_dunning_disabled_only_records_and_fires(): void
    {
        $this->reconfigure(['automation' => ['dunning_enabled' => false]]);

        $report = $this->runAt('2026-02-01 00:00:00');

        $this->assertSame(1, $report->renewals);
        $this->assertSame(1, $report->paymentsFailed);
        $this->subscription->refresh();
        $invoice = $this->subscription->latestInvoice();
        $this->assertSame(SubscriptionStatus::Active, $this->subscription->status, 'status unchanged');
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertNull($invoice->next_attempt_at, 'nothing scheduled, the crash-recovery lease is cleared');
        $this->assertSame(1, $this->rows('cleat_charges', "invoice_id = ? AND status = 'failed'", [$invoice->id]), 'the charge is recorded');

        $failed = $this->eventsOf(PaymentFailed::class);
        $this->assertCount(1, $failed);
        $this->assertFalse($failed[0]->willRetry);
        $this->assertSame([], $this->eventsOf(SubscriptionPastDue::class));
        $this->assertSame([], $this->mailer->sent, 'no email');

        // Nothing retries later either.
        $charges = $this->gateway->chargeCount();
        $this->runAt('2026-02-02 01:00:00');
        $this->runAt('2026-02-09 00:00:00');
        $this->assertSame($charges, $this->gateway->chargeCount());

        // The pay link still works if the developer chooses to send it.
        $this->assertSame(200, (new InvoicePayPage('sess'))->show($invoice->public_token)->status);
    }

    public function test_trial_without_card_goes_past_due_with_pay_link_email(): void
    {
        $guest = Customer::create(['email' => 'nocard@example.com']);
        $this->at('2026-01-01 00:00:00');
        $trial = $guest->newSubscription('premium-monthly')->withTrialDays(7)->create();
        $this->mailer->clear();
        $chargesBefore = $this->gateway->chargeCount();

        $this->runAt('2026-01-08 00:00:00');

        $trial->refresh();
        $this->assertSame(SubscriptionStatus::PastDue, $trial->status);
        $invoice = $trial->latestInvoice();
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $charge = $invoice->charges()[0];
        $this->assertSame('no_payment_method', $charge->reason_code);
        $this->assertSame($chargesBefore, $this->gateway->chargeCount(), 'no card, so no gateway call');
        $this->assertNotNull(self::payLinkIn($this->mailer->last()['html']));
    }

    public function test_grace_days_keep_a_past_due_subscription_active(): void
    {
        $this->reconfigure(['grace_days' => 3]);
        $this->runAt('2026-02-01 00:00:00');
        $this->subscription->refresh();
        $this->assertSame(SubscriptionStatus::PastDue, $this->subscription->status);
        $this->at('2026-02-03 23:00:00');
        $this->assertTrue($this->subscription->active());
        $this->at('2026-02-04 00:00:01');
        $this->assertFalse($this->subscription->active());
    }

    public function test_customer_attempts_on_the_pay_page_do_not_advance_the_schedule(): void
    {
        $this->runAt('2026-02-01 00:00:00');
        $invoice = Invoice::findOrFail((int) $this->subscription->refresh()->latestInvoice()->id);
        $this->at('2026-02-01 10:00:00');
        try {
            $invoice->payWithToken(self::opaque('tok_decline'), false, '203.0.113.9');
        } catch (\Cleat\Exceptions\PaymentFailedException) {
        }
        $invoice->refresh();
        $this->assertSame(1, $invoice->attempt_count);
        $this->assertSame('2026-02-02 00:00:00', $invoice->next_attempt_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, $this->rows('cleat_charges', 'invoice_id = ?', [$invoice->id]));
        $this->assertSame(1, $this->rows('cleat_charges', 'idempotency_key = ?', ['inv_' . $invoice->id . '_customer_1']));
    }

    public function test_timeout_is_recorded_as_failed_and_not_retried_in_the_same_run(): void
    {
        $this->gateway->setPaymentProfileToken($this->jane->gateway_customer_id, $this->jane->gateway_payment_id, 'tok_timeout');
        $chargesBefore = $this->gateway->chargeCount();
        $report = $this->runAt('2026-02-01 00:00:00');
        $this->assertSame(1, $report->paymentsFailed);
        $this->assertSame(0, $report->retries);
        $charge = $this->subscription->refresh()->latestInvoice()->charges()[0];
        $this->assertSame('failed', $charge->status->value);
        $this->assertSame('timeout', $charge->reason_code);
        $this->assertSame($chargesBefore + 1, $this->gateway->chargeCount(), 'one attempt, no retry in the same run');
        $this->assertNotEmpty(array_filter($this->logs, static fn ($l) => str_contains($l['message'], 'outcome unknown')));
    }
}
