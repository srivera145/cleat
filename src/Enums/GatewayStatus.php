<?php

declare(strict_types=1);

namespace Cleat\Enums;

/** Outcome of a gateway call. Ok is a successful non-transaction call (e.g. a CIM profile). */
enum GatewayStatus: string
{
    case Approved = 'approved';
    case Declined = 'declined';
    case Error = 'error';
    case Held = 'held';
    case Ok = 'ok';
}
