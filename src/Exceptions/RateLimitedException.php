<?php

declare(strict_types=1);

namespace Cleat\Exceptions;

final class RateLimitedException extends CleatException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds)
    {
        parent::__construct($message);
    }
}
