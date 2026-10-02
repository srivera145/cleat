<?php

declare(strict_types=1);

namespace Cleat;

use Cleat\Billing\Period;
use Cleat\Billing\Proration;
use Cleat\Enums\SubscriptionStatus;
use Cleat\Events\SubscriptionCanceled;
use Cleat\Exceptions\CleatException;
use Cleat\Exceptions\NotFoundException;
use Cleat\Exceptions\PaymentFailedException;
use Cleat\Support\Db;
use DateTimeImmutable;
use InvalidArgumentException;

final class Subscription
{
    public function __construct(
        public readonly int $id,
        public readonly int $customer_id,
        public int $price_id,
        public SubscriptionStatus $status,
        public int $quantity,
        public int $anchor_day,
        public ?DateTimeImmutable $trial_ends_at,
        public DateTimeImmutable $current_period_start,
        public DateTimeImmutable $current_period_end,
        public bool $cancel_at_period_end,
        public ?DateTimeImmutable $canceled_at,
        public ?DateTimeImmutable $ends_at,
        public readonly ?int $connected_account_id,
        public readonly ?string $application_fee_percent,
        public readonly DateTimeImmutable $created_at,
        public DateTimeImmutable $updated_at,
    ) {
    }

    public static function find(int $id): ?self
    {
        $row = Db::one('SELECT * FROM cleat_subscriptions WHERE id = ?', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findOrFail(int $id): self
    {
        return self::find($id) ?? throw new NotFoundException("Subscription $id not found.");
    }

    public function price(): Price
    {
        return Price::findOrFail($this->price_id);
    }

    public function customer(): Customer
    {
        return Customer::findOrFail($this->customer_id);
    }

    /** @return list<Invoice> newest first */
    public function invoices(): array
    {
        return array_map(
            [Invoice::class, 'fromRow'],
            Db::all('SELECT * FROM cleat_invoices WHERE subscription_id = ? ORDER BY id DESC', [$this->id]),
        );
    }

    public function latestInvoice(): ?Invoice
    {
        return $this->invoices()[0] ?? null;
    }

    // --- State -------------------------------------------------------------

    public function onTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing
            && $this->trial_ends_at !== null
            && $this->trial_ends_at > Cleat::now();
    }

    /**
     * Should the customer have access? True while trialing or active, and
     * while past_due within grace_days of the period start (the day the
     * failed invoice was raised).
     */
    public function active(): bool
    {
        return match ($this->status) {
            SubscriptionStatus::Trialing, SubscriptionStatus::Active => true,
            SubscriptionStatus::PastDue => Cleat::now() < $this->current_period_start->modify(sprintf('+%d days', (int) Cleat::config('grace_days'))),
            default => false,
        };
    }

    /** Canceled at period end but not yet ended: still usable until ends_at. */
    public function onGracePeriod(): bool
    {
        return $this->cancel_at_period_end
            && $this->status !== SubscriptionStatus::Canceled
            && $this->ends_at !== null
            && $this->ends_at > Cleat::now();
    }

    public function pastDue(): bool
    {
        return $this->status === SubscriptionStatus::PastDue;
    }

    public function canceled(): bool
    {
        return $this->status === SubscriptionStatus::Canceled;
    }

    public function incomplete(): bool
    {
        return $this->status === SubscriptionStatus::Incomplete;
    }

    public function unpaid(): bool
    {
        return $this->status === SubscriptionStatus::Unpaid;
    }

    // --- Changes -----------------------------------------------------------

    /** Cancel at the end of the current period (or trial). */
    public function cancel(): self
    {
        if ($this->status === SubscriptionStatus::Incomplete) {
            return $this->cancelNow();
        }
        Db::transaction(function (): void {
            $this->lock();
            if ($this->status === SubscriptionStatus::Canceled) {
                throw new CleatException('The subscription is already canceled.');
            }
            Db::update('cleat_subscriptions', [
                'cancel_at_period_end' => true,
                'ends_at' => $this->current_period_end,
                'updated_at' => Cleat::now(),
            ], ['id' => $this->id]);
        });
        return $this->refresh();
    }

    /**
     * Cancel immediately. No refund. Stops retries on its open invoices.
     * A subscription that never started (incomplete) also has its unpaid
     * first invoice voided, so its pay link cannot charge for nothing later.
     */
    public function cancelNow(): self
    {
        $canceled = Db::transaction(function (): bool {
            $this->lock();
            if ($this->status === SubscriptionStatus::Canceled) {
                return false;
            }
            $now = Cleat::now();
            if ($this->status === SubscriptionStatus::Incomplete) {
                $inFlight = "SELECT 1 FROM cleat_charges c WHERE c.invoice_id = cleat_invoices.id AND (c.status IN ('pending', 'held') OR "
                    . \Cleat\Gateway\GatewayResult::unknownOutcomeSql('c') . ')';
                Db::run(
                    "UPDATE cleat_invoices SET status = 'void', next_attempt_at = NULL, updated_at = ?
                     WHERE subscription_id = ? AND status = 'open' AND NOT EXISTS ($inFlight)",
                    [$now, $this->id],
                );
            }
            Db::update('cleat_subscriptions', [
                'status' => SubscriptionStatus::Canceled,
                'canceled_at' => $now,
                'ends_at' => $now,
                'updated_at' => $now,
            ], ['id' => $this->id]);
            Db::run(
                "UPDATE cleat_invoices SET next_attempt_at = NULL, updated_at = ? WHERE subscription_id = ? AND status = 'open'",
                [$now, $this->id],
            );
            return true;
        });
        $this->refresh();
        if ($canceled) {
            Cleat::events()->dispatch(new SubscriptionCanceled($this));
        }
        return $this;
    }

    /** Undo cancel() before the period ends. */
    public function resume(): self
    {
        Db::transaction(function (): void {
            $this->lock();
            if (!$this->cancel_at_period_end || $this->status === SubscriptionStatus::Canceled
                || $this->ends_at === null || $this->ends_at <= Cleat::now()) {
                throw new CleatException('Only a subscription scheduled to cancel, and still before its end date, can be resumed.');
            }
            Db::update('cleat_subscriptions', ['cancel_at_period_end' => false, 'ends_at' => null, 'updated_at' => Cleat::now()], ['id' => $this->id]);
        });
        return $this->refresh();
    }

    /**
     * Move to another price. With $prorate, an active subscription is
     * credited for the unused part of the old price and debited for the rest
     * of the period at the new price, both from the seconds left in the
     * current period. A positive net is invoiced and charged now; a negative
     * net waits as pending lines on the next renewal invoice. Period dates
     * never change. Trialing subscriptions just switch price.
     *
     * If the immediate charge fails the swap still stands, the invoice
     * enters dunning, and PaymentFailedException carries its pay URL.
     *
     * @throws PaymentFailedException
     */
    public function swap(string $lookupKey, bool $prorate = true): self
    {
        $newPrice = Price::resolve($lookupKey);
        if (!$newPrice->isRecurring() || !$newPrice->is_active) {
            throw new InvalidArgumentException(sprintf('Price "%s" must be an active recurring price.', $lookupKey));
        }

        $invoice = Db::transaction(function () use ($newPrice, $prorate): ?Invoice {
            $this->lock();
            if (!in_array($this->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)) {
                throw new CleatException(sprintf('Only active or trialing subscriptions can swap; this one is %s.', $this->status->value));
            }
            $oldPrice = $this->price();
            if ($oldPrice->id === $newPrice->id) {
                return null;
            }
            if ($oldPrice->currency !== $newPrice->currency) {
                throw new InvalidArgumentException('Cannot swap between prices in different currencies.');
            }

            $now = Cleat::now();
            $invoice = null;
            if ($prorate && $this->status === SubscriptionStatus::Active && $now < $this->current_period_end) {
                $newPeriodSeconds = $oldPrice->billing_interval === $newPrice->billing_interval && $oldPrice->interval_count === $newPrice->interval_count
                    ? null
                    : Period::seconds($this->current_period_start, $newPrice->billing_interval, $newPrice->interval_count, $this->anchor_day);
                $p = Proration::calculate(
                    $oldPrice->amount, $this->quantity, $newPrice->amount, $this->quantity,
                    $this->current_period_start, $this->current_period_end, $now, $newPeriodSeconds,
                );
                $qty = $this->quantity > 1 ? $this->quantity . ' × ' : '';
                $since = $now->format('M j, Y');
                $lines = [
                    ['description' => sprintf('Unused time on %s%s after %s', $qty, $oldPrice->label(), $since), 'amount' => $p['credit'], 'price_id' => $oldPrice->id],
                    ['description' => sprintf('Remaining time on %s%s after %s', $qty, $newPrice->label(), $since), 'amount' => $p['debit'], 'price_id' => $newPrice->id],
                ];

                if ($p['net'] > 0) {
                    $invoice = Invoice::createDraft($this->customer(), [
                        'subscription_id' => $this->id,
                        'currency' => $newPrice->currency,
                        'period_start' => $now,
                        'period_end' => $this->current_period_end,
                        'connected_account_id' => $this->connected_account_id,
                    ]);
                    foreach ($lines as $line) {
                        $invoice->addLine($line['description'], $line['amount'], 1, $line['price_id'], true, $now, $this->current_period_end);
                    }
                    $invoice->finalize();
                } elseif ($p['net'] < 0) {
                    foreach ($lines as $line) {
                        InvoiceItem::create([
                            'subscription_id' => $this->id,
                            'price_id' => $line['price_id'],
                            'description' => $line['description'],
                            'unit_amount' => $line['amount'],
                            'is_proration' => true,
                            'period_start' => $now,
                            'period_end' => $this->current_period_end,
                        ]);
                    }
                }
            }

            Db::update('cleat_subscriptions', ['price_id' => $newPrice->id, 'updated_at' => $now], ['id' => $this->id]);
            return $invoice;
        });

        $this->refresh();
        if ($invoice !== null) {
            $invoice->pay();
            $this->refresh();
        }
        return $this;
    }

    public function refresh(): self
    {
        $row = Db::one('SELECT * FROM cleat_subscriptions WHERE id = ?', [$this->id]);
        $this->sync($row ?? throw new NotFoundException("Subscription {$this->id} not found."));
        return $this;
    }

    /** @internal Lock the row and refresh from it. Inside a transaction. */
    public function lock(): void
    {
        $row = Db::one('SELECT * FROM cleat_subscriptions WHERE id = ? FOR UPDATE', [$this->id]);
        $this->sync($row ?? throw new NotFoundException("Subscription {$this->id} not found."));
    }

    /**
     * @internal Create the invoice for a new period: the price line, plus any
     * pending proration lines. A credit larger than the period is carried
     * forward as a new pending line. Runs inside the caller's transaction and
     * returns an open invoice (possibly with a zero total).
     */
    public function invoicePeriod(DateTimeImmutable $start, DateTimeImmutable $end): Invoice
    {
        $price = $this->price();
        $invoice = Invoice::createDraft($this->customer(), [
            'subscription_id' => $this->id,
            'currency' => $price->currency,
            'period_start' => $start,
            'period_end' => $end,
            'due_at' => Cleat::now(),
            'connected_account_id' => $this->connected_account_id,
        ]);
        $invoice->addLine(
            sprintf('%s%s (%s – %s)', $this->quantity > 1 ? $this->quantity . ' × ' : '', $price->label(), $start->format('M j, Y'), $end->format('M j, Y')),
            $price->amount,
            $this->quantity,
            $price->id,
            false,
            $start,
            $end,
        );
        Db::run(
            'UPDATE cleat_invoice_items SET invoice_id = ? WHERE subscription_id = ? AND invoice_id IS NULL',
            [$invoice->id, $this->id],
        );
        $invoice->recalculate();

        if ($invoice->total < 0) {
            $carry = -$invoice->total;
            $invoice->addLine('Credit carried forward to the next invoice', $carry, 1, null, true);
            InvoiceItem::create([
                'subscription_id' => $this->id,
                'description' => 'Credit carried forward from the previous invoice',
                'unit_amount' => -$carry,
                'is_proration' => true,
            ]);
            $invoice->recalculate();
        }
        $invoice->setApplicationFeeFromPercent($this->application_fee_percent);
        return $invoice->finalize();
    }

    /** @param array<string, mixed> $row */
    private function sync(array $row): void
    {
        $fresh = self::fromRow($row);
        $this->price_id = $fresh->price_id;
        $this->status = $fresh->status;
        $this->quantity = $fresh->quantity;
        $this->anchor_day = $fresh->anchor_day;
        $this->trial_ends_at = $fresh->trial_ends_at;
        $this->current_period_start = $fresh->current_period_start;
        $this->current_period_end = $fresh->current_period_end;
        $this->cancel_at_period_end = $fresh->cancel_at_period_end;
        $this->canceled_at = $fresh->canceled_at;
        $this->ends_at = $fresh->ends_at;
        $this->updated_at = $fresh->updated_at;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['customer_id'],
            (int) $row['price_id'],
            SubscriptionStatus::from((string) $row['status']),
            (int) $row['quantity'],
            (int) $row['anchor_day'],
            Db::date($row['trial_ends_at']),
            Db::date($row['current_period_start']),
            Db::date($row['current_period_end']),
            (bool) $row['cancel_at_period_end'],
            Db::date($row['canceled_at']),
            Db::date($row['ends_at']),
            $row['connected_account_id'] !== null ? (int) $row['connected_account_id'] : null,
            $row['application_fee_percent'] !== null ? (string) $row['application_fee_percent'] : null,
            Db::date($row['created_at']),
            Db::date($row['updated_at']),
        );
    }
}
