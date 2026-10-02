<?php

declare(strict_types=1);

namespace Cleat\Billing;

use Cleat\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Proration from the seconds remaining in the current period, in integer
 * cents with banker's rounding.
 *
 *   credit = -(old price x quantity) x remaining / period length
 *   debit  =  (new price x quantity) x remaining / new price's period length
 *   net    =  debit + credit
 *
 * When both prices share an interval the two period lengths are the same,
 * which is the usual case. For an interval change (monthly to yearly) the
 * debit uses the new price's own period length, so the customer pays the
 * yearly rate for the days left in the current monthly period.
 */
final class Proration
{
    /**
     * @return array{credit: int, debit: int, net: int, remaining_seconds: int, period_seconds: int}
     */
    public static function calculate(
        int $oldUnitAmount,
        int $oldQuantity,
        int $newUnitAmount,
        int $newQuantity,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        DateTimeImmutable $now,
        ?int $newPeriodSeconds = null,
    ): array {
        $period = $periodEnd->getTimestamp() - $periodStart->getTimestamp();
        if ($period <= 0) {
            throw new InvalidArgumentException('The billing period must have a positive length.');
        }
        if ($newPeriodSeconds !== null && $newPeriodSeconds <= 0) {
            throw new InvalidArgumentException('The new period length must be positive.');
        }
        $remaining = max(0, min($period, $periodEnd->getTimestamp() - $now->getTimestamp()));

        $credit = -Money::mulDiv(Money::of($oldUnitAmount)->multiply($oldQuantity)->amount, $remaining, $period);
        $debit = Money::mulDiv(Money::of($newUnitAmount)->multiply($newQuantity)->amount, $remaining, $newPeriodSeconds ?? $period);

        return [
            'credit' => $credit,
            'debit' => $debit,
            'net' => $debit + $credit,
            'remaining_seconds' => $remaining,
            'period_seconds' => $period,
        ];
    }
}
