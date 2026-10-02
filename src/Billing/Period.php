<?php

declare(strict_types=1);

namespace Cleat\Billing;

use Cleat\Enums\BillingInterval;
use DateTimeImmutable;

/**
 * Billing period arithmetic.
 *
 * Monthly and yearly periods are computed from the subscription's stored
 * anchor day rather than from the previous period end, so short months do
 * not drift the date: anchored on the 31st, Jan 31 -> Feb 28 (29 in a leap
 * year) -> Mar 31 -> Apr 30 -> May 31. All times are UTC, so day and week
 * periods never meet a DST shift.
 */
final class Period
{
    public static function advance(DateTimeImmutable $from, BillingInterval $interval, int $count, int $anchorDay): DateTimeImmutable
    {
        return match ($interval) {
            BillingInterval::Day => $from->modify(sprintf('+%d days', $count)),
            BillingInterval::Week => $from->modify(sprintf('+%d days', 7 * $count)),
            BillingInterval::Month => self::addMonths($from, $count, $anchorDay),
            BillingInterval::Year => self::addMonths($from, 12 * $count, $anchorDay),
        };
    }

    /** Add whole months, landing on min(anchorDay, days in the target month). Keeps the time of day. */
    public static function addMonths(DateTimeImmutable $from, int $months, int $anchorDay): DateTimeImmutable
    {
        $index = (int) $from->format('Y') * 12 + ((int) $from->format('n') - 1) + $months;
        $year = intdiv($index, 12);
        $month = $index % 12 + 1;
        $daysInMonth = (int) $from->setDate($year, $month, 1)->format('t');
        return $from->setDate($year, $month, min(max(1, $anchorDay), $daysInMonth));
    }

    /** Length of one billing period starting at $from, in seconds. */
    public static function seconds(DateTimeImmutable $from, BillingInterval $interval, int $count, int $anchorDay): int
    {
        return self::advance($from, $interval, $count, $anchorDay)->getTimestamp() - $from->getTimestamp();
    }
}
