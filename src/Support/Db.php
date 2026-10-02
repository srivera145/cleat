<?php

declare(strict_types=1);

namespace Cleat\Support;

use BackedEnum;
use Cleat\Cleat;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Thin PDO helper. Prepared statements only; table and column names passed to
 * insert()/update() are always Cleat's own constants, never request input.
 */
final class Db
{
    private static int $savepoint = 0;

    public static function pdo(): PDO
    {
        return Cleat::pdo();
    }

    /** @param array<int|string, mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_map([self::class, 'param'], $params));
        return $stmt;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<int|string, mixed> $params */
    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<string, mixed> $data */
    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        );
        self::run($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where column => value, ANDed
     * @return int affected rows
     */
    public static function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(static fn (string $c) => "$c = ?", array_keys($data)));
        $cond = implode(' AND ', array_map(static fn (string $c) => "$c = ?", array_keys($where)));
        return self::run(
            sprintf('UPDATE %s SET %s WHERE %s', $table, $set, $cond),
            [...array_values($data), ...array_values($where)],
        )->rowCount();
    }

    /**
     * Run $fn in a transaction. Nested calls (or a host transaction that is
     * already open) become savepoints, so an inner failure rolls back only the
     * inner work and still propagates.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $events = Cleat::events();
        if ($pdo->inTransaction()) {
            $name = 'cleat_sp_' . (++self::$savepoint);
            $queued = $events->deferredCount();
            $pdo->exec("SAVEPOINT $name");
            try {
                $result = $fn();
                $pdo->exec("RELEASE SAVEPOINT $name");
                return $result;
            } catch (Throwable $e) {
                $pdo->exec("ROLLBACK TO SAVEPOINT $name");
                $events->discardDeferred($queued);
                throw $e;
            } finally {
                self::$savepoint--;
            }
        }

        $pdo->beginTransaction();
        $events->beginDeferring();
        try {
            $result = $fn();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $events->discardDeferred();
            $events->flushDeferred();
            throw $e;
        }
        $events->flushDeferred();
        return $result;
    }

    /** True while any transaction (Cleat's or the host's) is open on the connection. */
    public static function inTransaction(): bool
    {
        return self::pdo()->inTransaction();
    }

    /** UTC DATETIME string for storage. */
    public static function ts(DateTimeInterface $at): string
    {
        return DateTimeImmutable::createFromInterface($at)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /** Parse a stored DATETIME (UTC) back into an immutable UTC date. */
    public static function date(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }

    public static function isDuplicateKey(PDOException $e): bool
    {
        return $e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    /**
     * A MySQL/MariaDB named lock. Returns false if it could not be taken within
     * $timeoutSeconds (0 = do not wait).
     */
    public static function lock(string $name, int $timeoutSeconds = 0): bool
    {
        return (int) self::value('SELECT GET_LOCK(?, ?)', [self::lockName($name), $timeoutSeconds]) === 1;
    }

    public static function unlock(string $name): void
    {
        self::value('SELECT RELEASE_LOCK(?)', [self::lockName($name)]);
    }

    private static function lockName(string $name): string
    {
        // GET_LOCK names are capped at 64 characters.
        return strlen($name) <= 64 ? $name : substr($name, 0, 23) . sha1($name);
    }

    private static function param(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => self::ts($value),
            $value instanceof BackedEnum => $value->value,
            is_bool($value) => $value ? 1 : 0,
            is_array($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            default => $value,
        };
    }
}
