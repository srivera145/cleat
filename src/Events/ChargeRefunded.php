<?php

declare(strict_types=1);

namespace Cleat\Events;

use Cleat\Charge;
use Cleat\Refund;

/** A refund or void against a charge succeeded. Check $refund->method for which one happened. */
final class ChargeRefunded
{
    public function __construct(
        public readonly Charge $charge,
        public readonly Refund $refund,
    ) {
    }
}
