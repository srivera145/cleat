<?php

declare(strict_types=1);

namespace Cleat\Mail;

use Cleat\Charge;
use Cleat\Cleat;
use Cleat\Customer;
use Cleat\Invoice;
use Cleat\Subscription;
use Cleat\Support\Log;
use Cleat\Support\View;
use DateTimeImmutable;
use Throwable;

/**
 * Composes and sends Cleat's emails (invoice, payment failed, receipt and
 * trial started, trial ending, subscription canceled), each as HTML (from the
 * template) plus a plain-text part. A mailer failure is logged and never
 * undoes the billing work that triggered the email.
 */
final class Messages
{
    public static function invoice(Invoice $invoice, Customer $customer): void
    {
        $brand = self::brandName();
        $payUrl = (string) $invoice->paymentUrl();
        $subject = sprintf('Invoice %s from %s', $invoice->number, $brand);
        $html = View::render('emails/invoice', self::common($invoice, $customer) + ['payUrl' => $payUrl]);
        $text = implode("\n", [
            sprintf('Invoice %s from %s', $invoice->number, $brand),
            '',
            sprintf('Amount due: %s', $invoice->amountDueMoney()->format()),
            sprintf('Due: %s', $invoice->due_at->format('M j, Y')),
            '',
            'Pay online: ' . $payUrl,
            ...self::supportLines(),
        ]);
        self::send($customer->email, $subject, $html, $text);
    }

    public static function paymentFailed(Invoice $invoice, Customer $customer, ?DateTimeImmutable $nextAttempt): void
    {
        $brand = self::brandName();
        $payUrl = $invoice->paymentUrl();
        $subject = sprintf('Payment failed for invoice %s', $invoice->number);
        $html = View::render('emails/payment_failed', self::common($invoice, $customer) + [
            'payUrl' => $payUrl,
            'nextAttempt' => $nextAttempt,
            'cardLabel' => $customer->paymentMethodLabel(),
        ]);
        $text = implode("\n", array_filter([
            sprintf('We could not process your payment of %s to %s.', $invoice->amountDueMoney()->format(), $brand),
            '',
            $nextAttempt !== null ? sprintf('We will try again on %s.', $nextAttempt->format('M j, Y')) : null,
            $payUrl !== null ? 'Update your card and pay now: ' . $payUrl : null,
            ...self::supportLines(),
        ], static fn ($line) => $line !== null));
        self::send($customer->email, $subject, $html, $text);
    }

    public static function receipt(Invoice $invoice, Customer $customer, ?Charge $charge): void
    {
        $subject = sprintf('Receipt from %s, invoice %s', self::brandName(), $invoice->number);
        $html = View::render('emails/receipt', self::common($invoice, $customer) + [
            'charge' => $charge,
            'trial' => null,
            'cardLabel' => $customer->paymentMethodLabel(),
        ]);
        $text = implode("\n", [
            sprintf('Receipt for invoice %s from %s.', $invoice->number, self::brandName()),
            sprintf('Amount paid: %s on %s', $invoice->money($invoice->amount_paid)->format(), ($invoice->paid_at ?? Cleat::now())->format('M j, Y')),
            ...self::supportLines(),
        ]);
        self::send($customer->email, $subject, $html, $text);
    }

    /** Confirmation for a checkout that started a free trial: nothing was charged. */
    public static function trialStarted(Subscription $subscription, Customer $customer): void
    {
        $price = $subscription->price();
        $subject = sprintf('Your %s trial has started', $price->product()->name);
        $html = View::render('emails/receipt', [
            'invoice' => null,
            'items' => [],
            'customer' => $customer,
            'brand' => Cleat::config('brand'),
            'brandName' => self::brandName(),
            'buttonColor' => self::buttonColor(),
            'charge' => null,
            'trial' => $subscription,
            'trialPrice' => $price,
            'cardLabel' => $customer->paymentMethodLabel(),
        ]);
        $text = implode("\n", [
            sprintf('Your free trial of %s has started. Nothing has been charged.', $price->label()),
            sprintf('It ends on %s, then %s.', $subscription->trial_ends_at?->format('M j, Y') ?? '', $price->display($subscription->quantity)),
            ...self::supportLines(),
        ]);
        self::send($customer->email, $subject, $html, $text);
    }

    /** A trial converts to a paid period soon (trial_ending_days before it ends). */
    public static function trialEnding(Subscription $subscription, Customer $customer): void
    {
        $price = $subscription->price();
        $endsOn = $subscription->trial_ends_at?->format('M j, Y') ?? '';
        $subject = sprintf('Your %s trial ends on %s', $price->product()->name, $endsOn);
        $html = View::render('emails/trial_ending', [
            'subscription' => $subscription,
            'price' => $price,
            'customer' => $customer,
            'brand' => Cleat::config('brand'),
            'brandName' => self::brandName(),
            'buttonColor' => self::buttonColor(),
            'cardLabel' => $customer->paymentMethodLabel(),
        ]);
        $text = implode("\n", [
            sprintf('Your free trial of %s ends on %s.', $price->label(), $endsOn),
            sprintf('Then %s.', $price->display($subscription->quantity)),
            $customer->paymentMethodLabel() !== null
                ? sprintf('We will charge your %s.', $customer->paymentMethodLabel())
                : 'There is no card on file yet: add one to keep your plan.',
            "Cancel before the trial ends and you won't be charged.",
            ...self::supportLines(),
        ]);
        self::send($customer->email, $subject, $html, $text);
    }

    /**
     * A subscription was canceled: at the end of its period (it keeps working
     * until then and can be resumed), or right away.
     */
    public static function subscriptionCanceled(Subscription $subscription, Customer $customer, bool $immediately): void
    {
        $price = $subscription->price();
        $endsOn = ($subscription->ends_at ?? $subscription->current_period_end)->format('M j, Y');
        $subject = sprintf('Your %s subscription is canceled', $price->product()->name);
        $html = View::render('emails/subscription_canceled', [
            'subscription' => $subscription,
            'price' => $price,
            'customer' => $customer,
            'brand' => Cleat::config('brand'),
            'brandName' => self::brandName(),
            'buttonColor' => self::buttonColor(),
            'immediately' => $immediately,
        ]);
        $text = implode("\n", [
            $immediately
                ? sprintf("Your subscription to %s ended on %s. You won't be charged again.", $price->label(), $endsOn)
                : sprintf("Your subscription to %s stays active until %s, and you won't be charged again.", $price->label(), $endsOn),
            ...($immediately ? [] : ['You can reactivate it any time before then.']),
            ...self::supportLines(),
        ]);
        self::send($customer->email, $subject, $html, $text);
    }

    /** @return array<string, mixed> */
    private static function common(Invoice $invoice, Customer $customer): array
    {
        return [
            'invoice' => $invoice,
            'items' => $invoice->items(),
            'customer' => $customer,
            'brand' => Cleat::config('brand'),
            'brandName' => self::brandName(),
            'buttonColor' => self::buttonColor(),
        ];
    }

    private static function brandName(): string
    {
        $name = trim((string) Cleat::config('brand.name', ''));
        return $name !== '' ? $name : 'us';
    }

    /** @return list<string> */
    private static function supportLines(): array
    {
        $support = trim((string) Cleat::config('brand.support_email', ''));
        return $support !== '' ? ['', 'Questions? ' . $support] : [];
    }

    private static function buttonColor(): string
    {
        $color = (string) Cleat::config('brand.color', '#0f766e');
        return preg_match('/^#[0-9a-fA-F]{6}$/D', $color) === 1 ? $color : '#0f766e';
    }

    private static function send(string $to, string $subject, string $html, string $text): void
    {
        try {
            Cleat::mailer()->send($to, $subject, $html, $text);
        } catch (Throwable $e) {
            Log::error('Cleat email could not be sent', ['subject' => $subject, 'exception' => $e::class, 'message' => $e->getMessage()]);
        }
    }
}
