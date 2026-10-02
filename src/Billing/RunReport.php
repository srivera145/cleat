<?php

declare(strict_types=1);

namespace Cleat\Billing;

/** What one Runner::run() did. */
final class RunReport
{
    public bool $skipped = false;
    public int $trialsConverted = 0;
    public int $renewals = 0;
    public int $paymentsSucceeded = 0;
    public int $paymentsFailed = 0;
    public int $cancellations = 0;
    public int $retries = 0;
    public int $linksDeactivated = 0;
    public int $rateLimitRowsPruned = 0;
    /** @var list<string> */
    public array $errors = [];

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
