<?php

declare(strict_types=1);

namespace Cleat\Billing;

use Cleat\Charge;
use Cleat\Cleat;
use Cleat\Customer;
use Cleat\Enums\ChargeStatus;
use Cleat\Enums\InvoiceStatus;
use Cleat\Enums\SubscriptionStatus;
use Cleat\Events\CheckoutCompleted;
use Cleat\Exceptions\RefundFailedException;
use Cleat\Invoice;
use Cleat\Mail\Messages;
use Cleat\PaymentLink;
use Cleat\Subscription;
use Cleat\Support\Log;

/**
 * Finishing a payment link purchase: count the use, announce it, send the
 * confirmation. Shared by the checkout request and by the late path, where
 * a charge whose response never arrived turns out (webhook or
 * reconciliation) to have captured after the buyer left the page.
 */
final class CheckoutCompletion
{
    /**
     * Count the use atomically and fire CheckoutCompleted. Returns false when
     * the link sold out in the meantime; the caller then reverses the purchase.
     */
    public static function complete(PaymentLink $link, Customer $customer, ?Invoice $invoice, ?Subscription $subscription, ?Charge $charge): bool
    {
        if (!$link->claimUse()) {
            return false;
        }
        Cleat::events()->dispatch(new CheckoutCompleted($link, $customer, $invoice, $subscription));
        $held = $charge !== null && $charge->status === ChargeStatus::Held;
        if ($subscription !== null && $subscription->status === SubscriptionStatus::Trialing) {
            Messages::trialStarted($subscription, $customer);
        } elseif (!$held && $invoice !== null && $invoice->status === InvoiceStatus::Paid && !Cleat::config('automation.dispatch_emails')) {
            // With dispatch_emails on, the payment itself already sent the receipt.
            $invoice->sendReceipt($charge);
        }
        return true;
    }

    /**
     * The link sold out under this purchase: refund (or void) the money,
     * cancel the subscription, close the invoice. Returns whether the money
     * went back automatically; when it could not, the merchant is alerted.
     */
    public static function reverse(Customer $customer, ?Invoice $invoice, ?Subscription $subscription, ?Charge $charge): bool
    {
        $refunded = false;
        if ($charge !== null && $charge->status === ChargeStatus::Succeeded) {
            try {
                $charge->refund();
                $refunded = true;
            } catch (RefundFailedException $e) {
                Log::error('Sold-out checkout could not be refunded automatically; refund it by hand', [
                    'charge_id' => $charge->id, 'reason' => $e->getMessage(),
                ]);
            }
        } elseif ($charge !== null) {
            Log::error('Sold-out checkout left a charge that is not refundable yet; resolve it by hand', ['charge_id' => $charge->id, 'status' => $charge->status->value]);
        }
        $subscription?->cancelNow();
        if ($invoice !== null) {
            $invoice->refresh();
            if ($invoice->status === InvoiceStatus::Paid && $refunded) {
                $invoice->voidAfterRefund();
            } elseif ($invoice->status === InvoiceStatus::Open && ($charge === null || $charge->status !== ChargeStatus::Held)) {
                $invoice->void();
            }
        }
        Log::info('Checkout lost the race for the last use of a payment link', ['customer_id' => $customer->id, 'refunded' => $refunded]);
        return $refunded;
    }

    /**
     * @internal A checkout charge with an unknown outcome captured after all.
     * The request that started it already told the buyer it could not
     * confirm, so finish the purchase here, exactly once.
     */
    public static function completeLate(Invoice $invoice, Charge $charge): void
    {
        $link = $invoice->paymentLink();
        if ($link === null) {
            return;
        }
        $customer = $invoice->customer();
        $subscription = $invoice->subscription();
        if (!self::complete($link, $customer, $invoice, $subscription, $charge)) {
            self::reverse($customer, $invoice, $subscription, $charge);
            Log::warning('A late-captured checkout found its payment link sold out and was reversed', ['invoice_id' => $invoice->id, 'charge_id' => $charge->id]);
        }
    }
}
