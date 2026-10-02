<?php

declare(strict_types=1);

namespace Cleat\Events;

use Cleat\Invoice;

/** An invoice reached the paid status. */
final class InvoicePaid
{
    public function __construct(
        public readonly Invoice $invoice,
    ) {
    }
}
