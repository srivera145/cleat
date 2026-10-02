<?php

declare(strict_types=1);

namespace Cleat\Support;

use DateTimeImmutable;

interface ClockInterface
{
    /** The current time, always in UTC. */
    public function now(): DateTimeImmutable;
}
