<?php

declare(strict_types=1);

namespace Cleat\Events;

use Cleat\Invoice;

/** An invoice was finalized (draft to open). */
final class InvoiceCreated
{
    public function __construct(
        public readonly Invoice $invoice,
    ) {
    }
}
