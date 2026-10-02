<?php

declare(strict_types=1);

namespace Cleat\Enums;

/** Values match cleat_prices.billing_interval. */
enum BillingInterval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public function label(int $count = 1): string
    {
        return $count === 1 ? $this->value : $count . ' ' . $this->value . 's';
    }
}
