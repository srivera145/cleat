<?php

declare(strict_types=1);

namespace Cleat\Enums;

/** Values match cleat_subscriptions.status. */
enum SubscriptionStatus: string
{
    case Incomplete = 'incomplete';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Unpaid = 'unpaid';
}
