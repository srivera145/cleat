<?php

declare(strict_types=1);

namespace Cleat\Events;

use Cleat\Charge;
use Cleat\Invoice;

/** A charge was declined or errored. Always fired, whether or not dunning is enabled. $attemptNumber counts every charge attempt recorded on the invoice; $willRetry is true when Cleat has scheduled an automatic retry. */
final class PaymentFailed
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly Charge $charge,
        public readonly int $attemptNumber,
        public readonly bool $willRetry,
    ) {
    }
}
