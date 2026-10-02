<?php

declare(strict_types=1);

namespace Cleat;

use Cleat\Exceptions\NotFoundException;
use Cleat\Support\Db;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A reusable link that sells one price, one-time or recurring, to anyone
 * with the URL. Guests check out without an account.
 *
 *   $link = PaymentLink::create('premium-monthly', ['max_uses' => 100]);
 *   $link->url();   // https://example.com/checkout/{64 hex chars}
 */
final class PaymentLink
{
    private const OPTIONS = [
        'quantity', 'allow_quantity_change', 'max_quantity', 'collect_name', 'collect_phone', 'success_url',
        'is_active', 'expires_at', 'max_uses', 'metadata', 'connected_account_id', 'application_fee_percent',
    ];

    /** Upper bound on quantity when allow_quantity_change is on and max_quantity is not set. */
    public const DEFAULT_MAX_QUANTITY = 99;

    /** @param array<string, mixed>|null $metadata */
    public function __construct(
        public readonly int $id,
        public readonly string $public_token,
        public readonly int $price_id,
        public int $quantity,
        public bool $allow_quantity_change,
        public ?int $max_quantity,
        public bool $collect_name,
        public bool $collect_phone,
        public ?string $success_url,
        public bool $is_active,
        public ?DateTimeImmutable $expires_at,
        public ?int $max_uses,
        public int $use_count,
        public ?array $metadata,
        public readonly ?int $connected_account_id,
        public readonly ?string $application_fee_percent,
        public readonly DateTimeImmutable $created_at,
        public DateTimeImmutable $updated_at,
    ) {
    }

    /**
     * @param array{
     *     quantity?: int, allow_quantity_change?: bool, max_quantity?: ?int, collect_name?: bool,
     *     collect_phone?: bool, success_url?: ?string, is_active?: bool, expires_at?: DateTimeInterface|string|null,
     *     max_uses?: ?int, metadata?: ?array<string, mixed>, connected_account_id?: ?int, application_fee_percent?: ?string
     * } $options
     */
    public static function create(string|int $price, array $options = []): self
    {
        $unknown = array_diff(array_keys($options), self::OPTIONS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown payment link option(s): ' . implode(', ', $unknown));
        }
        $price = Price::resolve($price);
        if (!$price->is_active) {
            throw new InvalidArgumentException(sprintf('Price "%s" is not active.', $price->lookup_key));
        }
        $connectedAccountId = isset($options['connected_account_id']) ? (int) $options['connected_account_id'] : null;
        Cleat::assertConnectAvailable($connectedAccountId);

        $quantity = (int) ($options['quantity'] ?? 1);
        $maxQuantity = isset($options['max_quantity']) ? (int) $options['max_quantity'] : null;
        $maxUses = isset($options['max_uses']) ? (int) $options['max_uses'] : null;
        if ($quantity < 1 || ($maxQuantity !== null && $maxQuantity < $quantity) || ($maxUses !== null && $maxUses < 1)) {
            throw new InvalidArgumentException('quantity must be >= 1, max_quantity >= quantity, and max_uses >= 1.');
        }
        $successUrl = $options['success_url'] ?? null;
        if ($successUrl !== null && !self::isSafeUrl((string) $successUrl)) {
            throw new InvalidArgumentException('success_url must be an absolute http(s) URL.');
        }
        $fee = $options['application_fee_percent'] ?? null;
        if ($fee !== null) {
            Money::percentage(100, (string) $fee); // validates
        }

        $now = Cleat::now();
        $id = Db::insert('cleat_payment_links', [
            'public_token' => Token::generate(),
            'price_id' => $price->id,
            'quantity' => $quantity,
            'allow_quantity_change' => (bool) ($options['allow_quantity_change'] ?? false),
            'max_quantity' => $maxQuantity,
            'collect_name' => (bool) ($options['collect_name'] ?? true),
            'collect_phone' => (bool) ($options['collect_phone'] ?? false),
            'success_url' => $successUrl,
            'is_active' => (bool) ($options['is_active'] ?? true),
            'expires_at' => self::toDate($options['expires_at'] ?? null),
            'max_uses' => $maxUses,
            'metadata' => isset($options['metadata']) ? (array) $options['metadata'] : null,
            'connected_account_id' => $connectedAccountId,
            'application_fee_percent' => $fee !== null ? (string) $fee : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return self::findOrFail($id);
    }

    public static function find(int $id): ?self
    {
        $row = Db::one('SELECT * FROM cleat_payment_links WHERE id = ?', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findOrFail(int $id): self
    {
        return self::find($id) ?? throw new NotFoundException("Payment link $id not found.");
    }

    public static function findByToken(string $token): ?self
    {
        if (!Token::isValid($token)) {
            return null;
        }
        $row = Db::one('SELECT * FROM cleat_payment_links WHERE public_token = ?', [$token]);
        return $row === null ? null : self::fromRow($row);
    }

    public function url(): string
    {
        return Cleat::url('checkout', $this->public_token);
    }

    public function price(): Price
    {
        return Price::findOrFail($this->price_id);
    }

    public function deactivate(): self
    {
        Db::update('cleat_payment_links', ['is_active' => false, 'updated_at' => Cleat::now()], ['id' => $this->id]);
        $this->is_active = false;
        return $this;
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        return $this->expires_at !== null && $this->expires_at <= ($now ?? Cleat::now());
    }

    public function isSoldOut(): bool
    {
        return $this->max_uses !== null && $this->use_count >= $this->max_uses;
    }

    /** @return array{0: int, 1: int} [min, max] quantity a buyer may choose */
    public function quantityBounds(): array
    {
        if (!$this->allow_quantity_change) {
            return [$this->quantity, $this->quantity];
        }
        return [1, $this->max_quantity ?? max(self::DEFAULT_MAX_QUANTITY, $this->quantity)];
    }

    /**
     * @internal Atomically count one use. False means the link sold out
     * between the pre-check and now (a concurrent checkout took the last use).
     */
    public function claimUse(): bool
    {
        $claimed = Db::run(
            'UPDATE cleat_payment_links SET use_count = use_count + 1, updated_at = ? WHERE id = ? AND (max_uses IS NULL OR use_count < max_uses)',
            [Cleat::now(), $this->id],
        )->rowCount() === 1;
        if ($claimed) {
            $this->use_count++;
        }
        return $claimed;
    }

    public function refresh(): self
    {
        return self::findOrFail($this->id);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['public_token'],
            (int) $row['price_id'],
            (int) $row['quantity'],
            (bool) $row['allow_quantity_change'],
            $row['max_quantity'] !== null ? (int) $row['max_quantity'] : null,
            (bool) $row['collect_name'],
            (bool) $row['collect_phone'],
            $row['success_url'] !== null ? (string) $row['success_url'] : null,
            (bool) $row['is_active'],
            Db::date($row['expires_at']),
            $row['max_uses'] !== null ? (int) $row['max_uses'] : null,
            (int) $row['use_count'],
            $row['metadata'] !== null ? json_decode((string) $row['metadata'], true) : null,
            $row['connected_account_id'] !== null ? (int) $row['connected_account_id'] : null,
            $row['application_fee_percent'] !== null ? (string) $row['application_fee_percent'] : null,
            Db::date($row['created_at']),
            Db::date($row['updated_at']),
        );
    }

    private static function isSafeUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && preg_match('#^https?://#i', $url) === 1
            && strlen($url) <= 2048;
    }

    private static function toDate(DateTimeInterface|string|null $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $utc = new DateTimeZone('UTC');
        return (is_string($value) ? new DateTimeImmutable($value, $utc) : DateTimeImmutable::createFromInterface($value))->setTimezone($utc);
    }
}
