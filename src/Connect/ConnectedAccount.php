<?php

declare(strict_types=1);

namespace Cleat\Connect;

use Cleat\Cleat;
use Cleat\Enums\ConnectedAccountStatus;
use Cleat\Support\Db;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A row in cleat_connected_accounts. Read and write only: no gateway calls
 * until the Connect driver ships.
 */
final class ConnectedAccount
{
    private const WRITABLE = [
        'gateway_account_id', 'status', 'charges_enabled', 'payouts_enabled', 'business_name',
        'email', 'country', 'default_currency', 'capabilities', 'requirements',
    ];

    /**
     * @param array<string, mixed>|null $capabilities
     * @param array<string, mixed>|null $requirements
     */
    public function __construct(
        public readonly int $id,
        public readonly string $owner_type,
        public readonly int $owner_id,
        public readonly string $gateway,
        public ?string $gateway_account_id,
        public ConnectedAccountStatus $status,
        public bool $charges_enabled,
        public bool $payouts_enabled,
        public ?string $business_name,
        public ?string $email,
        public string $country,
        public string $default_currency,
        public ?array $capabilities,
        public ?array $requirements,
        public readonly DateTimeImmutable $created_at,
        public DateTimeImmutable $updated_at,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes owner_type, owner_id, gateway, plus any writable column
     */
    public static function create(array $attributes): self
    {
        foreach (['owner_type', 'owner_id', 'gateway'] as $required) {
            if (!isset($attributes[$required]) || $attributes[$required] === '') {
                throw new InvalidArgumentException("ConnectedAccount::create() needs $required.");
            }
        }
        $now = Cleat::now();
        $row = [
            'owner_type' => (string) $attributes['owner_type'],
            'owner_id' => (int) $attributes['owner_id'],
            'gateway' => (string) $attributes['gateway'],
            'status' => ConnectedAccountStatus::Pending->value,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        foreach (self::WRITABLE as $column) {
            if (array_key_exists($column, $attributes)) {
                $row[$column] = self::normalize($column, $attributes[$column]);
            }
        }
        return self::findOrFail(Db::insert('cleat_connected_accounts', $row));
    }

    public static function find(int $id): ?self
    {
        $row = Db::one('SELECT * FROM cleat_connected_accounts WHERE id = ?', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findOrFail(int $id): self
    {
        return self::find($id) ?? throw new InvalidArgumentException("Connected account $id not found.");
    }

    /** @return list<self> */
    public static function forOwner(string $ownerType, int $ownerId): array
    {
        return array_map(
            [self::class, 'fromRow'],
            Db::all('SELECT * FROM cleat_connected_accounts WHERE owner_type = ? AND owner_id = ? ORDER BY id', [$ownerType, $ownerId]),
        );
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): self
    {
        $row = [];
        foreach ($attributes as $column => $value) {
            if (!in_array($column, self::WRITABLE, true)) {
                throw new InvalidArgumentException("Column $column is not writable on a connected account.");
            }
            $row[$column] = self::normalize($column, $value);
        }
        if ($row !== []) {
            $row['updated_at'] = Cleat::now();
            Db::update('cleat_connected_accounts', $row, ['id' => $this->id]);
        }
        return self::findOrFail($this->id);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['owner_type'],
            (int) $row['owner_id'],
            (string) $row['gateway'],
            $row['gateway_account_id'] !== null ? (string) $row['gateway_account_id'] : null,
            ConnectedAccountStatus::from((string) $row['status']),
            (bool) $row['charges_enabled'],
            (bool) $row['payouts_enabled'],
            $row['business_name'] !== null ? (string) $row['business_name'] : null,
            $row['email'] !== null ? (string) $row['email'] : null,
            (string) $row['country'],
            (string) $row['default_currency'],
            $row['capabilities'] !== null ? json_decode((string) $row['capabilities'], true) : null,
            $row['requirements'] !== null ? json_decode((string) $row['requirements'], true) : null,
            Db::date($row['created_at']),
            Db::date($row['updated_at']),
        );
    }

    private static function normalize(string $column, mixed $value): mixed
    {
        return match ($column) {
            'status' => $value instanceof ConnectedAccountStatus ? $value : ConnectedAccountStatus::from((string) $value),
            'charges_enabled', 'payouts_enabled' => (bool) $value,
            'capabilities', 'requirements' => $value === null ? null : (array) $value,
            default => $value,
        };
    }
}
