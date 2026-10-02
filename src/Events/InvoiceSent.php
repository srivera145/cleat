<?php

declare(strict_types=1);

namespace Cleat\Events;

use Cleat\Invoice;

/** An invoice email with its pay link was sent. */
final class InvoiceSent
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly string $to,
    ) {
    }
}
