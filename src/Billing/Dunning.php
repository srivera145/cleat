<?php

declare(strict_types=1);

namespace Cleat\Billing;

use Cleat\Charge;
use Cleat\Cleat;
use Cleat\Enums\InvoiceStatus;
use Cleat\Enums\SubscriptionStatus;
use Cleat\Events\PaymentFailed;
use Cleat\Events\SubscriptionCanceled;
use Cleat\Events\SubscriptionPastDue;
use Cleat\Exceptions\ChargeInProgressException;
use Cleat\Exceptions\PaymentFailedException;
use Cleat\Invoice;
use Cleat\Mail\Messages;
use Cleat\Support\Db;
use Cleat\Support\Log;
use DateTimeImmutable;

/**
 * What happens after a failed charge.
 *
 * Always: the charge is already recorded, and PaymentFailed fires.
 *
 * With automation.dunning_enabled, for an automatic attempt on a
 * subscription invoice (renewal, trial conversion, proration):
 *   - the subscription moves to failed_action (past_due),
 *   - the next retry is scheduled retry_attempts[attempt_count - 1] days
 *     after the first failure (so +1, +3, +7 by default),
 *   - the payment_failed email with the pay link goes out (dispatch_emails),
 *   - after the last retry fails: final_action (unpaid or canceled), the
 *     invoice becomes uncollectible, and SubscriptionPastDue or
 *     SubscriptionCanceled fires.
 *
 * With dunning disabled: nothing changes, nothing is scheduled, nothing is
 * sent. The developer owns what happens next; the pay link still works.
 *
 * Customer-initiated attempts on the pay page, first payments of new
 * subscriptions, and one-off invoices never enter the retry schedule.
 */
final class Dunning
{
    public static function handleFailure(Invoice $invoice, Charge $charge, string $initiator): void
    {
        $attemptNumber = (int) Db::value('SELECT COUNT(*) FROM cleat_charges WHERE invoice_id = ?', [$invoice->id]);
        $automation = Cleat::config('automation');
        $subscription = $invoice->subscription();

        $eligible = $initiator === 'automatic'
            && $automation['dunning_enabled']
            && $subscription !== null
            && in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue], true)
            && $invoice->status === InvoiceStatus::Open;

        if (!$eligible) {
            // An automatic attempt that was recorded clears the Runner's crash-recovery
            // lease: with nothing scheduled, nothing may come back to retry it.
            if ($initiator === 'automatic' && $invoice->next_attempt_at !== null) {
                Db::update('cleat_invoices', ['next_attempt_at' => null, 'updated_at' => Cleat::now()], ['id' => $invoice->id]);
                $invoice->refresh();
            }
            Cleat::events()->dispatch(new PaymentFailed($invoice, $charge, $attemptNumber, $invoice->next_attempt_at !== null));
            return;
        }

        $retries = $automation['retry_attempts'];
        $index = $invoice->attempt_count - 1;
        $now = Cleat::now();

        if (array_key_exists($index, $retries)) {
            $next = self::firstFailureAt($invoice)->modify(sprintf('+%d days', $retries[$index]));
            if ($next <= $now) {
                $next = $now->modify('+1 hour'); // catching up after downtime: never retry in the same run
            }
            $failedAction = SubscriptionStatus::from((string) $automation['failed_action']);
            $movedToPastDue = Db::transaction(function () use ($invoice, $subscription, $next, $failedAction, $now): bool {
                $subscription->lock();
                Db::update('cleat_invoices', ['next_attempt_at' => $next, 'updated_at' => $now], ['id' => $invoice->id]);
                if ($subscription->status !== $failedAction && $subscription->status !== SubscriptionStatus::Canceled) {
                    Db::update('cleat_subscriptions', ['status' => $failedAction, 'updated_at' => $now], ['id' => $subscription->id]);
                    return $failedAction === SubscriptionStatus::PastDue;
                }
                return false;
            });
            $invoice->refresh();
            $subscription->refresh();

            Cleat::events()->dispatch(new PaymentFailed($invoice, $charge, $attemptNumber, true));
            if ($movedToPastDue) {
                Cleat::events()->dispatch(new SubscriptionPastDue($subscription, $invoice));
            }
            if ($automation['dispatch_emails']) {
                $invoice->extendLink();
                Messages::paymentFailed($invoice, $invoice->customer(), $next);
            }
            return;
        }

        // Last retry failed.
        $finalAction = SubscriptionStatus::from((string) $automation['final_action']);
        Db::transaction(function () use ($invoice, $subscription, $finalAction, $now): void {
            $subscription->lock();
            Db::update('cleat_invoices', ['status' => InvoiceStatus::Uncollectible, 'next_attempt_at' => null, 'updated_at' => $now], ['id' => $invoice->id]);
            $fields = ['status' => $finalAction, 'updated_at' => $now];
            if ($finalAction === SubscriptionStatus::Canceled) {
                $fields += ['canceled_at' => $now, 'ends_at' => $now];
            }
            Db::update('cleat_subscriptions', $fields, ['id' => $subscription->id]);
        });
        $invoice->refresh();
        $subscription->refresh();

        Cleat::events()->dispatch(new PaymentFailed($invoice, $charge, $attemptNumber, false));
        Cleat::events()->dispatch($finalAction === SubscriptionStatus::Canceled
            ? new SubscriptionCanceled($subscription)
            : new SubscriptionPastDue($subscription, $invoice));
    }

    /**
     * A scheduled retry, called by the Runner after it has claimed the
     * invoice. Returns true when the invoice ended up paid.
     */
    public static function retry(Invoice $invoice): bool
    {
        $subscription = $invoice->subscription();
        if ($invoice->status !== InvoiceStatus::Open
            || ($subscription !== null && in_array($subscription->status, [SubscriptionStatus::Canceled, SubscriptionStatus::Unpaid], true))) {
            Db::update('cleat_invoices', ['next_attempt_at' => null, 'updated_at' => Cleat::now()], ['id' => $invoice->id]);
            return false;
        }
        try {
            $invoice->pay();
        } catch (PaymentFailedException) {
            return false;
        } catch (ChargeInProgressException $e) {
            Log::warning('Retry skipped: a charge is still pending or held', ['invoice_id' => $invoice->id, 'message' => $e->getMessage()]);
            return false;
        }
        return $invoice->refresh()->status === InvoiceStatus::Paid;
    }

    private static function firstFailureAt(Invoice $invoice): DateTimeImmutable
    {
        $first = Db::value(
            "SELECT MIN(created_at) FROM cleat_charges WHERE invoice_id = ? AND status = 'failed' AND idempotency_key LIKE ?",
            [$invoice->id, sprintf('inv\\_%d\\_attempt\\_%%', $invoice->id)],
        );
        return Db::date($first) ?? Cleat::now();
    }
}
