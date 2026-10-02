<?php

declare(strict_types=1);

namespace Cleat\Http;

use Cleat\Charge;
use Cleat\Cleat;
use Cleat\Enums\ChargeStatus;
use Cleat\Enums\InvoiceStatus;
use Cleat\Exceptions\ChargeInProgressException;
use Cleat\Exceptions\CleatException;
use Cleat\Exceptions\PaymentFailedException;
use Cleat\Invoice;
use Cleat\Token;
use InvalidArgumentException;

/**
 * The hosted invoice page: the customer opens the link from the invoice
 * email and pays without logging in.
 *
 *   $page = new InvoicePayPage(session_id());
 *   $response = $_SERVER['REQUEST_METHOD'] === 'POST'
 *       ? $page->submit($token, $_POST, $_SERVER['REMOTE_ADDR'])
 *       : $page->show($token);
 *
 * Never echoes, never calls header(). The host sends the Response.
 */
final class InvoicePayPage extends HostedPage
{
    public function show(string $token): Response
    {
        $now = Cleat::now();
        [$invoice, $error] = $this->resolve($token);
        if ($error !== null) {
            return $error;
        }
        if ($invoice->status === InvoiceStatus::Paid) {
            return $this->view($invoice, 'paid');
        }
        return $this->form($invoice, $now);
    }

    /** @param array<string, mixed> $post */
    public function submit(string $token, array $post, string $ip): Response
    {
        $now = Cleat::now();
        $ip = self::cleanIp($ip);
        if (!Token::isValid($token)) {
            return $this->notFound();
        }
        if (($limited = $this->rateLimit($token, $ip, $now)) !== null) {
            return $limited;
        }
        [$invoice, $error] = $this->resolve($token);
        if ($error !== null) {
            return $error;
        }
        if ($invoice->status === InvoiceStatus::Paid) {
            return $this->view($invoice, 'paid');
        }
        if (!$this->verifyCsrf($post, $token, $now)) {
            return $this->forbidden();
        }
        if (!$this->captcha->verify($post, $ip)) {
            return $this->form($invoice, $now, ['Please complete the verification and try again.'], 422);
        }

        $customer = $invoice->customer();
        $useSaved = ($post['payment_method'] ?? '') === 'saved' && $customer->hasPaymentMethod();
        try {
            if ($useSaved) {
                $charge = $invoice->payWithSavedCard($ip);
            } else {
                $opaque = $this->opaqueFrom($post);
                if ($opaque === null) {
                    return $this->form($invoice, $now, ['Enter your card details to pay.'], 422);
                }
                $charge = $invoice->payWithToken($opaque, ($post['save_card'] ?? '') === '1', $ip);
            }
        } catch (PaymentFailedException $e) {
            if (!$e->result->outcomeUnknown()) {
                $this->recordFailure($ip, $now); // an unconfirmed outcome is not the card's fault
            }
            return $this->form($invoice->refresh(), $now, [$e->customerMessage()], 402);
        } catch (ChargeInProgressException) {
            return $this->form($invoice->refresh(), $now, ['A payment for this invoice is already being processed. Check back in a few minutes before trying again.'], 409);
        } catch (InvalidArgumentException) {
            $this->recordFailure($ip, $now);
            return $this->form($invoice, $now, ['Enter your card details to pay.'], 422);
        } catch (CleatException) {
            // The invoice changed state between loading and paying (paid elsewhere, voided).
            return $this->show($token);
        }

        $invoice->refresh();
        if ($charge instanceof Charge && $charge->status === ChargeStatus::Held) {
            return $this->view($invoice, 'review');
        }
        return $this->view($invoice, 'receipt', $charge);
    }

    /** @return array{0: ?Invoice, 1: ?Response} */
    private function resolve(string $token): array
    {
        $invoice = Invoice::findByToken($token);
        if ($invoice === null || $invoice->status === InvoiceStatus::Draft) {
            return [null, $this->notFound()];
        }
        if ($invoice->linkExpired()) {
            return [null, $this->gone('This link has expired', 'This payment link is no longer active. Contact us for a new one.')];
        }
        if (in_array($invoice->status, [InvoiceStatus::Void, InvoiceStatus::Uncollectible], true)) {
            return [null, $this->gone('This invoice is closed', 'This invoice can no longer be paid online. Contact us if you think that is a mistake.')];
        }
        return [$invoice, null];
    }

    /** @param list<string> $errors */
    private function form(Invoice $invoice, \DateTimeImmutable $now, array $errors = [], int $status = 200): Response
    {
        return $this->view($invoice, 'pay', null, $errors, $status, $this->csrfToken($invoice->public_token, $now));
    }

    /** @param list<string> $errors */
    private function view(Invoice $invoice, string $state, ?Charge $charge = null, array $errors = [], int $status = 200, ?string $csrf = null): Response
    {
        $customer = $invoice->customer();
        return $this->page('pay_invoice', [
            'state' => $state,
            'invoice' => $invoice,
            'items' => $invoice->items(),
            'customer' => $customer,
            'savedCard' => $customer->paymentMethodLabel(),
            'charge' => $charge,
            'errors' => $errors,
            'csrf' => $csrf,
        ], $status);
    }
}
