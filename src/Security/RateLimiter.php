<?php

declare(strict_types=1);

namespace Cleat\Security;

use Cleat\Support\Db;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Fixed-window counters in cleat_rate_limits. One row per bucket per
 * window; the increment is a single INSERT ... ON DUPLICATE KEY UPDATE, so
 * concurrent requests count correctly without a lock.
 */
final class RateLimiter
{
    /** Count a hit. Returns true while the bucket is within $max for this window. */
    public function hit(string $bucket, int $max, int $windowSeconds, DateTimeImmutable $now): bool
    {
        $window = self::windowStart($now, $windowSeconds);
        Db::run(
            'INSERT INTO cleat_rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1',
            [self::bucket($bucket), $window],
        );
        return $this->hits($bucket, $windowSeconds, $now) <= $max;
    }

    /** Read-only: has the bucket already reached $max this window? */
    public function tooMany(string $bucket, int $max, int $windowSeconds, DateTimeImmutable $now): bool
    {
        return $this->hits($bucket, $windowSeconds, $now) >= $max;
    }

    public function hits(string $bucket, int $windowSeconds, DateTimeImmutable $now): int
    {
        return (int) Db::value(
            'SELECT hits FROM cleat_rate_limits WHERE bucket = ? AND window_start = ?',
            [self::bucket($bucket), self::windowStart($now, $windowSeconds)],
        );
    }

    /** Seconds until the current window closes. */
    public function retryAfter(int $windowSeconds, DateTimeImmutable $now): int
    {
        return max(1, $windowSeconds - ($now->getTimestamp() % $windowSeconds));
    }

    /** Delete rows whose window started more than $olderThanSeconds ago. */
    public static function prune(DateTimeImmutable $now, int $olderThanSeconds = 86400): int
    {
        return Db::run('DELETE FROM cleat_rate_limits WHERE window_start < ?', [$now->modify("-$olderThanSeconds seconds")])->rowCount();
    }

    private static function windowStart(DateTimeImmutable $now, int $windowSeconds): DateTimeImmutable
    {
        $ts = $now->getTimestamp();
        return new DateTimeImmutable('@' . ($ts - ($ts % $windowSeconds)), new DateTimeZone('UTC'));
    }

    /** Buckets longer than the column are hashed, keeping a readable prefix. */
    private static function bucket(string $bucket): string
    {
        return strlen($bucket) <= 191 ? $bucket : substr($bucket, 0, 120) . ':' . hash('sha256', $bucket);
    }
}
