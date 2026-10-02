<?php

declare(strict_types=1);

namespace Cleat\Enums;

/** Values match cleat_payouts.status. */
enum PayoutStatus: string
{
    case Pending = 'pending';
    case InTransit = 'in_transit';
    case Paid = 'paid';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
