<?php

declare(strict_types=1);

namespace Cleat\Enums;

/** Values match cleat_charges.source. */
enum ChargeSource: string
{
    case StoredProfile = 'stored_profile';
    case OneTimeToken = 'one_time_token';
}
