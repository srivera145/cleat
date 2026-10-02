<?php

declare(strict_types=1);

namespace Cleat\Exceptions;

/**
 * The invoice already has a charge that is pending (in flight, or left behind
 * by a crash) or held for fraud review. Charging again could take the money
 * twice, so Cleat refuses until that charge settles or is reconciled.
 */
final class ChargeInProgressException extends CleatException
{
}
