<?php

declare(strict_types=1);

namespace Cleat\Events;

use Cleat\Charge;
use Cleat\Invoice;

/** A charge succeeded and the invoice is paid. */
final class PaymentSucceeded
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly Charge $charge,
    ) {
    }
}
