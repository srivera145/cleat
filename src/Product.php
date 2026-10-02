<?php

declare(strict_types=1);

namespace Cleat;

use Cleat\Exceptions\NotFoundException;
use Cleat\Support\Db;
use DateTimeImmutable;
use InvalidArgumentException;

final class Product
{
    public function __construct(
        public readonly int $id,
        public string $name,
        public ?string $description,
        public bool $is_active,
        public readonly DateTimeImmutable $created_at,
        public DateTimeImmutable $updated_at,
    ) {
    }

    /** @param array{name: string, description?: ?string, is_active?: bool} $attributes */
    public static function create(array $attributes): self
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('A product needs a name.');
        }
        $now = Cleat::now();
        return self::findOrFail(Db::insert('cleat_products', [
            'name' => $name,
            'description' => $attributes['description'] ?? null,
            'is_active' => $attributes['is_active'] ?? true,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    public static function find(int $id): ?self
    {
        $row = Db::one('SELECT * FROM cleat_products WHERE id = ?', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findOrFail(int $id): self
    {
        return self::find($id) ?? throw new NotFoundException("Product $id not found.");
    }

    /** @return list<Price> */
    public function prices(): array
    {
        return array_map(
            [Price::class, 'fromRow'],
            Db::all('SELECT * FROM cleat_prices WHERE product_id = ? ORDER BY id', [$this->id]),
        );
    }

    /**
     * @param array<string, mixed> $attributes see Price::create()
     */
    public function addPrice(array $attributes): Price
    {
        return Price::create($this, $attributes);
    }

    public function deactivate(): self
    {
        Db::update('cleat_products', ['is_active' => false, 'updated_at' => Cleat::now()], ['id' => $this->id]);
        $this->is_active = false;
        return $this;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            $row['description'] !== null ? (string) $row['description'] : null,
            (bool) $row['is_active'],
            Db::date($row['created_at']),
            Db::date($row['updated_at']),
        );
    }
}
