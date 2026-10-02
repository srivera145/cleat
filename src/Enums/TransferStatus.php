<?php

declare(strict_types=1);

namespace Cleat\Enums;

/** Values match cleat_transfers.status. */
enum TransferStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Reversed = 'reversed';
}
