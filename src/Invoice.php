<?php

declare(strict_types=1);

namespace Cleat;

use Cleat\Billing\CheckoutCompletion;
use Cleat\Billing\Dunning;
use Cleat\Enums\ChargeSource;
use Cleat\Enums\ChargeStatus;
use Cleat\Enums\InvoiceStatus;
use Cleat\Enums\SubscriptionStatus;
use Cleat\Events\InvoiceCreated;
use Cleat\Events\InvoicePaid;
use Cleat\Events\InvoiceSent;
use Cleat\Events\PaymentSucceeded;
use Cleat\Exceptions\ChargeInProgressException;
use Cleat\Exceptions\CleatException;
use Cleat\Exceptions\NotFoundException;
use Cleat\Exceptions\PaymentFailedException;
use Cleat\Gateway\GatewayResult;
use Cleat\Mail\Messages;
use Cleat\Support\Db;
use Cleat\Support\Log;
use Cleat\Support\OpaqueData;
use Cleat\Support\View;
use DateTimeImmutable;
use Throwable;

/**
 * An invoice: draft -> open -> paid | void | uncollectible.
 *
 * Charging follows one pattern everywhere, so a crash can never take money
 * twice:
 *
 *   1. Claim (one transaction): lock the invoice row, refuse if another
 *      charge is pending or held, write a 'pending' charge row with a unique
 *      idempotency key, bump attempt_count for automatic attempts. Commit.
 *   2. Call the gateway, outside any transaction.
 *   3. Record (one transaction): write the outcome onto the charge row and,
 *      on success, mark the invoice paid and reactivate its subscription.
 *
 * A crash between 1 and 3 leaves a 'pending' row that blocks further
 * attempts until it is reconciled (by the authcapture webhook, or by hand).
 */
final class Invoice
{
    private const INITIATOR_AUTOMATIC = 'automatic';
    private const INITIATOR_CUSTOMER = 'customer';

    public function __construct(
        public readonly int $id,
        public ?string $number,
        public readonly string $public_token,
        public readonly int $customer_id,
        public readonly ?int $subscription_id,
        public readonly ?int $payment_link_id,
        public InvoiceStatus $status,
        public readonly string $currency,
        public int $subtotal,
        public int $tax,
        public int $total,
        public int $amount_paid,
        public ?string $memo,
        public int $attempt_count,
        public ?DateTimeImmutable $next_attempt_at,
        public ?DateTimeImmutable $period_start,
        public ?DateTimeImmutable $period_end,
        public DateTimeImmutable $due_at,
        public ?DateTimeImmutable $sent_at,
        public ?DateTimeImmutable $paid_at,
        public ?DateTimeImmutable $link_expires_at,
        public readonly ?int $connected_account_id,
        public ?int $application_fee_amount,
        public readonly DateTimeImmutable $created_at,
        public DateTimeImmutable $updated_at,
    ) {
    }

    // --- Lookup ------------------------------------------------------------

    public static function find(int $id): ?self
    {
        $row = Db::one('SELECT * FROM cleat_invoices WHERE id = ?', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findOrFail(int $id): self
    {
        return self::find($id) ?? throw new NotFoundException("Invoice $id not found.");
    }

    public static function findByToken(string $token): ?self
    {
        if (!Token::isValid($token)) {
            return null;
        }
        $row = Db::one('SELECT * FROM cleat_invoices WHERE public_token = ?', [$token]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findByNumber(string $number): ?self
    {
        $row = Db::one('SELECT * FROM cleat_invoices WHERE number = ?', [$number]);
        return $row === null ? null : self::fromRow($row);
    }

    // --- Relations and money -------------------------------------------------

    /** @return list<InvoiceItem> */
    public function items(): array
    {
        return InvoiceItem::forInvoice($this->id);
    }

    /** @return list<Charge> */
    public function charges(): array
    {
        return Charge::forInvoice($this->id);
    }

    public function customer(): Customer
    {
        return Customer::findOrFail($this->customer_id);
    }

    public function subscription(): ?Subscription
    {
        return $this->subscription_id !== null ? Subscription::find($this->subscription_id) : null;
    }

    public function paymentLink(): ?PaymentLink
    {
        return $this->payment_link_id !== null ? PaymentLink::find($this->payment_link_id) : null;
    }

    public function amountDue(): int
    {
        return max(0, $this->total - $this->amount_paid);
    }

    public function money(int $amount): Money
    {
        return Money::of($amount, $this->currency);
    }

    public function totalMoney(): Money
    {
        return $this->money($this->total);
    }

    public function amountDueMoney(): Money
    {
        return $this->money($this->amountDue());
    }

    public function isPastDue(): bool
    {
        return $this->status === InvoiceStatus::Open && $this->due_at < Cleat::now();
    }

    public function linkExpired(): bool
    {
        return $this->link_expires_at !== null && $this->link_expires_at <= Cleat::now();
    }

    /** The hosted pay page URL. Only open invoices have one. */
    public function paymentUrl(): ?string
    {
        return $this->status === InvoiceStatus::Open ? Cleat::url('pay_invoice', $this->public_token) : null;
    }

    // --- Lifecycle -------------------------------------------------------

    /**
     * Draft -> open. Assigns the next invoice number inside the transaction
     * (gapless: a rolled-back finalize gives its number back) and starts the
     * hosted link's lifetime.
     */
    public function finalize(): self
    {
        Db::transaction(function (): void {
            $this->lock();
            if ($this->status === InvoiceStatus::Open) {
                return;
            }
            if ($this->status !== InvoiceStatus::Draft) {
                throw new CleatException(sprintf('Only a draft invoice can be finalized; invoice %d is %s.', $this->id, $this->status->value));
            }
            if ((int) Db::value('SELECT COUNT(*) FROM cleat_invoice_items WHERE invoice_id = ?', [$this->id]) === 0) {
                throw new CleatException('Cannot finalize an invoice with no line items.');
            }
            $this->recalculate();
            if ($this->total < 0) {
                throw new CleatException('An invoice total cannot be negative.');
            }
            $now = Cleat::now();
            $days = Cleat::config('invoice_link_days');
            Db::update('cleat_invoices', [
                'status' => InvoiceStatus::Open,
                'number' => self::nextNumber(),
                'link_expires_at' => $days === null ? null : $now->modify("+$days days"),
                'updated_at' => $now,
            ], ['id' => $this->id]);
            $this->reload();
            Cleat::events()->dispatch(new InvoiceCreated($this));
        });
        return $this->reload();
    }

    /**
     * Email the invoice with its pay link. Finalizes a draft first. Sending
     * (or re-sending) extends the hosted link so the link in the newest email
     * always works.
     */
    public function send(): self
    {
        if ($this->status === InvoiceStatus::Draft) {
            $this->finalize();
        }
        if ($this->status !== InvoiceStatus::Open) {
            throw new CleatException(sprintf('Only open invoices can be sent; invoice %s is %s.', $this->number ?? $this->id, $this->status->value));
        }
        $this->extendLink();
        $now = Cleat::now();
        Db::update('cleat_invoices', ['sent_at' => $now, 'updated_at' => $now], ['id' => $this->id]);
        $this->reload();

        $customer = $this->customer();
        Messages::invoice($this, $customer);
        Cleat::events()->dispatch(new InvoiceSent($this, $customer->email));
        return $this;
    }

    /** Void a draft or open invoice. Stops any scheduled retry. */
    public function void(): self
    {
        Db::transaction(function (): void {
            $this->lock();
            if ($this->status === InvoiceStatus::Void) {
                return;
            }
            if (!in_array($this->status, [InvoiceStatus::Draft, InvoiceStatus::Open], true)) {
                throw new CleatException(sprintf('Only draft or open invoices can be voided; invoice %s is %s.', $this->number ?? $this->id, $this->status->value));
            }
            $this->assertNoChargeInFlight();
            Db::update('cleat_invoices', ['status' => InvoiceStatus::Void, 'next_attempt_at' => null, 'updated_at' => Cleat::now()], ['id' => $this->id]);
        });
        return $this->reload();
    }

    public function markUncollectible(): self
    {
        Db::transaction(function (): void {
            $this->lock();
            if ($this->status !== InvoiceStatus::Open) {
                throw new CleatException('Only open invoices can be marked uncollectible.');
            }
            $this->assertNoChargeInFlight();
            Db::update('cleat_invoices', ['status' => InvoiceStatus::Uncollectible, 'next_attempt_at' => null, 'updated_at' => Cleat::now()], ['id' => $this->id]);
        });
        return $this->reload();
    }

    /** The print-ready HTML invoice (templates/invoice.php). */
    public function render(): string
    {
        return View::render('invoice', [
            'invoice' => $this,
            'items' => $this->items(),
            'customer' => $this->customer(),
            'brand' => Cleat::config('brand'),
            'deckCss' => Cleat::config('deck_css'),
            'payUrl' => $this->paymentUrl(),
        ]);
    }

    // --- Payment -----------------------------------------------------------

    /**
     * Charge the customer's stored card. This is the automatic (or
     * merchant-initiated) attempt: it bumps attempt_count and uses the
     * idempotency key "inv_{id}_attempt_{n}". Returns the charge (succeeded
     * or held), or null when nothing was due.
     *
     * @throws PaymentFailedException on decline, error, timeout or no card on file
     * @throws ChargeInProgressException when another charge is pending or held
     */
    public function pay(?string $ip = null): ?Charge
    {
        $customer = $this->customer();
        return $this->collect(self::INITIATOR_AUTOMATIC, ChargeSource::StoredProfile, $ip, function (Charge $charge) use ($customer): GatewayResult {
            if (!$customer->hasPaymentMethod()) {
                return GatewayResult::error('no_payment_method', 'The customer has no saved payment method.');
            }
            return Cleat::gateway()->chargeProfile(
                (string) $customer->gateway_customer_id,
                (string) $customer->gateway_payment_id,
                $charge->amount,
                (string) $this->number,
                $charge->idempotency_key,
            );
        });
    }

    /**
     * Pay with a card the customer just entered (the hosted pay page and
     * checkout). With $saveCard the card is attached to the customer's CIM
     * profile and the profile is charged; without it the single-use token is
     * charged directly and nothing is stored.
     *
     * @param array{dataDescriptor: string, dataValue: string} $opaqueData
     * @throws PaymentFailedException
     */
    public function payWithToken(array $opaqueData, bool $saveCard, string $ip): ?Charge
    {
        $opaque = OpaqueData::from($opaqueData);
        unset($opaqueData);
        $customer = $this->customer();

        if ($saveCard) {
            try {
                $customer->updatePaymentMethod($opaque);
            } catch (PaymentFailedException $e) {
                throw new PaymentFailedException($e->result, $this, null, $this->paymentUrl());
            }
            return $this->payWithSavedCard($ip);
        }

        return $this->collect(self::INITIATOR_CUSTOMER, ChargeSource::OneTimeToken, $ip, function (Charge $charge) use ($opaque, $customer, $ip): GatewayResult {
            return Cleat::gateway()->chargeToken($opaque, $charge->amount, (string) $this->number, $charge->idempotency_key, $customer->email, $ip);
        });
    }

    /**
     * The customer chose their saved card on the pay page. Customer-initiated,
     * so it does not advance the automatic retry schedule.
     *
     * @throws PaymentFailedException
     */
    public function payWithSavedCard(string $ip): ?Charge
    {
        $customer = $this->customer();
        return $this->collect(self::INITIATOR_CUSTOMER, ChargeSource::StoredProfile, $ip, function (Charge $charge) use ($customer): GatewayResult {
            if (!$customer->hasPaymentMethod()) {
                return GatewayResult::error('no_payment_method', 'The customer has no saved payment method.');
            }
            return Cleat::gateway()->chargeProfile(
                (string) $customer->gateway_customer_id,
                (string) $customer->gateway_payment_id,
                $charge->amount,
                (string) $this->number,
                $charge->idempotency_key,
            );
        });
    }

    /**
     * @internal Settle a charge that a webhook resolved (fraud approved/declined,
     * or a timed-out charge the gateway did capture).
     */
    public function settleCharge(Charge $charge, ChargeStatus $status, ?string $transactionId, ?string $reasonCode = null, ?string $reasonText = null): void
    {
        $lateCapture = false;
        $paidNow = Db::transaction(function () use ($charge, $status, $transactionId, $reasonCode, $reasonText, &$lateCapture): bool {
            $this->lock();
            // Re-read under the lock: a webhook and a reconciliation can race to
            // settle the same charge, and only the first may act on it.
            $row = Db::one('SELECT * FROM cleat_charges WHERE id = ? FOR UPDATE', [$charge->id]);
            $current = Charge::fromRow($row ?? throw new NotFoundException("Charge {$charge->id} not found."));
            if (in_array($current->status, [ChargeStatus::Succeeded, ChargeStatus::Refunded, ChargeStatus::Voided], true)
                || ($current->status === $status && $current->gateway_transaction_id === $transactionId)) {
                return false;
            }
            $charge->settle($status, $transactionId, $reasonCode, $reasonText);
            if ($status !== ChargeStatus::Succeeded) {
                return false;
            }
            if ($reasonCode === null && GatewayResult::isUnknownOutcome($current->reason_code)) {
                // Answer never arrived, but the money moved: say so on the row.
                $captured = mb_substr($current->reason_code . '_captured', 0, 32);
                Db::update('cleat_charges', ['reason_code' => $captured, 'reason_text' => 'Outcome was unknown; the gateway confirmed the capture later.'], ['id' => $charge->id]);
                $charge->reason_code = $captured;
                $lateCapture = true;
            }
            if (in_array($this->status, [InvoiceStatus::Open, InvoiceStatus::Uncollectible], true)) {
                $this->applyPayment($charge);
                return true;
            }
            // Money arrived for an invoice that is already paid or void (e.g. a
            // timed-out charge that did capture after all). Never silently.
            Log::error('Captured payment landed on an invoice that is not open; refund it', [
                'invoice_id' => $this->id,
                'invoice_status' => $this->status->value,
                'charge_id' => $charge->id,
                'transaction_id' => $transactionId,
            ]);
            return false;
        });
        $this->reload();
        if ($paidNow) {
            $this->afterPaid($charge);
            if ($lateCapture && $this->payment_link_id !== null) {
                // The checkout request that made this charge already told the
                // buyer it could not confirm; finish that purchase now.
                CheckoutCompletion::completeLate($this, Charge::findOrFail($charge->id));
            }
        } elseif ($status === ChargeStatus::Failed) {
            $initiator = str_contains($charge->idempotency_key, '_attempt_') ? self::INITIATOR_AUTOMATIC : self::INITIATOR_CUSTOMER;
            Dunning::handleFailure($this, $charge, $initiator);
        }
    }

    /**
     * Settle every charge on this invoice whose outcome is unknown (timeout,
     * dropped connection) by asking the gateway what happened. Runs before
     * every new attempt, so a charge that did go through is recorded instead
     * of being taken a second time.
     *
     * Returns the charge that turned out to have succeeded (the invoice is
     * then paid), or null. Throws ChargeInProgressException when the gateway
     * cannot be asked, because guessing could charge twice.
     *
     * @throws ChargeInProgressException
     */
    public function reconcile(): ?Charge
    {
        if (Db::inTransaction()) {
            throw new CleatException('reconcile() calls the gateway and must not run inside an open database transaction.');
        }
        $rows = Db::all('SELECT * FROM cleat_charges WHERE invoice_id = ? AND ' . GatewayResult::unknownOutcomeSql() . ' ORDER BY id', [$this->id]);
        foreach ($rows as $row) {
            $charge = Charge::fromRow($row);
            $found = Cleat::gateway()->findTransaction((string) $this->number, $charge->amount, $charge->created_at->modify('-10 minutes'));
            if (!$found->success) {
                throw new ChargeInProgressException(sprintf(
                    'The outcome of an earlier payment attempt on invoice %s (charge %d) is not known yet, and the gateway could not be checked. Try again shortly.',
                    $this->number,
                    $charge->id,
                ));
            }
            $transactionId = $found->get('transaction_id');
            $status = (string) $found->get('status');
            if (is_string($transactionId) && $status !== 'not_captured' && Charge::findByTransactionId($transactionId) === null) {
                $this->settleCharge($charge, $status === 'held' ? ChargeStatus::Held : ChargeStatus::Succeeded, $transactionId);
                if ($status !== 'held') {
                    return Charge::findOrFail($charge->id);
                }
                continue;
            }
            Db::update('cleat_charges', [
                'reason_code' => mb_substr($charge->reason_code . '_verified', 0, 32),
                'reason_text' => 'Outcome was unknown; the gateway has no matching capture, so nothing was charged.',
                'updated_at' => Cleat::now(),
            ], ['id' => $charge->id]);
        }
        $this->reload();
        return null;
    }

    /** @internal Mark a paid invoice void after its payment was refunded (checkout sold out). */
    public function voidAfterRefund(): self
    {
        Db::transaction(function (): void {
            $this->lock();
            Db::update('cleat_invoices', ['status' => InvoiceStatus::Void, 'next_attempt_at' => null, 'updated_at' => Cleat::now()], ['id' => $this->id]);
        });
        return $this->reload();
    }

    /** @internal Send the receipt email for this invoice. */
    public function sendReceipt(?Charge $charge = null): void
    {
        Messages::receipt($this, $this->customer(), $charge);
    }

    public function refresh(): self
    {
        return $this->reload();
    }

    // --- Internals ---------------------------------------------------------

    /**
     * @param callable(Charge): GatewayResult $call
     * @throws PaymentFailedException
     */
    private function collect(string $initiator, ChargeSource $source, ?string $ip, callable $call): ?Charge
    {
        if (Db::inTransaction()) {
            throw new CleatException(
                'Cleat charges must not run inside an open database transaction: the pending charge row has to be '
                . 'committed before the gateway is called, or a crash could charge twice.'
            );
        }

        // Phase 0: an earlier attempt whose answer never arrived may have
        // captured. Ask the gateway before trying again.
        if ($this->status === InvoiceStatus::Open && ($earlier = $this->reconcile()) !== null) {
            return $earlier;
        }

        // Phase 1: claim.
        $charge = Db::transaction(function () use ($initiator, $source, $ip): ?Charge {
            $this->lock();
            if ($this->status !== InvoiceStatus::Open) {
                throw new CleatException(sprintf('Invoice %s is %s, not open, so it cannot be paid.', $this->number ?? $this->id, $this->status->value));
            }
            if ($this->amountDue() === 0) {
                $this->applyPayment(null);
                return null;
            }
            $this->assertNoChargeInFlight();

            if ($initiator === self::INITIATOR_AUTOMATIC) {
                $attempt = $this->attempt_count + 1;
                $key = sprintf('inv_%d_attempt_%d', $this->id, $attempt);
                Db::update('cleat_invoices', ['attempt_count' => $attempt, 'updated_at' => Cleat::now()], ['id' => $this->id]);
                $this->attempt_count = $attempt;
            } else {
                $n = 1 + (int) Db::value('SELECT COUNT(*) FROM cleat_charges WHERE invoice_id = ? AND idempotency_key LIKE ?', [$this->id, sprintf('inv\\_%d\\_customer\\_%%', $this->id)]);
                $key = sprintf('inv_%d_customer_%d', $this->id, $n);
            }

            return Charge::insertPending([
                'invoice_id' => $this->id,
                'customer_id' => $this->customer_id,
                'amount' => $this->amountDue(),
                'source' => $source,
                'idempotency_key' => $key,
                'ip_address' => $ip,
                'connected_account_id' => $this->connected_account_id,
                'application_fee_amount' => $this->application_fee_amount,
            ]);
        });

        if ($charge === null) {
            $this->reload();
            $this->afterPaid(null);
            return null;
        }

        // Phase 2: gateway, outside any transaction.
        try {
            $result = $call($charge);
        } catch (Throwable $e) {
            // We cannot know whether money moved. Record a failure that the
            // authcapture webhook can still reconcile, exactly like a timeout.
            Log::error('Gateway call threw', ['invoice_id' => $this->id, 'charge_id' => $charge->id, 'exception' => $e::class, 'message' => $e->getMessage()]);
            $result = GatewayResult::error('gateway_exception', 'The gateway call failed unexpectedly.');
        }
        if ($result->outcomeUnknown()) {
            Log::warning('Charge outcome unknown; recorded as failed, blocks further attempts until reconciled, not retried in this run', [
                'invoice_id' => $this->id, 'charge_id' => $charge->id, 'reason' => $result->reasonCode,
            ]);
        }

        // Phase 3: record.
        $status = match (true) {
            $result->isApproved() => ChargeStatus::Succeeded,
            $result->isHeld() => ChargeStatus::Held,
            default => ChargeStatus::Failed,
        };
        $outcome = Db::transaction(function () use ($charge, $result, $status): string {
            $this->lock();
            $current = Db::value('SELECT status FROM cleat_charges WHERE id = ? FOR UPDATE', [$charge->id]);
            if ($current !== ChargeStatus::Pending->value) {
                // A webhook settled this charge while the call was in flight. Its
                // answer stands; overwriting it could lose a captured payment.
                return 'settled_elsewhere';
            }
            $charge->applyResult($result, $status);
            if ($status === ChargeStatus::Succeeded) {
                if ($this->status === InvoiceStatus::Open) {
                    $this->applyPayment($charge);
                    return 'paid';
                }
                Log::error('Charge succeeded on an invoice that is no longer open; refund it manually', ['invoice_id' => $this->id, 'charge_id' => $charge->id]);
            } elseif ($status === ChargeStatus::Held) {
                Db::update('cleat_invoices', ['next_attempt_at' => null, 'updated_at' => Cleat::now()], ['id' => $this->id]);
            }
            return 'recorded';
        });
        $this->reload();

        if ($outcome === 'settled_elsewhere') {
            $charge = Charge::findOrFail($charge->id);
            if ($charge->status !== ChargeStatus::Failed) {
                return $charge; // the webhook path already fired the events
            }
            throw new PaymentFailedException($result, $this, $charge, $this->paymentUrl());
        }
        if ($status === ChargeStatus::Succeeded) {
            if ($outcome === 'paid') {
                $this->afterPaid($charge);
            }
            return $charge;
        }
        if ($status === ChargeStatus::Held) {
            return $charge;
        }

        Dunning::handleFailure($this, $charge, $initiator);
        $this->reload();
        throw new PaymentFailedException($result, $this, $charge, $this->paymentUrl());
    }

    /** Called with the invoice row locked, inside a transaction. */
    private function applyPayment(?Charge $charge): void
    {
        $now = Cleat::now();
        Db::update('cleat_invoices', [
            'status' => InvoiceStatus::Paid,
            'amount_paid' => $this->amount_paid + ($charge?->amount ?? 0),
            'paid_at' => $now,
            'next_attempt_at' => null,
            'updated_at' => $now,
        ], ['id' => $this->id]);

        if ($this->subscription_id !== null) {
            $row = Db::one('SELECT status FROM cleat_subscriptions WHERE id = ? FOR UPDATE', [$this->subscription_id]);
            $current = $row !== null ? SubscriptionStatus::from((string) $row['status']) : null;
            $reactivate = [SubscriptionStatus::Incomplete, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue, SubscriptionStatus::Unpaid];
            if ($current !== null && in_array($current, $reactivate, true)) {
                Db::update('cleat_subscriptions', ['status' => SubscriptionStatus::Active, 'updated_at' => $now], ['id' => $this->subscription_id]);
            }
        }
    }

    private function afterPaid(?Charge $charge): void
    {
        if ($charge !== null) {
            Cleat::events()->dispatch(new PaymentSucceeded($this, $charge));
        }
        Cleat::events()->dispatch(new InvoicePaid($this));
        if (Cleat::config('automation.dispatch_emails') && $this->total > 0) {
            $this->sendReceipt($charge);
        }
    }

    private function assertNoChargeInFlight(): void
    {
        $inFlight = Db::one(
            "SELECT id, status, reason_code, created_at FROM cleat_charges WHERE invoice_id = ? AND (status IN ('pending', 'held') OR "
            . GatewayResult::unknownOutcomeSql() . ') ORDER BY id LIMIT 1',
            [$this->id],
        );
        if ($inFlight !== null) {
            throw new ChargeInProgressException(sprintf(
                'Invoice %s already has a %s charge (id %d) from %s. Cleat will not charge again until it settles or is reconciled '
                . '(webhook, or $invoice->reconcile()).',
                $this->number ?? $this->id,
                $inFlight['status'] === 'failed' ? 'unconfirmed (' . $inFlight['reason_code'] . ')' : $inFlight['status'],
                (int) $inFlight['id'],
                (string) $inFlight['created_at'],
            ));
        }
    }

    /** Push link_expires_at out to now + invoice_link_days, never pulling it in. */
    public function extendLink(): void
    {
        $days = Cleat::config('invoice_link_days');
        if ($days === null || $this->link_expires_at === null) {
            return;
        }
        $target = Cleat::now()->modify("+$days days");
        if ($target > $this->link_expires_at) {
            Db::update('cleat_invoices', ['link_expires_at' => $target, 'updated_at' => Cleat::now()], ['id' => $this->id]);
            $this->link_expires_at = $target;
        }
    }

    /** @internal Recompute subtotal/total from the lines. Draft only, inside a transaction. */
    public function recalculate(): void
    {
        $subtotal = (int) Db::value('SELECT COALESCE(SUM(amount), 0) FROM cleat_invoice_items WHERE invoice_id = ?', [$this->id]);
        $total = $subtotal + $this->tax;
        Db::update('cleat_invoices', ['subtotal' => $subtotal, 'total' => $total, 'updated_at' => Cleat::now()], ['id' => $this->id]);
        $this->subtotal = $subtotal;
        $this->total = $total;
    }

    /**
     * @internal Create a draft. Callers add lines and finalize.
     * @param array<string, mixed> $attributes
     */
    public static function createDraft(Customer $customer, array $attributes = []): self
    {
        $now = Cleat::now();
        $id = Db::insert('cleat_invoices', [
            'number' => null,
            'public_token' => Token::generate(),
            'customer_id' => $customer->id,
            'subscription_id' => $attributes['subscription_id'] ?? null,
            'payment_link_id' => $attributes['payment_link_id'] ?? null,
            'status' => InvoiceStatus::Draft,
            'currency' => strtoupper((string) ($attributes['currency'] ?? Cleat::config('currency'))),
            'tax' => (int) ($attributes['tax'] ?? 0),
            'memo' => $attributes['memo'] ?? null,
            'period_start' => $attributes['period_start'] ?? null,
            'period_end' => $attributes['period_end'] ?? null,
            'due_at' => $attributes['due_at'] ?? $now,
            'connected_account_id' => $attributes['connected_account_id'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return self::findOrFail($id);
    }

    /** @internal Add a line to a draft invoice. Finalized invoices are immutable. */
    public function addLine(string $description, int $unitAmount, int $quantity = 1, ?int $priceId = null, bool $isProration = false, ?DateTimeImmutable $periodStart = null, ?DateTimeImmutable $periodEnd = null): InvoiceItem
    {
        if ($this->status !== InvoiceStatus::Draft) {
            throw new CleatException('Lines can only be added to a draft invoice; finalized invoices are immutable.');
        }
        return InvoiceItem::create([
            'invoice_id' => $this->id,
            'subscription_id' => $this->subscription_id,
            'price_id' => $priceId,
            'description' => $description,
            'quantity' => $quantity,
            'unit_amount' => $unitAmount,
            'is_proration' => $isProration,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);
    }

    /** @internal Store the application fee once totals are known (Connect). */
    public function setApplicationFeeFromPercent(?string $percent): void
    {
        if ($percent === null) {
            return;
        }
        $fee = Money::percentage($this->total, $percent);
        Db::update('cleat_invoices', ['application_fee_amount' => $fee], ['id' => $this->id]);
        $this->application_fee_amount = $fee;
    }

    /**
     * Next number from cleat_sequences. Must run inside a transaction: the
     * row lock serializes concurrent finalizes, and a rollback releases the
     * number unused.
     */
    private static function nextNumber(): string
    {
        $current = Db::value("SELECT value FROM cleat_sequences WHERE name = 'invoice' FOR UPDATE");
        if ($current === null) {
            Db::run("INSERT IGNORE INTO cleat_sequences (name, value) VALUES ('invoice', 0)");
            $current = Db::value("SELECT value FROM cleat_sequences WHERE name = 'invoice' FOR UPDATE");
        }
        $next = (int) $current + 1;
        Db::run("UPDATE cleat_sequences SET value = ? WHERE name = 'invoice'", [$next]);
        return Cleat::config('invoice_prefix') . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /** SELECT ... FOR UPDATE and refresh every mutable field from the row. */
    private function lock(): void
    {
        $row = Db::one('SELECT * FROM cleat_invoices WHERE id = ? FOR UPDATE', [$this->id]);
        $this->sync($row ?? throw new NotFoundException("Invoice {$this->id} not found."));
    }

    private function reload(): self
    {
        $row = Db::one('SELECT * FROM cleat_invoices WHERE id = ?', [$this->id]);
        $this->sync($row ?? throw new NotFoundException("Invoice {$this->id} not found."));
        return $this;
    }

    /** @param array<string, mixed> $row */
    private function sync(array $row): void
    {
        $fresh = self::fromRow($row);
        $this->number = $fresh->number;
        $this->status = $fresh->status;
        $this->subtotal = $fresh->subtotal;
        $this->tax = $fresh->tax;
        $this->total = $fresh->total;
        $this->amount_paid = $fresh->amount_paid;
        $this->memo = $fresh->memo;
        $this->attempt_count = $fresh->attempt_count;
        $this->next_attempt_at = $fresh->next_attempt_at;
        $this->period_start = $fresh->period_start;
        $this->period_end = $fresh->period_end;
        $this->due_at = $fresh->due_at;
        $this->sent_at = $fresh->sent_at;
        $this->paid_at = $fresh->paid_at;
        $this->link_expires_at = $fresh->link_expires_at;
        $this->application_fee_amount = $fresh->application_fee_amount;
        $this->updated_at = $fresh->updated_at;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            $row['number'] !== null ? (string) $row['number'] : null,
            (string) $row['public_token'],
            (int) $row['customer_id'],
            $row['subscription_id'] !== null ? (int) $row['subscription_id'] : null,
            $row['payment_link_id'] !== null ? (int) $row['payment_link_id'] : null,
            InvoiceStatus::from((string) $row['status']),
            (string) $row['currency'],
            (int) $row['subtotal'],
            (int) $row['tax'],
            (int) $row['total'],
            (int) $row['amount_paid'],
            $row['memo'] !== null ? (string) $row['memo'] : null,
            (int) $row['attempt_count'],
            Db::date($row['next_attempt_at']),
            Db::date($row['period_start']),
            Db::date($row['period_end']),
            Db::date($row['due_at']),
            Db::date($row['sent_at']),
            Db::date($row['paid_at']),
            Db::date($row['link_expires_at']),
            $row['connected_account_id'] !== null ? (int) $row['connected_account_id'] : null,
            $row['application_fee_amount'] !== null ? (int) $row['application_fee_amount'] : null,
            Db::date($row['created_at']),
            Db::date($row['updated_at']),
        );
    }
}
