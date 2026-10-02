<?php

declare(strict_types=1);

namespace Cleat\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** A clock that only moves when told to. Used by Runner::run($now) and by tests. */
final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeInterface|string $now)
    {
        $this->set($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeInterface|string $now): void
    {
        $utc = new DateTimeZone('UTC');
        $value = is_string($now)
            ? new DateTimeImmutable($now, $utc)
            : DateTimeImmutable::createFromInterface($now);
        $this->now = $value->setTimezone($utc);
    }

    /** e.g. advance('+7 days') */
    public function advance(string $modifier): DateTimeImmutable
    {
        $this->now = $this->now->modify($modifier);
        return $this->now;
    }
}
