<?php

declare(strict_types=1);

namespace Cleat\Enums;

/** Values match cleat_refunds.method. */
enum RefundMethod: string
{
    case Refund = 'refund';
    case Void = 'void';
}
