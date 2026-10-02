<?php

declare(strict_types=1);

namespace Cleat\Tests\Unit;

use Cleat\Billing\Period;
use Cleat\Billing\Proration;
use Cleat\Enums\BillingInterval;
use Cleat\Money;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyPeriodProrationTest extends TestCase
{
    public function test_format_and_decimal(): void
    {
        $this->assertSame('$29.00', Money::of(2900, 'USD')->format());
        $this->assertSame('$1,234,567.89', Money::of(123456789, 'USD')->format());
        $this->assertSame('-$5.05', Money::of(-505, 'USD')->format());
        $this->assertSame('€0.99', Money::of(99, 'EUR')->format());
        $this->assertSame('¥1,200', Money::of(1200, 'JPY')->format());
        $this->assertSame('CA$10.00', Money::of(1000, 'CAD')->format());
        $this->assertSame('BRL 3.50', Money::of(350, 'BRL')->format());
        $this->assertSame('1234.50', Money::of(123450, 'USD')->toDecimal());
        $this->assertSame('-0.07', Money::of(-7, 'USD')->toDecimal());
    }

    public function test_parse_never_touches_a_float(): void
    {
        $this->assertSame(4500, Money::parse('45.00', 'USD')->amount);
        $this->assertSame(4550, Money::parse('45.5', 'USD')->amount);
        $this->assertSame(1, Money::parse('0.01', 'USD')->amount);
        $this->assertSame(-300, Money::parse('-3', 'USD')->amount);
        $this->assertSame(1200, Money::parse('1200', 'JPY')->amount);
        $this->assertSame(1999, Money::parse('19.990', 'USD')->amount, 'trailing zeros are fine');
        $this->expectException(InvalidArgumentException::class);
        Money::parse('19.999', 'USD');
    }

    public function test_arithmetic(): void
    {
        $a = Money::of(1000, 'USD');
        $this->assertSame(1500, $a->add(Money::of(500, 'USD'))->amount);
        $this->assertSame(500, $a->subtract(Money::of(500, 'USD'))->amount);
        $this->assertSame(3000, $a->multiply(3)->amount);
        $this->assertTrue($a->negate()->isNegative());
        $this->expectException(InvalidArgumentException::class);
        $a->add(Money::of(1, 'EUR'));
    }

    /** @return array<string, array{int, int, int, int}> */
    public static function bankers(): array
    {
        return [
            'exact' => [1000, 1, 2, 500],
            'half rounds to even (down)' => [1, 1, 2, 0],       // 0.5 -> 0
            'half rounds to even (up)' => [3, 1, 2, 2],         // 1.5 -> 2
            '2.5 -> 2' => [5, 1, 2, 2],
            '3.5 -> 4' => [7, 1, 2, 4],
            'above half rounds up' => [2, 1, 3, 1],              // 0.667 -> 1
            'below half rounds down' => [1, 1, 3, 0],            // 0.333 -> 0
            'negative half to even' => [-5, 1, 2, -2],           // -2.5 -> -2
            'negative above half' => [-2, 1, 3, -1],
        ];
    }

    #[DataProvider('bankers')]
    public function test_mul_div_uses_bankers_rounding(int $amount, int $num, int $den, int $expected): void
    {
        $this->assertSame($expected, Money::mulDiv($amount, $num, $den));
    }

    public function test_percentage_helper_parses_decimal_strings_with_integer_math(): void
    {
        $this->assertSame(250, Money::percentage(10000, '2.50'));
        $this->assertSame(1250, Money::percentage(10000, '12.5'));
        $this->assertSame(0, Money::percentage(10, '5.00'), '0.5 cents rounds to even 0');
        $this->assertSame(2, Money::percentage(30, '5'), '1.5 cents rounds to even 2');
        $this->assertSame(10000, Money::percentage(10000, '100.00'));
        $this->assertSame(0, Money::percentage(10000, '0'));
        $this->expectException(InvalidArgumentException::class);
        Money::percentage(100, '100.01');
    }

    public function test_overflow_is_detected(): void
    {
        $this->expectException(OverflowException::class);
        Money::mulDiv(PHP_INT_MAX, 2, 3);
    }

    public function test_month_end_anchoring(): void
    {
        $utc = new DateTimeZone('UTC');
        $jan31 = new DateTimeImmutable('2026-01-31 10:30:00', $utc);
        $feb = Period::advance($jan31, BillingInterval::Month, 1, 31);
        $mar = Period::advance($feb, BillingInterval::Month, 1, 31);
        $apr = Period::advance($mar, BillingInterval::Month, 1, 31);
        $this->assertSame('2026-02-28 10:30:00', $feb->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-31 10:30:00', $mar->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-30 10:30:00', $apr->format('Y-m-d H:i:s'));
        $this->assertSame('2024-02-29', Period::advance(new DateTimeImmutable('2024-01-31', $utc), BillingInterval::Month, 1, 31)->format('Y-m-d'));
        $this->assertSame('2026-12-31', Period::advance(new DateTimeImmutable('2026-10-31', $utc), BillingInterval::Month, 2, 31)->format('Y-m-d'));
        $this->assertSame('2027-01-15', Period::advance(new DateTimeImmutable('2026-12-15', $utc), BillingInterval::Month, 1, 15)->format('Y-m-d'));
        $this->assertSame('2029-02-28', Period::advance(new DateTimeImmutable('2028-02-29', $utc), BillingInterval::Year, 1, 29)->format('Y-m-d'));
        $this->assertSame('2032-02-29', Period::advance(new DateTimeImmutable('2031-02-28', $utc), BillingInterval::Year, 1, 29)->format('Y-m-d'));
        $this->assertSame('2026-01-22', Period::advance(new DateTimeImmutable('2026-01-08', $utc), BillingInterval::Week, 2, 8)->format('Y-m-d'));
        $this->assertSame('2026-03-01', Period::advance(new DateTimeImmutable('2026-02-28', $utc), BillingInterval::Day, 1, 28)->format('Y-m-d'));
    }

    public function test_proration_from_seconds_remaining(): void
    {
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable('2026-01-01 00:00:00', $utc);
        $end = new DateTimeImmutable('2026-02-01 00:00:00', $utc);
        $half = new DateTimeImmutable('2026-01-16 12:00:00', $utc);

        $up = Proration::calculate(1000, 1, 3000, 1, $start, $end, $half);
        $this->assertSame(['credit' => -500, 'debit' => 1500, 'net' => 1000, 'remaining_seconds' => 1339200, 'period_seconds' => 2678400], $up);

        $qty = Proration::calculate(1000, 3, 1000, 5, $start, $end, $half);
        $this->assertSame(-1500, $qty['credit']);
        $this->assertSame(2500, $qty['debit']);

        $odd = Proration::calculate(1001, 1, 0, 1, $start, $end, $half);
        $this->assertSame(-500, $odd['credit'], '500.5 rounds half to even');

        $after = Proration::calculate(1000, 1, 3000, 1, $start, $end, $end->modify('+1 day'));
        $this->assertSame(0, $after['net'], 'nothing remains after the period');

        // Monthly to yearly: the debit uses the yearly rate for the remaining time.
        $yearSeconds = Period::seconds($start, BillingInterval::Year, 1, 1);
        $toYearly = Proration::calculate(1000, 1, 12000, 1, $start, $end, $half, $yearSeconds);
        $this->assertSame(Money::mulDiv(12000, 1339200, 31536000), $toYearly['debit']);
    }
}
