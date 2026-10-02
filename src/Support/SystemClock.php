<?php

declare(strict_types=1);

namespace Cleat\Support;

use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        // Second precision: every stored timestamp is DATETIME(0).
        return new DateTimeImmutable('@' . time(), new DateTimeZone('UTC'));
    }
}
