<?php

declare(strict_types=1);

namespace Cleat\Tests\Feature;

use Cleat\Enums\InvoiceStatus;
use Cleat\Enums\SubscriptionStatus;
use Cleat\InvoiceItem;
use Cleat\Tests\Support\TestCase;

/** Self-check 5. */
final class ProrationSwapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $product = $this->product('Plan');
        $this->monthlyPrice('basic', 1000, product: $product);
        $this->monthlyPrice('premium', 3000, product: $product);
        $this->monthlyPrice('pro', 5000, product: $product);
        $this->monthlyPrice('starter', 2000, product: $product);
    }

    public function test_mid_period_upgrade_invoices_the_net_immediately(): void
    {
        // Jan 1 -> Feb 1 is 31 days. Jan 16 12:00 leaves exactly half.
        $this->at('2026-01-01 00:00:00');
        $subscription = $this->customer()->newSubscription('basic')->create();
        $this->at('2026-01-16 12:00:00');

        $subscription->swap('premium');

        $this->assertSame('premium', $subscription->price()->lookup_key);
        $this->assertSame('2026-01-01 00:00:00', $subscription->current_period_start->format('Y-m-d H:i:s'), 'period dates never change on swap');
        $this->assertSame('2026-02-01 00:00:00', $subscription->current_period_end->format('Y-m-d H:i:s'));

        $invoice = $subscription->latestInvoice();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $lines = $invoice->items();
        $this->assertCount(2, $lines);
        [$credit, $debit] = $lines;
        $this->assertTrue($credit->is_proration);
        $this->assertTrue($debit->is_proration);
        $this->assertSame(-500, $credit->amount, 'unused half of $10.00');
        $this->assertSame(1500, $debit->amount, 'remaining half of $30.00');
        $this->assertStringStartsWith('Unused time on Plan (Monthly)', $credit->description);
        $this->assertSame(1000, $invoice->total);
        $this->assertSame(1000, $invoice->amount_paid);
        $this->assertSame(2, $this->gateway->chargeCount(), 'initial charge + proration charge');
        $this->assertSame(1000, $this->gateway->callsTo('chargeProfile')[1]['args']['amount']);
    }

    public function test_downgrade_carries_a_credit_to_the_next_renewal(): void
    {
        $this->at('2026-01-01 00:00:00');
        $subscription = $this->customer()->newSubscription('pro')->create();
        $this->at('2026-01-16 12:00:00');

        $subscription->swap('starter');

        // Net is -$15.00: nothing charged now, two pending lines wait for the renewal.
        $this->assertSame(1, $this->gateway->chargeCount());
        $this->assertSame(1, $this->rows('cleat_invoices'));
        $pending = InvoiceItem::pendingFor($subscription->id);
        $this->assertCount(2, $pending);
        $this->assertSame([-2500, 1000], array_map(static fn (InvoiceItem $i) => $i->amount, $pending));

        $this->runAt('2026-02-01 00:00:00');

        $renewal = $subscription->refresh()->latestInvoice();
        $this->assertSame(InvoiceStatus::Paid, $renewal->status);
        $amounts = array_map(static fn (InvoiceItem $i) => $i->amount, $renewal->items());
        $this->assertSame([-2500, 1000, 2000], $amounts, 'pending proration lines first, then the new period');
        $this->assertSame(500, $renewal->total, '$20.00 renewal less the $15.00 credit');
        $this->assertSame(500, $this->gateway->callsTo('chargeProfile')[1]['args']['amount']);
        $this->assertSame([], InvoiceItem::pendingFor($subscription->id), 'pending lines are consumed once');

        // The next renewal is back to the full price.
        $this->runAt('2026-03-01 00:00:00');
        $this->assertSame(2000, $subscription->refresh()->latestInvoice()->total);
    }

    public function test_credit_larger_than_the_renewal_is_carried_forward_again(): void
    {
        $this->at('2026-01-01 00:00:00');
        $subscription = $this->customer()->newSubscription('pro')->create();
        $subscription->swap('basic'); // at the very start: credit -$50, debit +$10

        $this->runAt('2026-02-01 00:00:00');
        $renewal = $subscription->refresh()->latestInvoice();
        $this->assertSame(0, $renewal->total);
        $this->assertSame(InvoiceStatus::Paid, $renewal->status, 'a zero invoice is paid without a charge');
        $this->assertSame(1, $this->gateway->chargeCount());
        $carried = InvoiceItem::pendingFor($subscription->id);
        $this->assertCount(1, $carried);
        $this->assertSame(-3000, $carried[0]->amount, '$10 + (-$50) + $10 = -$30 carried forward');

        $this->runAt('2026-03-01 00:00:00');
        $this->assertSame(0, $subscription->refresh()->latestInvoice()->total);
        $this->assertSame(-2000, InvoiceItem::pendingFor($subscription->id)[0]->amount);
    }

    public function test_swap_without_proration_only_changes_the_price(): void
    {
        $this->at('2026-01-01 00:00:00');
        $subscription = $this->customer()->newSubscription('basic')->create();
        $this->at('2026-01-20 00:00:00');
        $subscription->swap('premium', prorate: false);
        $this->assertSame(1, $this->rows('cleat_invoices'));
        $this->assertSame([], InvoiceItem::pendingFor($subscription->id));
        $this->runAt('2026-02-01 00:00:00');
        $this->assertSame(3000, $subscription->refresh()->latestInvoice()->total);
    }

    public function test_swap_during_trial_does_not_prorate(): void
    {
        $subscription = $this->customer()->newSubscription('basic')->withTrialDays(14)->create();
        $subscription->swap('premium');
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertSame(0, $this->rows('cleat_invoices'));
        $this->assertSame(0, $this->gateway->chargeCount());
    }

    public function test_failed_upgrade_charge_keeps_the_swap_and_enters_dunning(): void
    {
        $this->at('2026-01-01 00:00:00');
        $customer = $this->customer();
        $subscription = $customer->newSubscription('basic')->create();
        $this->gateway->setPaymentProfileToken($customer->gateway_customer_id, $customer->gateway_payment_id, 'tok_decline');
        $this->at('2026-01-16 12:00:00');

        try {
            $subscription->swap('premium');
            $this->fail('expected PaymentFailedException');
        } catch (\Cleat\Exceptions\PaymentFailedException $e) {
            $this->assertNotNull($e->payUrl);
        }
        $subscription->refresh();
        $this->assertSame('premium', $subscription->price()->lookup_key);
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertSame('2026-01-17 12:00:00', $subscription->latestInvoice()->next_attempt_at->format('Y-m-d H:i:s'));
    }
}
