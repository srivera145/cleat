<?php

declare(strict_types=1);

namespace Cleat\Enums;

/** Values match cleat_invoices.status. */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';
    case Uncollectible = 'uncollectible';
}
