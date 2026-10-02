<?php

declare(strict_types=1);

namespace Cleat\Enums;

/** Values match cleat_connected_accounts.status. */
enum ConnectedAccountStatus: string
{
    case Pending = 'pending';
    case Onboarding = 'onboarding';
    case Active = 'active';
    case Restricted = 'restricted';
    case Disabled = 'disabled';
}
