<?php

declare(strict_types=1);

namespace Cleat;

/**
 * Public tokens for URLs: 64 hex characters from random_bytes. URLs carry a
 * token, never a sequential id.
 */
final class Token
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isValid(mixed $token): bool
    {
        return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }
}
