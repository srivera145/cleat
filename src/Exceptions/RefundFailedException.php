<?php

declare(strict_types=1);

namespace Cleat\Exceptions;

use Cleat\Gateway\GatewayResult;
use Cleat\Refund;

/** A refund (and, where it applied, the void fallback) was rejected. The attempt is recorded on $refund. */
final class RefundFailedException extends CleatException
{
    public function __construct(string $message, public readonly Refund $refund, public readonly GatewayResult $result)
    {
        parent::__construct($message);
    }
}
