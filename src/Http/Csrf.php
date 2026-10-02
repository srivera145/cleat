<?php

declare(strict_types=1);

namespace Cleat\Http;

use Cleat\Cleat;
use DateTimeImmutable;
use SensitiveParameter;

/**
 * Stateless CSRF tokens: "{issued-at}.{HMAC-SHA256}" over the issue time, the
 * page's public link token and the host's session id, keyed with app_key.
 * Cleat keeps no session of its own; the host passes its session id in.
 */
final class Csrf
{
    public function __construct(
        #[SensitiveParameter] private readonly string $key,
        private readonly int $ttlSeconds = 7200,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(Cleat::appKey());
    }

    public function issue(string $linkToken, string $sessionId, DateTimeImmutable $now): string
    {
        $issued = (string) $now->getTimestamp();
        return $issued . '.' . $this->mac($issued, $linkToken, $sessionId);
    }

    public function verify(mixed $token, string $linkToken, string $sessionId, DateTimeImmutable $now): bool
    {
        if (!is_string($token) || preg_match('/^(\d{1,12})\.([a-f0-9]{64})$/D', $token, $m) !== 1) {
            return false;
        }
        $age = $now->getTimestamp() - (int) $m[1];
        if ($age < -60 || $age > $this->ttlSeconds) {
            return false;
        }
        return hash_equals($this->mac($m[1], $linkToken, $sessionId), $m[2]);
    }

    private function mac(string $issued, string $linkToken, string $sessionId): string
    {
        return hash_hmac('sha256', implode('|', ['cleat-csrf', 'v1', $issued, $linkToken, $sessionId]), $this->key);
    }
}
