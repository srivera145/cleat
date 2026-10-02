<?php

declare(strict_types=1);

namespace Cleat\Enums;

/** Values match cleat_refunds.status. */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
