<?php

declare(strict_types=1);

namespace Cleat\Exceptions;

use Cleat\Charge;
use Cleat\Gateway\GatewayResult;
use Cleat\Invoice;

/**
 * A charge was declined or errored. Carries the gateway result, the invoice
 * (left open), the recorded charge when there is one, and the invoice's
 * hosted pay URL so the caller can hand the customer a way to finish paying.
 *
 * getMessage() is safe for logs. Never show $result->reasonText to a
 * customer; show customerMessage() instead.
 */
final class PaymentFailedException extends CleatException
{
    public function __construct(
        public readonly GatewayResult $result,
        public readonly ?Invoice $invoice = null,
        public readonly ?Charge $charge = null,
        public readonly ?string $payUrl = null,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
            'Payment failed%s: %s (reason %s)',
            $invoice?->number !== null ? ' for invoice ' . $invoice->number : '',
            $result->status->value,
            $result->reasonCode ?? 'n/a',
        ));
    }

    /** A generic line for the customer. Gateway reason text stays server side. */
    public function customerMessage(): string
    {
        if ($this->result->outcomeUnknown()) {
            return "We couldn't confirm your payment with the card network. Please wait a minute before trying again; "
                . "we check for this attempt first, so you won't be charged twice.";
        }
        return 'Your card was declined. Please check the details or try a different card.';
    }
}
