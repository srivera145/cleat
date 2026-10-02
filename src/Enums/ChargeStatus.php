<?php

declare(strict_types=1);

namespace Cleat\Enums;

/** Values match cleat_charges.status. */
enum ChargeStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Held = 'held';
    case Refunded = 'refunded';
    case Voided = 'voided';
}
