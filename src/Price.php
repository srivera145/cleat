<?php

declare(strict_types=1);

namespace Cleat;

use Cleat\Enums\BillingInterval;
use Cleat\Enums\BillingType;
use Cleat\Exceptions\NotFoundException;
use Cleat\Support\Db;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;

/**
 * A price for a product. The amount, currency and interval are immutable:
 * to change what something costs, create a new price and deactivate the old
 * one. Existing subscriptions keep the price they were sold.
 */
final class Price
{
    public function __construct(
        public readonly int $id,
        public readonly int $product_id,
        public readonly string $lookup_key,
        public string $nickname,
        public readonly int $amount,
        public readonly string $currency,
        public readonly BillingType $billing_type,
        public readonly ?BillingInterval $billing_interval,
        public readonly int $interval_count,
        public readonly int $trial_days,
        public bool $is_active,
        public readonly DateTimeImmutable $created_at,
    ) {
    }

    /**
     * @param array{
     *     lookup_key: string, amount: int, billing_type?: string|BillingType,
     *     billing_interval?: string|BillingInterval|null, interval_count?: int,
     *     trial_days?: int, currency?: string, nickname?: string, is_active?: bool
     * } $attributes
     */
    public static function create(Product|int $product, array $attributes): self
    {
        $productId = $product instanceof Product ? $product->id : $product;
        $lookupKey = trim((string) ($attributes['lookup_key'] ?? ''));
        if ($lookupKey === '' || strlen($lookupKey) > 191 || preg_match('/^[A-Za-z0-9._:-]+$/D', $lookupKey) !== 1) {
            throw new InvalidArgumentException('lookup_key is required: letters, digits, dot, dash, underscore or colon, up to 191 characters.');
        }
        if (!isset($attributes['amount']) || !is_int($attributes['amount']) || $attributes['amount'] < 0) {
            throw new InvalidArgumentException('amount must be a non-negative integer number of cents.');
        }
        $type = $attributes['billing_type'] ?? BillingType::OneTime;
        $type = $type instanceof BillingType ? $type : BillingType::from((string) $type);
        $interval = $attributes['billing_interval'] ?? null;
        $interval = $interval === null || $interval instanceof BillingInterval ? $interval : BillingInterval::from((string) $interval);
        $count = (int) ($attributes['interval_count'] ?? 1);
        $trial = (int) ($attributes['trial_days'] ?? 0);

        if ($type === BillingType::Recurring && $interval === null) {
            throw new InvalidArgumentException('A recurring price needs a billing_interval.');
        }
        if ($type === BillingType::OneTime && ($interval !== null || $trial > 0)) {
            throw new InvalidArgumentException('A one-time price cannot have a billing_interval or trial_days.');
        }
        if ($count < 1 || $count > 365 || $trial < 0 || $trial > 730) {
            throw new InvalidArgumentException('interval_count must be 1-365 and trial_days 0-730.');
        }

        try {
            $id = Db::insert('cleat_prices', [
                'product_id' => $productId,
                'lookup_key' => $lookupKey,
                'nickname' => (string) ($attributes['nickname'] ?? ''),
                'amount' => $attributes['amount'],
                'currency' => strtoupper((string) ($attributes['currency'] ?? Cleat::config('currency'))),
                'billing_type' => $type,
                'billing_interval' => $interval,
                'interval_count' => $count,
                'trial_days' => $trial,
                'is_active' => $attributes['is_active'] ?? true,
                'created_at' => Cleat::now(),
            ]);
        } catch (PDOException $e) {
            if (Db::isDuplicateKey($e)) {
                throw new InvalidArgumentException(sprintf('A price with lookup_key "%s" already exists.', $lookupKey), 0, $e);
            }
            throw $e;
        }
        return self::findOrFail($id);
    }

    public static function find(int $id): ?self
    {
        $row = Db::one('SELECT * FROM cleat_prices WHERE id = ?', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findOrFail(int $id): self
    {
        return self::find($id) ?? throw new NotFoundException("Price $id not found.");
    }

    public static function findByLookupKey(string $lookupKey): ?self
    {
        $row = Db::one('SELECT * FROM cleat_prices WHERE lookup_key = ?', [$lookupKey]);
        return $row === null ? null : self::fromRow($row);
    }

    /** A price by lookup key (string) or id (int). */
    public static function resolve(string|int|self $price): self
    {
        if ($price instanceof self) {
            return $price;
        }
        $found = is_int($price) ? self::find($price) : self::findByLookupKey($price);
        return $found ?? throw new NotFoundException(sprintf('Price "%s" not found.', (string) $price));
    }

    public function product(): Product
    {
        return Product::findOrFail($this->product_id);
    }

    public function isRecurring(): bool
    {
        return $this->billing_type === BillingType::Recurring;
    }

    public function money(int $quantity = 1): Money
    {
        return Money::of($this->amount, $this->currency)->multiply($quantity);
    }

    /** "month", "3 months", "week". Null for one-time prices. */
    public function intervalLabel(): ?string
    {
        return $this->billing_interval?->label($this->interval_count);
    }

    /** "$29.00 / month", "$75.00 every 3 months", or "$29.00" for one-time. */
    public function display(int $quantity = 1): string
    {
        $amount = $this->money($quantity)->format();
        if (!$this->isRecurring()) {
            return $amount;
        }
        return $this->interval_count === 1
            ? $amount . ' / ' . $this->billing_interval->value
            : $amount . ' every ' . $this->intervalLabel();
    }

    /** "7-day free trial, then $29.00 / month". Null when there is no trial. */
    public function trialText(int $quantity = 1, ?int $trialDays = null): ?string
    {
        $days = $trialDays ?? $this->trial_days;
        return $days > 0 ? sprintf('%d-day free trial, then %s', $days, $this->display($quantity)) : null;
    }

    /** Line item text: "Premium (Monthly)" or just the product name. */
    public function label(): string
    {
        $name = $this->product()->name;
        return $this->nickname !== '' ? sprintf('%s (%s)', $name, $this->nickname) : $name;
    }

    public function deactivate(): self
    {
        Db::update('cleat_prices', ['is_active' => false], ['id' => $this->id]);
        $this->is_active = false;
        return $this;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['product_id'],
            (string) $row['lookup_key'],
            (string) $row['nickname'],
            (int) $row['amount'],
            (string) $row['currency'],
            BillingType::from((string) $row['billing_type']),
            $row['billing_interval'] !== null ? BillingInterval::from((string) $row['billing_interval']) : null,
            (int) $row['interval_count'],
            (int) $row['trial_days'],
            (bool) $row['is_active'],
            Db::date($row['created_at']),
        );
    }
}
