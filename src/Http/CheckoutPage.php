<?php

declare(strict_types=1);

namespace Cleat\Http;

use Cleat\Billing\CheckoutCompletion;
use Cleat\Charge;
use Cleat\Cleat;
use Cleat\Customer;
use Cleat\Enums\ChargeStatus;
use Cleat\Enums\InvoiceStatus;
use Cleat\Enums\SubscriptionStatus;
use Cleat\Exceptions\ChargeInProgressException;
use Cleat\Exceptions\PaymentFailedException;
use Cleat\Invoice;
use Cleat\PaymentLink;
use Cleat\Price;
use Cleat\Subscription;
use Cleat\Support\Db;
use Cleat\Token;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Checkout for a payment link: guest buyers, one-time or recurring prices.
 *
 *   $page = new CheckoutPage(session_id());
 *   $response = $_SERVER['REQUEST_METHOD'] === 'POST'
 *       ? $page->submit($token, $_POST, $_SERVER['REMOTE_ADDR'])
 *       : $page->show($token);
 */
final class CheckoutPage extends HostedPage
{
    public function show(string $token): Response
    {
        [$link, $price, $error] = $this->resolve($token);
        if ($error !== null) {
            return $error;
        }
        return $this->form($link, $price, Cleat::now());
    }

    /** @param array<string, mixed> $post */
    public function submit(string $token, array $post, string $ip): Response
    {
        $now = Cleat::now();
        $ip = self::cleanIp($ip);
        if (!Token::isValid($token)) {
            return $this->notFound();
        }

        // 1. Rate limits.
        if (($limited = $this->rateLimit($token, $ip, $now)) !== null) {
            return $limited;
        }
        [$link, $price, $error] = $this->resolve($token);
        if ($error !== null) {
            return $error;
        }

        // 2. CSRF and captcha.
        if (!$this->verifyCsrf($post, $token, $now)) {
            return $this->forbidden();
        }
        $values = $this->values($post, $link);
        if (!$this->captcha->verify($post, $ip)) {
            return $this->form($link, $price, $now, ['Please complete the verification and try again.'], $values, 422);
        }

        // 3. Validate.
        $errors = $this->validate($values, $link);
        $opaque = $this->opaqueFrom($post);
        if ($opaque === null) {
            $errors[] = 'Enter your card details.';
        }
        if ($errors !== []) {
            return $this->form($link, $price, $now, $errors, $values, 422);
        }

        // An earlier attempt by this buyer may have ended without an answer
        // (timeout). Settle it before charging again; if it captured, the
        // buyer already paid and sees that purchase instead.
        if (($earlier = $this->resumeEarlierAttempt($link, $price, $values, $now)) !== null) {
            return $earlier;
        }

        // 4-5. Customer, then charge or subscribe.
        $invoice = null;
        $subscription = null;
        $charge = null;
        try {
            if ($price->isRecurring()) {
                [$customer, $subscription, $invoice, $charge] = $this->subscribe($link, $price, $values, $opaque);
            } else {
                [$customer, $invoice, $charge] = $this->buyOnce($link, $price, $values, $opaque, $ip);
            }
        } catch (PaymentFailedException $e) {
            // 7. Failure: a generic message, never the gateway's reason text.
            // An unconfirmed outcome (timeout) is not the card's fault.
            if (!$e->result->outcomeUnknown()) {
                $this->recordFailure($ip, $now);
            }
            return $this->form($link, $price, $now, [$e->customerMessage()], $values, 402);
        } catch (InvalidArgumentException) {
            $this->recordFailure($ip, $now);
            return $this->form($link, $price, $now, ['Check your details and card, then try again.'], $values, 422);
        } catch (ChargeInProgressException) {
            return $this->form($link, $price, $now, ['A payment is already being processed. Please wait a moment before trying again.'], $values, 409);
        }

        // 6. Count the use atomically, fire CheckoutCompleted, confirm. Losing
        // the race means the link sold out while this payment was in flight:
        // give the money back.
        if (!CheckoutCompletion::complete($link, $customer, $invoice, $subscription, $charge)) {
            return $this->soldOut(CheckoutCompletion::reverse($customer, $invoice, $subscription, $charge), $charge);
        }
        return $this->success($link, $price, $customer, $invoice, $subscription, $charge instanceof Charge && $charge->status === ChargeStatus::Held);
    }

    private function success(PaymentLink $link, Price $price, Customer $customer, ?Invoice $invoice, ?Subscription $subscription, bool $held): Response
    {
        if ($link->success_url !== null && !$held) {
            return Response::redirect($link->success_url)->withHeaders($this->securityHeaders(self::originOf($link->success_url)));
        }
        return $this->page('checkout_success', [
            'link' => $link,
            'price' => $price,
            'product' => $price->product(),
            'customer' => $customer,
            'invoice' => $invoice,
            'subscription' => $subscription,
            'held' => $held,
        ]);
    }

    /** @return array{0: Customer, 1: Invoice, 2: ?Charge} */
    private function buyOnce(PaymentLink $link, Price $price, array $values, array $opaque, string $ip): array
    {
        $customer = Customer::findOrCreateGuest($values['email'], $values['name'], $values['phone']);
        $invoice = $customer->newInvoice()
            ->addPrice($price->id, $values['quantity'])
            ->forPaymentLink($link)
            ->dueIn(0)
            ->finalize();
        try {
            $charge = $invoice->payWithToken($opaque, false, $ip);
        } catch (PaymentFailedException $e) {
            // A decline: the invoice existed only for this attempt, so close it
            // and declined checkouts do not pile up as open invoices. An
            // unknown outcome stays open; the buyer's next try reconciles it.
            if (!$e->result->outcomeUnknown() && $invoice->refresh()->status === InvoiceStatus::Open) {
                $invoice->void();
            }
            throw $e;
        }
        return [$customer, $invoice->refresh(), $charge];
    }

    /**
     * Earlier checkouts by this buyer on this link whose outcome was unknown
     * (timeout). Open ones are reconciled with the gateway first: if one
     * captured, Invoice::settleCharge() finishes that purchase and the buyer
     * sees it; if not, it is closed and a fresh purchase may go ahead. One
     * that a webhook already settled as captured in the last 30 minutes is
     * shown too, so a buyer who retries after "we couldn't confirm" is not
     * charged a second time.
     */
    private function resumeEarlierAttempt(PaymentLink $link, Price $price, array $values, DateTimeImmutable $now): ?Response
    {
        $open = Db::all(
            'SELECT i.* FROM cleat_invoices i JOIN cleat_customers c ON c.id = i.customer_id
             WHERE i.payment_link_id = ? AND i.status = ? AND c.email = ? AND c.user_id IS NULL AND i.created_at >= ?
             ORDER BY i.id',
            [$link->id, InvoiceStatus::Open->value, $values['email'], $now->modify('-1 day')],
        );
        foreach ($open as $row) {
            $invoice = Invoice::fromRow($row);
            try {
                if ($invoice->reconcile() !== null) {
                    return $this->lateCapture($link, $price, $invoice->refresh());
                }
                $abandoned = $invoice->subscription();
                $invoice->void();
                if ($abandoned !== null && $abandoned->status === SubscriptionStatus::Incomplete) {
                    $abandoned->cancelNow();
                }
            } catch (ChargeInProgressException) {
                return $this->form($link, $price, $now, ['Your earlier payment is still being processed. Please wait a moment before trying again.'], $values, 409);
            }
        }

        $captured = Db::one(
            "SELECT i.* FROM cleat_invoices i
             JOIN cleat_customers c ON c.id = i.customer_id
             JOIN cleat_charges ch ON ch.invoice_id = i.id
             WHERE i.payment_link_id = ? AND c.email = ? AND c.user_id IS NULL
               AND ch.status = 'succeeded' AND ch.reason_code LIKE '%\\_captured' AND ch.updated_at >= ?
             ORDER BY i.id DESC LIMIT 1",
            [$link->id, $values['email'], $now->modify('-30 minutes')],
        );
        return $captured !== null ? $this->lateCapture($link, $price, Invoice::fromRow($captured)) : null;
    }

    /** The buyer's earlier, unconfirmed payment did go through: show that purchase. */
    private function lateCapture(PaymentLink $link, Price $price, Invoice $invoice): Response
    {
        if ($invoice->status === InvoiceStatus::Void) {
            // It captured, but the link had sold out by then and it was reversed.
            return $this->soldOut(true, null);
        }
        return $this->success($link, $price, $invoice->customer(), $invoice, $invoice->subscription(), false);
    }

    /** @return array{0: Customer, 1: Subscription, 2: ?Invoice, 3: ?Charge} */
    private function subscribe(PaymentLink $link, Price $price, array $values, array $opaque): array
    {
        $customer = Customer::findOrCreateGuest($values['email'], $values['name'], $values['phone']);
        if ($customer->hasPaymentMethod()) {
            // Someone else may own this email. Never replace a stored card
            // that existing renewals depend on: start a fresh guest instead.
            $customer = Customer::createGuest($values['email'], $values['name'], $values['phone']);
        }
        // A card on file is required for renewals.
        $customer->updatePaymentMethod($opaque);

        try {
            $subscription = $customer->newSubscription($price->lookup_key)
                ->quantity($values['quantity'])
                ->fromPaymentLink($link)
                ->create();
        } catch (PaymentFailedException $e) {
            if ($e->invoice !== null && !$e->result->outcomeUnknown()) {
                $abandoned = $e->invoice->subscription();
                if ($e->invoice->refresh()->status === InvoiceStatus::Open) {
                    $e->invoice->void();
                }
                if ($abandoned !== null && $abandoned->status === SubscriptionStatus::Incomplete) {
                    $abandoned->cancelNow();
                }
            }
            throw $e;
        }
        $invoice = $subscription->latestInvoice();
        $charge = null;
        if ($invoice !== null) {
            $charges = $invoice->charges();
            $charge = $charges === [] ? null : $charges[array_key_last($charges)];
        }
        return [$customer, $subscription, $invoice, $charge];
    }

    /** The last use went to a concurrent checkout; the purchase was reversed. Say what happened to the money. */
    private function soldOut(bool $refunded, ?Charge $charge): Response
    {
        $message = match (true) {
            $refunded => 'This offer sold out while your payment was processing. Your payment has been refunded in full.',
            $charge !== null && in_array($charge->status, [ChargeStatus::Succeeded, ChargeStatus::Held], true)
                => 'This offer sold out while your payment was processing. Any amount taken will be refunded; contact us if you do not see it within a few days.',
            default => 'This offer sold out while your order was processing. You have not been charged for it.',
        };
        return $this->gone('Sold out', $message);
    }

    /** @return array{0: ?PaymentLink, 1: ?Price, 2: ?Response} */
    private function resolve(string $token): array
    {
        $link = PaymentLink::findByToken($token);
        if ($link === null) {
            return [null, null, $this->notFound()];
        }
        if ($link->isExpired()) {
            return [null, null, $this->gone('This link has expired', 'This offer is no longer available.')];
        }
        if ($link->isSoldOut()) {
            return [null, null, $this->gone('Sold out', 'This offer has reached its limit and is no longer available.')];
        }
        if (!$link->is_active) {
            return [null, null, $this->notFound()];
        }
        $price = $link->price();
        if (!$price->is_active || !$price->product()->is_active) {
            return [null, null, $this->gone('No longer available', 'This offer is no longer available.')];
        }
        return [$link, $price, null];
    }

    /**
     * @param list<string> $errors
     * @param array<string, mixed>|null $values
     */
    private function form(PaymentLink $link, Price $price, DateTimeImmutable $now, array $errors = [], ?array $values = null, int $status = 200): Response
    {
        $values ??= ['email' => '', 'name' => '', 'phone' => '', 'quantity' => $link->quantity];
        [$minQty, $maxQty] = $link->quantityBounds();
        $quantity = (int) $values['quantity'];
        // Browsers check form-action on the redirect that follows a submit
        // against the CSP of the page holding the form, so the success_url's
        // origin has to be allowed here, not only on the redirect response.
        return $this->page('checkout', [
            'link' => $link,
            'price' => $price,
            'product' => $price->product(),
            'quantity' => $quantity,
            'minQuantity' => $minQty,
            'maxQuantity' => $maxQty,
            'amount' => $price->money($quantity),
            'priceText' => $price->display($quantity),
            'trialText' => $price->isRecurring() ? $price->trialText($quantity) : null,
            'values' => $values,
            'errors' => $errors,
            'csrf' => $this->csrfToken($link->public_token, $now),
        ], $status, self::originOf((string) $link->success_url));
    }

    /** @return array{email: string, name: ?string, phone: ?string, quantity: int} */
    private function values(array $post, PaymentLink $link): array
    {
        $quantity = $link->allow_quantity_change && isset($post['quantity']) && is_numeric($post['quantity'])
            ? (int) $post['quantity']
            : $link->quantity;
        $text = static fn (string $key): ?string => isset($post[$key]) && is_string($post[$key]) && trim($post[$key]) !== '' ? trim($post[$key]) : null;
        return [
            'email' => (string) ($text('email') ?? ''),
            'name' => $link->collect_name ? $text('name') : null,
            'phone' => $link->collect_phone ? $text('phone') : null,
            'quantity' => $quantity,
        ];
    }

    /**
     * @param array{email: string, name: ?string, phone: ?string, quantity: int} $values
     * @return list<string>
     */
    private function validate(array $values, PaymentLink $link): array
    {
        $errors = [];
        if ($values['email'] === '' || strlen($values['email']) > 255 || filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($link->collect_name && ($values['name'] === null || mb_strlen($values['name']) > 255)) {
            $errors[] = 'Enter your name.';
        }
        if ($link->collect_phone && ($values['phone'] === null || preg_match('/^[0-9+().\-\s]{7,32}$/D', $values['phone']) !== 1)) {
            $errors[] = 'Enter a valid phone number.';
        }
        [$min, $max] = $link->quantityBounds();
        if ($values['quantity'] < $min || $values['quantity'] > $max) {
            $errors[] = sprintf('Quantity must be between %d and %d.', $min, $max);
        }
        return $errors;
    }

    private static function originOf(string $url): ?string
    {
        return preg_match('#^(https?://[^/?\#]+)#i', $url, $m) === 1 ? strtolower($m[1]) : null;
    }
}
