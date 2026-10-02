<?php

declare(strict_types=1);

namespace Cleat\Events;

use Cleat\Invoice;
use Cleat\Subscription;

/** A subscription moved to past_due, or to unpaid after its last retry failed. Check $subscription->status. */
final class SubscriptionPastDue
{
    public function __construct(
        public readonly Subscription $subscription,
        public readonly Invoice $invoice,
    ) {
    }
}
