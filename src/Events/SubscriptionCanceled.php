<?php

declare(strict_types=1);

namespace Cleat\Events;

use Cleat\Subscription;

/** A subscription reached the canceled status. */
final class SubscriptionCanceled
{
    public function __construct(
        public readonly Subscription $subscription,
    ) {
    }
}
