<?php

declare(strict_types=1);

namespace Cleat\Enums;

/** Values match cleat_payouts.method. */
enum PayoutMethod: string
{
    case Ach = 'ach';
    case Rtp = 'rtp';
    case Card = 'card';
}
