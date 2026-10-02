<?php

declare(strict_types=1);

namespace Cleat\Enums;

/** Values match cleat_prices.billing_type. */
enum BillingType: string
{
    case OneTime = 'one_time';
    case Recurring = 'recurring';
}
