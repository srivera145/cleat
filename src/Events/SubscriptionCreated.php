<?php

declare(strict_types=1);

namespace Cleat\Events;

use Cleat\Subscription;

/** A subscription was created. Its status may be trialing, active or incomplete. */
final class SubscriptionCreated
{
    public function __construct(
        public readonly Subscription $subscription,
    ) {
    }
}
