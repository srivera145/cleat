<?php

declare(strict_types=1);

namespace Cleat\Events;

use Cleat\Customer;
use Cleat\Invoice;
use Cleat\PaymentLink;
use Cleat\Subscription;

/** A payment link checkout finished: paid (one-time), or a subscription started. */
final class CheckoutCompleted
{
    public function __construct(
        public readonly PaymentLink $link,
        public readonly Customer $customer,
        public readonly ?Invoice $invoice,
        public readonly ?Subscription $subscription,
    ) {
    }
}
