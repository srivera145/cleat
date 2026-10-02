<?php

declare(strict_types=1);

namespace Cleat\Exceptions;

/** A connected account was given, or a Connect method called, with no Connect driver configured. */
final class ConnectNotConfiguredException extends CleatException
{
}
