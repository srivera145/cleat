<?php

declare(strict_types=1);

namespace Cleat\Tests\Feature;

use Cleat\Enums\InvoiceStatus;
use Cleat\Enums\SubscriptionStatus;
use Cleat\Exceptions\CleatException;
use Cleat\Price;
use Cleat\Tests\Support\TestCase;
use InvalidArgumentException;

/** 0.1.3: swapAtPeriodEnd(), the usual way to downgrade, applied by the Runner as it rolls the period. */
final class PeriodEndChangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $product = $this->product('Plan');
        $this->monthlyPrice('plan-monthly', 1200, product: $product);
        Price::create($product, [
            'lookup_key' => 'plan-yearly', 'amount' => 12000, 'billing_type' => 'recurring',
            'billing_interval' => 'year', 'nickname' => 'Yearly',
        ]);
        $this->monthlyPrice('lite-monthly', 500, product: $product);
    }

    public function test_yearly_to_monthly_waits_for_the_period_end_and_charges_nothing_now(): void
    {
        $this->at('2026-01-10 08:00:00');
        $subscription = $this->customer()->newSubscription('plan-yearly')->create();
        $this->assertSame(1, $this->gateway->chargeCount());

        $this->at('2026-06-01 00:00:00');
        $subscription->swapAtPeriodEnd('plan-monthly');

        $this->assertSame('plan-yearly', $subscription->price()->lookup_key, 'the current price stays until the period ends');
        $this->assertSame('plan-monthly', $subscription->scheduledPrice()?->lookup_key);
        $this->assertSame(1, $this->gateway->chargeCount(), 'nothing charged or credited now');
        $this->assertSame(0, $this->rows('cleat_invoice_items', 'is_proration = 1'), 'no proration lines');

        // Before the period ends nothing happens.
        $report = $this->runAt('2027-01-09 08:00:00');
        $this->assertSame(0, $report->renewals);

        $report = $this->runAt('2027-01-10 08:00:00');
        $this->assertSame(1, $report->renewals);
        $this->assertSame([], $report->errors);
        $subscription->refresh();
        $this->assertSame('plan-monthly', $subscription->price()->lookup_key);
        $this->assertNull($subscription->scheduled_price_id);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame('2027-01-10 08:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2027-02-10 08:00:00', $subscription->current_period_end->format('Y-m-d H:i:s'), 'the new period follows the new interval');

        $invoice = $subscription->latestInvoice();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(1200, $invoice->total);
        $this->assertSame(1200, $this->gateway->callsTo('chargeProfile')[1]['args']['amount']);

        // The next renewal is monthly at the new price.
        $this->runAt('2027-02-10 08:00:00');
        $this->assertSame('2027-03-10 08:00:00', $subscription->refresh()->current_period_end->format('Y-m-d H:i:s'));
        $this->assertSame(3, $this->gateway->chargeCount());
    }

    public function test_a_scheduled_change_applies_when_a_trial_converts(): void
    {
        $this->at('2026-03-01 09:00:00');
        $subscription = $this->customer()->newSubscription('plan-monthly')->withTrialDays(14)->create();
        $subscription->swapAtPeriodEnd('lite-monthly');

        $this->runAt('2026-03-15 09:00:00');
        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame('lite-monthly', $subscription->price()->lookup_key);
        $this->assertSame(500, $subscription->latestInvoice()->total);
        $this->assertNull($subscription->scheduled_price_id);
    }

    public function test_an_immediate_swap_cancel_or_the_current_price_clears_a_scheduled_change(): void
    {
        $this->at('2026-01-01 00:00:00');
        $subscription = $this->customer()->newSubscription('plan-monthly')->create();

        $subscription->swapAtPeriodEnd('lite-monthly');
        $subscription->swapAtPeriodEnd('plan-monthly');
        $this->assertNull($subscription->scheduled_price_id, 'asking for the current price clears it');

        $subscription->swapAtPeriodEnd('lite-monthly');
        $subscription->cancelScheduledSwap();
        $this->assertNull($subscription->scheduled_price_id);

        $subscription->swapAtPeriodEnd('lite-monthly');
        $this->at('2026-01-16 12:00:00');
        $subscription->swap('plan-yearly');
        $this->assertNull($subscription->scheduled_price_id, 'an immediate change replaces it');
        $this->assertSame('plan-yearly', $subscription->price()->lookup_key);

        $subscription->swapAtPeriodEnd('plan-monthly');
        $subscription->cancel();
        $this->assertNull($subscription->scheduled_price_id, 'cancel at period end drops it');
        $subscription->resume();
        $this->assertNull($subscription->scheduled_price_id, 'resume keeps the current price');
        $this->assertSame('plan-yearly', $subscription->price()->lookup_key);

        $subscription->swapAtPeriodEnd('plan-monthly');
        $subscription->cancelNow();
        $this->assertNull($subscription->scheduled_price_id);
    }

    public function test_refused_while_canceling_or_not_active(): void
    {
        $this->at('2026-01-01 00:00:00');
        $subscription = $this->customer()->newSubscription('plan-monthly')->create();
        $subscription->cancel();
        try {
            $subscription->swapAtPeriodEnd('lite-monthly');
            $this->fail('a subscription set to cancel must be resumed first');
        } catch (CleatException $e) {
            $this->assertStringContainsString('Resume it', $e->getMessage());
        }

        $subscription->cancelNow();
        $this->expectException(CleatException::class);
        $subscription->swapAtPeriodEnd('lite-monthly');
    }

    public function test_refuses_a_one_time_or_foreign_currency_price(): void
    {
        $this->at('2026-01-01 00:00:00');
        $subscription = $this->customer()->newSubscription('plan-monthly')->create();
        $this->oneTimePrice('setup', 5000);
        try {
            $subscription->swapAtPeriodEnd('setup');
            $this->fail('a one-time price cannot be a subscription price');
        } catch (InvalidArgumentException) {
        }

        Price::create($this->product('Euro'), [
            'lookup_key' => 'plan-eur', 'amount' => 1100, 'currency' => 'EUR', 'billing_type' => 'recurring', 'billing_interval' => 'month',
        ]);
        $this->expectException(InvalidArgumentException::class);
        $subscription->swapAtPeriodEnd('plan-eur');
    }

    public function test_runner_twice_at_the_same_time_applies_the_change_once(): void
    {
        $this->at('2026-01-01 00:00:00');
        $subscription = $this->customer()->newSubscription('plan-monthly')->create();
        $subscription->swapAtPeriodEnd('lite-monthly');

        $this->runAt('2026-02-01 00:00:00');
        $this->runAt('2026-02-01 00:00:00');

        $this->assertSame(2, $this->rows('cleat_invoices'));
        $this->assertSame(2, $this->gateway->chargeCount());
        $this->assertSame('lite-monthly', $subscription->refresh()->price()->lookup_key);
    }
}
