<?php

declare(strict_types=1);

namespace Cleat\Gateway;

use Cleat\Enums\GatewayStatus;

/**
 * The normalized outcome of one gateway call.
 *
 * For transactions, $responseCode is Authorize.net's transactionResponse.responseCode
 * (1 approved, 2 declined, 3 error, 4 held for review) and $reasonCode is the
 * first errors[].errorCode, or the message code when there is no error.
 * $data carries call-specific extras: profile ids, card brand and last four,
 * masked account number.
 *
 * $raw is the decoded gateway response. Responses never contain secrets or
 * full card numbers; requests, which do contain credentials, are never kept.
 */
final class GatewayResult
{
    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly bool $success,
        public readonly GatewayStatus $status,
        public readonly ?string $transactionId = null,
        public readonly ?int $responseCode = null,
        public readonly ?string $reasonCode = null,
        public readonly ?string $reasonText = null,
        public readonly array $raw = [],
        public readonly array $data = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function ok(array $data = [], array $raw = []): self
    {
        return new self(true, GatewayStatus::Ok, null, null, null, null, $raw, $data);
    }

    public static function error(string $reasonCode, string $reasonText, array $raw = []): self
    {
        return new self(false, GatewayStatus::Error, null, null, $reasonCode, $reasonText, $raw);
    }

    /**
     * The request may or may not have reached the gateway. Cleat records the
     * charge as failed with reason 'timeout' and never retries it in the same
     * run; a later authcapture webhook reconciles it if money did move.
     */
    public static function timeout(): self
    {
        return new self(false, GatewayStatus::Error, null, null, 'timeout', 'timeout');
    }

    public function isApproved(): bool
    {
        return $this->status === GatewayStatus::Approved;
    }

    public function isDeclined(): bool
    {
        return $this->status === GatewayStatus::Declined;
    }

    public function isHeld(): bool
    {
        return $this->status === GatewayStatus::Held;
    }

    public function isError(): bool
    {
        return $this->status === GatewayStatus::Error;
    }

    public function isTimeout(): bool
    {
        return $this->reasonCode === 'timeout';
    }

    /**
     * The request may have reached the gateway and we never saw the answer
     * (timeout, dropped connection, unreadable response). The charge is
     * recorded as failed, but it blocks further attempts until
     * Invoice::reconcile() has asked the gateway what really happened.
     */
    public function outcomeUnknown(): bool
    {
        return self::isUnknownOutcome($this->reasonCode);
    }

    /** @param bool $includeVerified also match codes already checked against the gateway ("timeout_verified") */
    public static function isUnknownOutcome(?string $reasonCode, bool $includeVerified = false): bool
    {
        if ($reasonCode === null) {
            return false;
        }
        if ($includeVerified && str_ends_with($reasonCode, '_verified')) {
            $reasonCode = substr($reasonCode, 0, -9);
        }
        return in_array($reasonCode, ['timeout', 'network', 'gateway_exception', 'invalid_response'], true)
            || str_starts_with($reasonCode, 'http_');
    }

    /** SQL fragment matching unresolved unknown-outcome charges (no params). */
    public static function unknownOutcomeSql(string $alias = ''): string
    {
        $p = $alias === '' ? '' : $alias . '.';
        return "({$p}status = 'failed' AND {$p}gateway_transaction_id IS NULL AND "
            . "({$p}reason_code IN ('timeout', 'network', 'gateway_exception', 'invalid_response') OR {$p}reason_code LIKE 'http\\_%'))";
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
