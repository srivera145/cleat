<?php

declare(strict_types=1);

namespace Cleat;

use InvalidArgumentException;
use JsonSerializable;
use OverflowException;
use Stringable;

/**
 * An amount in integer minor units (cents) plus a currency. All of Cleat's
 * money math happens here, in integers. There is no float anywhere on the
 * path from a price to a charge.
 */
final class Money implements JsonSerializable, Stringable
{
    /** Currencies whose minor unit is the major unit. */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    private const SYMBOLS = [
        'USD' => '$', 'CAD' => 'CA$', 'AUD' => 'A$', 'NZD' => 'NZ$', 'EUR' => '€', 'GBP' => '£',
        'JPY' => '¥', 'CHF' => 'CHF ', 'MXN' => 'MX$', 'SEK' => 'SEK ', 'NOK' => 'NOK ', 'DKK' => 'DKK ',
    ];

    public readonly string $currency;

    public function __construct(public readonly int $amount, string $currency)
    {
        $currency = strtoupper($currency);
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid currency code "%s".', $currency));
        }
        $this->currency = $currency;
    }

    public static function of(int $amount, ?string $currency = null): self
    {
        return new self($amount, $currency ?? self::defaultCurrency());
    }

    public static function zero(?string $currency = null): self
    {
        return self::of(0, $currency);
    }

    /**
     * Parse a decimal string such as "45.00", "45.5" or "-3" into minor units
     * without touching a float. Gateways and webhooks hand amounts over as
     * decimal strings; this is the one door they come in through.
     */
    public static function parse(string $decimal, ?string $currency = null): self
    {
        $currency = strtoupper($currency ?? self::defaultCurrency());
        $places = self::minorUnits($currency);
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', trim($decimal), $m) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a decimal amount.', $decimal));
        }
        $fraction = $m[3] ?? '';
        if (strlen($fraction) > $places && rtrim(substr($fraction, $places), '0') !== '') {
            throw new InvalidArgumentException(sprintf('"%s" has more precision than %s allows.', $decimal, $currency));
        }
        $fraction = str_pad(substr($fraction, 0, $places), $places, '0');
        $minor = (int) ($m[2] . $fraction);
        return new self($m[1] === '-' ? -$minor : $minor, $currency);
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self(self::checkedMul($this->amount, $factor), $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->amount, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(Money $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    /** "$1,234.56", "-$5.00", "¥1,200". */
    public function format(): string
    {
        $symbol = self::SYMBOLS[$this->currency] ?? $this->currency . ' ';
        return ($this->amount < 0 ? '-' : '') . $symbol . $this->formatNumber(true);
    }

    /** "1234.56", the plain decimal form gateways expect. */
    public function toDecimal(): string
    {
        return ($this->amount < 0 ? '-' : '') . $this->formatNumber(false);
    }

    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /**
     * round($amount * $numerator / $denominator) in integers, rounding half to
     * even (banker's rounding). Used for proration and percentage fees.
     */
    public static function mulDiv(int $amount, int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Denominator must be positive.');
        }
        $negative = ($amount < 0) !== ($numerator < 0);
        $product = self::checkedMul(abs($amount), abs($numerator));
        $quotient = intdiv($product, $denominator);
        $twiceRemainder = 2 * ($product % $denominator);
        if ($twiceRemainder > $denominator || ($twiceRemainder === $denominator && $quotient % 2 === 1)) {
            $quotient++;
        }
        return $negative ? -$quotient : $quotient;
    }

    /**
     * A percentage of an amount, e.g. an application fee. $percent is the
     * DECIMAL(5,2) string the database stores ("12.50"); it is parsed into
     * hundredths of a percent and applied with banker's rounding, so no float
     * is ever formed. This is the one helper for application_fee_percent.
     */
    public static function percentage(int $amount, string|int $percent): int
    {
        $text = trim((string) $percent);
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/D', $text, $m) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a percentage with at most two decimals.', $text));
        }
        $basisPoints = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($basisPoints > 10000) {
            throw new InvalidArgumentException('A percentage cannot exceed 100.');
        }
        return self::mulDiv($amount, $basisPoints, 10000);
    }

    public static function minorUnits(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    private function formatNumber(bool $group): string
    {
        $places = self::minorUnits($this->currency);
        $abs = abs($this->amount);
        $divisor = 10 ** $places;
        $major = (string) intdiv($abs, $divisor);
        if ($group) {
            $major = strrev(implode(',', str_split(strrev($major), 3)));
        }
        if ($places === 0) {
            return $major;
        }
        return $major . '.' . str_pad((string) ($abs % $divisor), $places, '0', STR_PAD_LEFT);
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException(sprintf('Currency mismatch: %s vs %s.', $this->currency, $other->currency));
        }
    }

    private static function checkedMul(int $a, int $b): int
    {
        if ($a !== 0 && abs($b) > intdiv(PHP_INT_MAX, abs($a))) {
            throw new OverflowException('Amount overflows a 64-bit integer.');
        }
        return $a * $b;
    }

    private static function defaultCurrency(): string
    {
        return Cleat::isConfigured() ? (string) Cleat::config('currency') : 'USD';
    }
}
