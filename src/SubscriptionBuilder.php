<?php

declare(strict_types=1);

namespace Cleat;

use Cleat\Billing\Period;
use Cleat\Enums\SubscriptionStatus;
use Cleat\Events\SubscriptionCreated;
use Cleat\Exceptions\PaymentFailedException;
use Cleat\Support\Db;
use InvalidArgumentException;

/**
 *   $customer->newSubscription('premium-monthly')
 *       ->withTrialDays(7)
 *       ->quantity(1)
 *       ->create($opaqueData);   // opaque data optional if a card is saved
 *
 * With a trial: status trialing, nothing charged, the period is the trial.
 * Without: the first invoice is created and charged now. A decline leaves
 * the subscription incomplete with an open invoice and throws
 * PaymentFailedException carrying the gateway result and the pay URL.
 */
final class SubscriptionBuilder
{
    private ?int $trialDays = null;
    private int $quantity = 1;
    private ?int $connectedAccountId = null;
    private ?string $applicationFeePercent = null;
    private ?PaymentLink $paymentLink = null;

    public function __construct(private readonly Customer $customer, private readonly string $lookupKey)
    {
    }

    /** Overrides the price's own trial_days. 0 means no trial. */
    public function withTrialDays(int $days): self
    {
        if ($days < 0 || $days > 730) {
            throw new InvalidArgumentException('Trial days must be 0-730.');
        }
        $this->trialDays = $days;
        return $this;
    }

    public function skipTrial(): self
    {
        $this->trialDays = 0;
        return $this;
    }

    public function quantity(int $quantity): self
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }
        $this->quantity = $quantity;
        return $this;
    }

    /** Bill on behalf of a connected account. Requires a Connect driver. */
    public function onBehalfOf(int $connectedAccountId, ?string $applicationFeePercent = null): self
    {
        Cleat::assertConnectAvailable($connectedAccountId);
        $this->connectedAccountId = $connectedAccountId;
        $this->applicationFeePercent = $applicationFeePercent;
        return $this;
    }

    /** @internal Record the payment link on the first invoice. */
    public function fromPaymentLink(PaymentLink $link): self
    {
        $this->paymentLink = $link;
        return $this;
    }

    /**
     * @param array{dataDescriptor: string, dataValue: string}|null $opaqueData
     * @throws PaymentFailedException
     */
    public function create(?array $opaqueData = null): Subscription
    {
        $price = Price::resolve($this->lookupKey);
        if (!$price->isRecurring() || !$price->is_active) {
            throw new InvalidArgumentException(sprintf('Price "%s" must be an active recurring price.', $this->lookupKey));
        }
        Cleat::assertConnectAvailable($this->connectedAccountId);
        if ($this->applicationFeePercent !== null) {
            Money::percentage(100, $this->applicationFeePercent); // validates the format
        }

        if ($opaqueData !== null) {
            $this->customer->updatePaymentMethod($opaqueData);
        }

        $now = Cleat::now();
        $trialDays = $this->trialDays ?? $price->trial_days;

        if ($trialDays > 0) {
            $trialEnd = $now->modify(sprintf('+%d days', $trialDays));
            $subscription = Subscription::findOrFail(Db::insert('cleat_subscriptions', $this->row(
                SubscriptionStatus::Trialing, (int) $trialEnd->format('j'), $now, $trialEnd, $trialEnd,
            )));
            Cleat::events()->dispatch(new SubscriptionCreated($subscription));
            return $subscription;
        }

        $anchor = (int) $now->format('j');
        $periodEnd = Period::advance($now, $price->billing_interval, $price->interval_count, $anchor);
        [$subscription, $invoice] = Db::transaction(function () use ($anchor, $now, $periodEnd): array {
            $subscription = Subscription::findOrFail(Db::insert('cleat_subscriptions', $this->row(
                SubscriptionStatus::Incomplete, $anchor, $now, $periodEnd, null,
            )));
            $invoice = $subscription->invoicePeriod($now, $periodEnd);
            if ($this->paymentLink !== null) {
                Db::update('cleat_invoices', ['payment_link_id' => $this->paymentLink->id], ['id' => $invoice->id]);
            }
            return [$subscription, $invoice->refresh()];
        });

        try {
            $invoice->pay();
        } catch (PaymentFailedException $e) {
            Cleat::events()->dispatch(new SubscriptionCreated($subscription->refresh()));
            throw $e;
        }
        Cleat::events()->dispatch(new SubscriptionCreated($subscription->refresh()));
        return $subscription;
    }

    /** @return array<string, mixed> */
    private function row(SubscriptionStatus $status, int $anchorDay, \DateTimeImmutable $start, \DateTimeImmutable $end, ?\DateTimeImmutable $trialEnd): array
    {
        $now = Cleat::now();
        return [
            'customer_id' => $this->customer->id,
            'price_id' => Price::resolve($this->lookupKey)->id,
            'status' => $status,
            'quantity' => $this->quantity,
            'anchor_day' => $anchorDay,
            'trial_ends_at' => $trialEnd,
            'current_period_start' => $start,
            'current_period_end' => $end,
            'cancel_at_period_end' => false,
            'connected_account_id' => $this->connectedAccountId,
            'application_fee_percent' => $this->applicationFeePercent,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
