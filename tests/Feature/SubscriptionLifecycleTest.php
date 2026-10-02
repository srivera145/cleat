<?php

declare(strict_types=1);

namespace Cleat\Tests\Feature;

use Cleat\Enums\InvoiceStatus;
use Cleat\Enums\SubscriptionStatus;
use Cleat\Events\InvoicePaid;
use Cleat\Events\PaymentSucceeded;
use Cleat\Events\SubscriptionCanceled;
use Cleat\Events\SubscriptionCreated;
use Cleat\Exceptions\PaymentFailedException;
use Cleat\Subscription;
use Cleat\Tests\Support\TestCase;

final class SubscriptionLifecycleTest extends TestCase
{
    /** Self-check 3: a 7-day trial is converted, charged and activated by the Runner on day 7. */
    public function test_seven_day_trial_converts_on_day_seven(): void
    {
        $this->monthlyPrice(amount: 2900);
        $customer = $this->customer();
        $this->at('2026-03-01 09:00:00');

        $subscription = $customer->newSubscription('premium-monthly')->withTrialDays(7)->create();

        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertTrue($subscription->onTrial());
        $this->assertTrue($subscription->active());
        $this->assertSame('2026-03-08 09:00:00', $subscription->trial_ends_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-08 09:00:00', $subscription->current_period_end->format('Y-m-d H:i:s'));
        $this->assertSame(0, $this->gateway->chargeCount(), 'no charge during a trial');
        $this->assertCount(1, $this->eventsOf(SubscriptionCreated::class));

        // Day 6: nothing happens.
        $report = $this->runAt('2026-03-07 09:00:00');
        $this->assertSame(0, $report->trialsConverted);
        $this->assertSame(0, $this->gateway->chargeCount());

        // Day 7: converted, charged, active, new period.
        $report = $this->runAt('2026-03-08 09:15:00');
        $this->assertSame(1, $report->trialsConverted);
        $this->assertSame(1, $report->paymentsSucceeded);
        $this->assertSame([], $report->errors);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertFalse($subscription->onTrial());
        $this->assertSame('2026-03-08 09:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-08 09:00:00', $subscription->current_period_end->format('Y-m-d H:i:s'));
        $this->assertSame(8, $subscription->anchor_day);

        $invoice = $subscription->latestInvoice();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(2900, $invoice->amount_paid);
        $this->assertSame('CLT-000001', $invoice->number);
        $this->assertSame(1, $this->gateway->chargeCount());
        $this->assertCount(1, $this->eventsOf(PaymentSucceeded::class));
        $this->assertCount(1, $this->eventsOf(InvoicePaid::class));
        $this->assertCount(1, $this->mailer->to('jane@example.com'), 'receipt email');
    }

    /** Self-check 4: a monthly subscription started Jan 31 renews Feb 28 (29 in a leap year), then Mar 31. */
    public function test_month_end_anchor_does_not_drift(): void
    {
        $this->monthlyPrice(amount: 1000);
        $customer = $this->customer();
        $this->at('2026-01-31 10:00:00');
        $subscription = $customer->newSubscription('premium-monthly')->create();

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(31, $subscription->anchor_day);
        $this->assertSame('2026-02-28 10:00:00', $subscription->current_period_end->format('Y-m-d H:i:s'));

        $this->runAt('2026-02-28 10:00:00');
        $subscription->refresh();
        $this->assertSame('2026-02-28 10:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-31 10:00:00', $subscription->current_period_end->format('Y-m-d H:i:s'));

        $this->runAt('2026-03-31 10:00:00');
        $subscription->refresh();
        $this->assertSame('2026-04-30 10:00:00', $subscription->current_period_end->format('Y-m-d H:i:s'));

        $this->runAt('2026-04-30 10:00:00');
        $subscription->refresh();
        $this->assertSame('2026-05-31 10:00:00', $subscription->current_period_end->format('Y-m-d H:i:s'));

        $this->assertSame(4, $this->rows('cleat_invoices', "status = 'paid'"));
        $this->assertSame(4, $this->gateway->chargeCount());
    }

    public function test_month_end_anchor_in_a_leap_year(): void
    {
        $this->monthlyPrice(amount: 1000);
        $customer = $this->customer();
        $this->at('2028-01-31 00:00:00');
        $subscription = $customer->newSubscription('premium-monthly')->create();
        $this->assertSame('2028-02-29 00:00:00', $subscription->current_period_end->format('Y-m-d H:i:s'));

        $this->runAt('2028-02-29 00:00:00');
        $this->assertSame('2028-03-31 00:00:00', $subscription->refresh()->current_period_end->format('Y-m-d H:i:s'));
    }

    /** Self-check 8: running the Runner twice at the same $now produces no duplicate invoices or charges. */
    public function test_runner_twice_at_the_same_time_is_idempotent(): void
    {
        $this->monthlyPrice(amount: 2900);
        $this->at('2026-01-01 00:00:00');
        $a = $this->customer('a@example.com')->newSubscription('premium-monthly')->create();
        $b = $this->customer('b@example.com')->newSubscription('premium-monthly')->withTrialDays(45)->create();
        $c = $this->customer('c@example.com', 'tok_decline');
        try {
            $c->newSubscription('premium-monthly')->create();
        } catch (PaymentFailedException) {
        }

        $before = [$this->rows('cleat_invoices'), $this->rows('cleat_charges'), $this->gateway->chargeCount()];

        $first = $this->runAt('2026-02-01 00:00:00');
        $afterFirst = [$this->rows('cleat_invoices'), $this->rows('cleat_charges'), $this->gateway->chargeCount()];
        $second = (new \Cleat\Billing\Runner())->run($this->clock->now());
        $afterSecond = [$this->rows('cleat_invoices'), $this->rows('cleat_charges'), $this->gateway->chargeCount()];

        $this->assertSame(1, $first->renewals, 'only the active subscription renews; trial has not ended');
        $this->assertSame(0, $second->renewals + $second->trialsConverted + $second->retries);
        $this->assertSame([$before[0] + 1, $before[1] + 1, $before[2] + 1], $afterFirst);
        $this->assertSame($afterFirst, $afterSecond, 'second run at the same $now changes nothing');

        // The trial converts once even when the Runner is run twice at its end.
        $this->runAt('2026-02-15 00:00:00');
        $this->runAt('2026-02-15 00:00:00');
        $this->assertSame(1, $this->rows('cleat_invoices', 'subscription_id = ? AND period_start = ?', [$b->id, '2026-02-15 00:00:00']));
        $this->assertSame(SubscriptionStatus::Active, $b->refresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $a->refresh()->status);
    }

    public function test_cancel_at_period_end_then_runner_cancels(): void
    {
        $this->monthlyPrice();
        $this->at('2026-01-10 08:00:00');
        $subscription = $this->customer()->newSubscription('premium-monthly')->create();
        $subscription->cancel();

        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertTrue($subscription->onGracePeriod());
        $this->assertTrue($subscription->active());
        $this->assertSame('2026-02-10 08:00:00', $subscription->ends_at->format('Y-m-d H:i:s'));

        $subscription->resume();
        $this->assertFalse($subscription->cancel_at_period_end);
        $this->assertNull($subscription->ends_at);
        $subscription->cancel();

        $report = $this->runAt('2026-02-10 08:00:00');
        $this->assertSame(1, $report->cancellations);
        $this->assertSame(0, $report->renewals, 'a subscription set to cancel never renews');
        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->status);
        $this->assertFalse($subscription->active());
        $this->assertCount(1, $this->eventsOf(SubscriptionCanceled::class));
        $this->assertSame(1, $this->gateway->chargeCount());
    }

    public function test_resume_is_refused_after_the_period_ends(): void
    {
        $this->monthlyPrice();
        $this->at('2026-01-10 08:00:00');
        $subscription = $this->customer()->newSubscription('premium-monthly')->create()->cancel();
        $this->at('2026-02-11 00:00:00');
        $this->expectException(\Cleat\Exceptions\CleatException::class);
        $subscription->resume();
    }

    public function test_cancel_now_stops_retries_and_fires_event(): void
    {
        $this->monthlyPrice();
        $subscription = $this->customer()->newSubscription('premium-monthly')->create();
        $subscription->cancelNow();
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->status);
        $this->assertNotNull($subscription->canceled_at);
        $this->assertCount(1, $this->eventsOf(SubscriptionCanceled::class));
        $this->assertSame(0, $this->runAt('2026-03-01 00:00:00')->renewals);
    }

    public function test_initial_decline_leaves_subscription_incomplete_with_pay_url(): void
    {
        $this->monthlyPrice();
        $customer = $this->customer('decline@example.com', 'tok_decline');
        try {
            $customer->newSubscription('premium-monthly')->create();
            $this->fail('expected PaymentFailedException');
        } catch (PaymentFailedException $e) {
            $this->assertTrue($e->result->isDeclined());
            $this->assertSame(2, $e->result->responseCode);
            $this->assertSame(InvoiceStatus::Open, $e->invoice->status);
            $this->assertMatchesRegularExpression('#^https://billing\.test/pay/[a-f0-9]{64}$#', $e->payUrl);
        }
        $subscription = Subscription::fromRow($this->pdo->query('SELECT * FROM cleat_subscriptions')->fetch(\PDO::FETCH_ASSOC));
        $this->assertSame(SubscriptionStatus::Incomplete, $subscription->status);
        $this->assertFalse($subscription->active());
        $this->assertSame(0, $this->rows('cleat_invoices', 'next_attempt_at IS NOT NULL'), 'first payments never enter dunning');
    }
}
