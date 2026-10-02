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
 * Composes and sends Cleat's three emails, each as HTML (from the template)
 * plus a plain-text part. A mailer failure is logged and never undoes the
 * billing work that triggered the email.
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
