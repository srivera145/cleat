<?php

declare(strict_types=1);

namespace Cleat\Billing;

use Cleat\Cleat;
use Cleat\Enums\InvoiceStatus;
use Cleat\Enums\SubscriptionStatus;
use Cleat\Events\SubscriptionCanceled;
use Cleat\Exceptions\ChargeInProgressException;
use Cleat\Exceptions\PaymentFailedException;
use Cleat\Invoice;
use Cleat\Security\RateLimiter;
use Cleat\Subscription;
use Cleat\Support\Db;
use Cleat\Support\FrozenClock;
use Cleat\Support\Log;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The billing scheduler. Run it from cron every 15 minutes (bin/cleat-run.php).
 *
 * Every step is claim-then-act: a short transaction locks one row with
 * SELECT ... FOR UPDATE, re-checks it still qualifies, and changes it so it no
 * longer does (rolls the period, schedules a lease) before committing. Only
 * then does the gateway get called. Running twice at the same $now, or two
 * runners at once, finds nothing left to claim the second time.
 */
final class Runner
{
    /** How long a claimed invoice waits before a crashed attempt is picked up again. */
    private const LEASE = '+1 hour';

    public function run(?DateTimeImmutable $now = null): RunReport
    {
        $now = ($now ?? Cleat::now())->setTimezone(new DateTimeZone('UTC'));
        $report = new RunReport();

        if (!Db::lock('cleat:runner', 0)) {
            $report->skipped = true;
            Log::info('Cleat runner skipped: another run holds the lock');
            return $report;
        }

        $previousClock = Cleat::clock();
        Cleat::setClock(new FrozenClock($now));
        try {
            $this->convertTrials($now, $report);
            $this->renewSubscriptions($now, $report);
            $this->cancelAtPeriodEnd($now, $report);
            $this->retryInvoices($now, $report);
            $this->expireLinks($now, $report);
            $report->rateLimitRowsPruned = RateLimiter::prune($now);
        } finally {
            Cleat::setClock($previousClock);
            Db::unlock('cleat:runner');
        }
        return $report;
    }

    /** 1. Trials that have ended: invoice the first paid period and charge it. */
    private function convertTrials(DateTimeImmutable $now, RunReport $report): void
    {
        $ids = Db::all(
            "SELECT id FROM cleat_subscriptions WHERE status = 'trialing' AND cancel_at_period_end = 0 AND current_period_end <= ? ORDER BY current_period_end, id",
            [$now],
        );
        foreach ($ids as $row) {
            $this->guard($report, 'trial ' . $row['id'], function () use ($row, $now, $report): void {
                $invoice = $this->claimNextPeriod((int) $row['id'], SubscriptionStatus::Trialing, $now);
                if ($invoice !== null) {
                    $report->trialsConverted++;
                    $this->charge($invoice, $report);
                }
            });
        }
    }

    /** 2. Active subscriptions whose period has ended: roll the period and charge the renewal. */
    private function renewSubscriptions(DateTimeImmutable $now, RunReport $report): void
    {
        $ids = Db::all(
            "SELECT id FROM cleat_subscriptions WHERE status = 'active' AND cancel_at_period_end = 0 AND current_period_end <= ? ORDER BY current_period_end, id",
            [$now],
        );
        foreach ($ids as $row) {
            $this->guard($report, 'renewal ' . $row['id'], function () use ($row, $now, $report): void {
                $invoice = $this->claimNextPeriod((int) $row['id'], SubscriptionStatus::Active, $now);
                if ($invoice !== null) {
                    $report->renewals++;
                    $this->charge($invoice, $report);
                }
            });
        }
    }

    /** 3. Subscriptions set to cancel at period end whose end has arrived. */
    private function cancelAtPeriodEnd(DateTimeImmutable $now, RunReport $report): void
    {
        $ids = Db::all(
            "SELECT id FROM cleat_subscriptions WHERE status <> 'canceled' AND cancel_at_period_end = 1 AND ends_at <= ? ORDER BY ends_at, id",
            [$now],
        );
        foreach ($ids as $row) {
            $this->guard($report, 'cancel ' . $row['id'], function () use ($row, $now, $report): void {
                $subscription = Db::transaction(function () use ($row, $now): ?Subscription {
                    $locked = Db::one('SELECT * FROM cleat_subscriptions WHERE id = ? FOR UPDATE', [(int) $row['id']]);
                    if ($locked === null || $locked['status'] === 'canceled' || !(bool) $locked['cancel_at_period_end']
                        || Db::date($locked['ends_at']) > $now) {
                        return null;
                    }
                    Db::update('cleat_subscriptions', [
                        'status' => SubscriptionStatus::Canceled,
                        'canceled_at' => $now,
                        'updated_at' => $now,
                    ], ['id' => (int) $row['id']]);
                    Db::run(
                        "UPDATE cleat_invoices SET next_attempt_at = NULL, updated_at = ? WHERE subscription_id = ? AND status = 'open'",
                        [$now, (int) $row['id']],
                    );
                    return Subscription::findOrFail((int) $row['id']);
                });
                if ($subscription !== null) {
                    $report->cancellations++;
                    Cleat::events()->dispatch(new SubscriptionCanceled($subscription));
                }
            });
        }
    }

    /** 4. Open invoices with a retry due. */
    private function retryInvoices(DateTimeImmutable $now, RunReport $report): void
    {
        $ids = Db::all(
            "SELECT id FROM cleat_invoices WHERE status = 'open' AND next_attempt_at IS NOT NULL AND next_attempt_at <= ? ORDER BY next_attempt_at, id",
            [$now],
        );
        foreach ($ids as $row) {
            $this->guard($report, 'retry ' . $row['id'], function () use ($row, $now, $report): void {
                $invoice = Db::transaction(function () use ($row, $now): ?Invoice {
                    $locked = Db::one('SELECT * FROM cleat_invoices WHERE id = ? FOR UPDATE', [(int) $row['id']]);
                    if ($locked === null || $locked['status'] !== InvoiceStatus::Open->value
                        || $locked['next_attempt_at'] === null || Db::date($locked['next_attempt_at']) > $now) {
                        return null;
                    }
                    // Lease: if this process dies mid-attempt the retry comes back in an hour.
                    Db::update('cleat_invoices', ['next_attempt_at' => $now->modify(self::LEASE), 'updated_at' => $now], ['id' => (int) $row['id']]);
                    return Invoice::findOrFail((int) $row['id']);
                });
                if ($invoice === null) {
                    return;
                }
                $report->retries++;
                Dunning::retry($invoice) ? $report->paymentsSucceeded++ : $report->paymentsFailed++;
            });
        }
    }

    /** 5. Deactivate payment links past expires_at. */
    private function expireLinks(DateTimeImmutable $now, RunReport $report): void
    {
        $report->linksDeactivated = Db::run(
            'UPDATE cleat_payment_links SET is_active = 0, updated_at = ? WHERE is_active = 1 AND expires_at IS NOT NULL AND expires_at <= ?',
            [$now, $now],
        )->rowCount();
    }

    /**
     * Claim one subscription's next period: lock it, re-check, roll the
     * period and create the open invoice, all in one transaction. The invoice
     * carries a one-hour lease in next_attempt_at so a crash before the
     * charge is retried by step 4 rather than lost.
     */
    private function claimNextPeriod(int $subscriptionId, SubscriptionStatus $expected, DateTimeImmutable $now): ?Invoice
    {
        return Db::transaction(function () use ($subscriptionId, $expected, $now): ?Invoice {
            $row = Db::one('SELECT * FROM cleat_subscriptions WHERE id = ? FOR UPDATE', [$subscriptionId]);
            if ($row === null) {
                return null;
            }
            $subscription = Subscription::fromRow($row);
            if ($subscription->status !== $expected || $subscription->cancel_at_period_end || $subscription->current_period_end > $now) {
                return null;
            }

            $price = $subscription->price();
            $start = $subscription->current_period_end;
            $anchor = $subscription->anchor_day;
            $end = Period::advance($start, $price->billing_interval, $price->interval_count, $anchor);
            Db::update('cleat_subscriptions', [
                'current_period_start' => $start,
                'current_period_end' => $end,
                'updated_at' => $now,
            ], ['id' => $subscriptionId]);
            $subscription->refresh();

            $invoice = $subscription->invoicePeriod($start, $end);
            Db::update('cleat_invoices', ['next_attempt_at' => $now->modify(self::LEASE)], ['id' => $invoice->id]);
            return $invoice->refresh();
        });
    }

    private function charge(Invoice $invoice, RunReport $report): void
    {
        try {
            $invoice->pay();
            $report->paymentsSucceeded++;
        } catch (PaymentFailedException) {
            $report->paymentsFailed++;
        } catch (ChargeInProgressException $e) {
            Log::warning('Charge skipped: another charge is pending or held', ['invoice_id' => $invoice->id, 'message' => $e->getMessage()]);
        }
    }

    /** One bad row must never stop billing for the rest. */
    private function guard(RunReport $report, string $label, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $report->errors[] = sprintf('%s: %s', $label, $e->getMessage());
            Log::error('Cleat runner step failed', ['step' => $label, 'exception' => $e::class, 'message' => $e->getMessage()]);
        }
    }
}
