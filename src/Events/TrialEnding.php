<?php

declare(strict_types=1);

namespace Cleat\Events;

use Cleat\Subscription;

/**
 * A trial ends within trial_ending_days and will convert to a paid period.
 * Fired once per subscription by the Runner.
 */
final class TrialEnding
{
    public function __construct(
        public readonly Subscription $subscription,
    ) {
    }
}
