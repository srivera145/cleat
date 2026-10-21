<?php

declare(strict_types=1);

namespace Cleat\Gateway;

use InvalidArgumentException;

/**
 * Stored-credential (card-on-file) indicators for one charge of a saved card,
 * in card-network terms. Each gateway turns them into its own fields; see
 * AuthorizeNetGateway::storedCredentialFields().
 *
 *   initiator  'customer': the cardholder takes part (CIT), e.g. the pay page.
 *              'merchant': Cleat charges on its own (MIT), e.g. a renewal.
 *   type       'recurring' for a subscription's fixed schedule, otherwise
 *              'unscheduled'.
 *   isFirst    the customer-initiated charge that starts using the stored
 *              card: the first charge of a newly saved card, or the first
 *              payment of a new subscription.
 *   originalNetworkTransId
 *              merchant-initiated only: the card network's id for the first
 *              stored-credential transaction on this card. Null when it is
 *              not known (cards saved before 0.1.2).
 *   reason     merchant-initiated, unscheduled only: an industry-practice
 *              reason. Cleat uses 'resubmission' for dunning retries.
 *
 * One-time Accept.js tokens that are not saved carry none of this.
 */
final class StoredCredential
{
    public const CUSTOMER = 'customer';
    public const MERCHANT = 'merchant';

    public const RECURRING = 'recurring';
    public const UNSCHEDULED = 'unscheduled';

    /** A retry of a declined charge for goods or services already provided. */
    public const RESUBMISSION = 'resubmission';

    /** The merchant-initiated reasons Authorize.net accepts (merchantInitTransReasonEnum). */
    public const REASONS = [self::RESUBMISSION, 'delayedCharge', 'reauthorization', 'noShow'];

    private function __construct(
        public readonly string $initiator,
        public readonly string $type,
        public readonly bool $isFirst = false,
        public readonly ?string $originalNetworkTransId = null,
        public readonly ?string $reason = null,
    ) {
        if (!in_array($initiator, [self::CUSTOMER, self::MERCHANT], true)) {
            throw new InvalidArgumentException("Unknown stored-credential initiator \"$initiator\".");
        }
        if (!in_array($type, [self::RECURRING, self::UNSCHEDULED], true)) {
            throw new InvalidArgumentException("Unknown stored-credential type \"$type\".");
        }
        if ($isFirst && $initiator !== self::CUSTOMER) {
            throw new InvalidArgumentException('Only a customer-initiated charge can be the first stored-credential charge.');
        }
        if ($initiator === self::CUSTOMER && ($originalNetworkTransId !== null || $reason !== null)) {
            throw new InvalidArgumentException('An original network transaction id and a reason belong to merchant-initiated charges only.');
        }
        if ($originalNetworkTransId !== null && !self::isNetworkTransId($originalNetworkTransId)) {
            throw new InvalidArgumentException('A network transaction id is 1-255 letters, digits or spaces.');
        }
        if ($reason !== null && (!in_array($reason, self::REASONS, true) || $type !== self::UNSCHEDULED)) {
            throw new InvalidArgumentException("A reason (\"$reason\") must be one of " . implode(', ', self::REASONS) . ' on an unscheduled charge.');
        }
    }

    /** The first charge of a newly saved card, or the first payment of a new subscription. */
    public static function firstCustomerInitiated(bool $recurring): self
    {
        return new self(self::CUSTOMER, $recurring ? self::RECURRING : self::UNSCHEDULED, true);
    }

    /** The customer pays with a card they saved earlier. */
    public static function customerInitiated(bool $recurring = false): self
    {
        return new self(self::CUSTOMER, $recurring ? self::RECURRING : self::UNSCHEDULED);
    }

    /** A scheduled subscription charge: a renewal or a trial conversion. */
    public static function recurring(): self
    {
        return new self(self::MERCHANT, self::RECURRING);
    }

    /** A merchant-initiated charge off the subscription schedule (one-off charges, plan swaps). */
    public static function unscheduled(): self
    {
        return new self(self::MERCHANT, self::UNSCHEDULED);
    }

    /** A dunning retry of a declined charge. */
    public static function resubmission(): self
    {
        return new self(self::MERCHANT, self::UNSCHEDULED, false, null, self::RESUBMISSION);
    }

    /** The same indicators, carrying the card's original network transaction id (merchant-initiated only). */
    public function withOriginalNetworkTransId(?string $networkTransactionId): self
    {
        return new self($this->initiator, $this->type, $this->isFirst, $networkTransactionId, $this->reason);
    }

    /** The shape Authorize.net gives network transaction ids (networkTransId: letters, digits, spaces, at most 255). */
    public static function isNetworkTransId(string $value): bool
    {
        return preg_match('/^[0-9A-Za-z ]{1,255}$/D', $value) === 1;
    }

    public function isCustomerInitiated(): bool
    {
        return $this->initiator === self::CUSTOMER;
    }

    public function isMerchantInitiated(): bool
    {
        return $this->initiator === self::MERCHANT;
    }

    public function isRecurring(): bool
    {
        return $this->type === self::RECURRING;
    }

    /** A short label for logs and reports, e.g. "cit first recurring" or "mit unscheduled resubmission". */
    public function label(): string
    {
        return implode(' ', array_filter([
            $this->isCustomerInitiated() ? 'cit' : 'mit',
            $this->isFirst ? 'first' : null,
            $this->type,
            $this->reason,
        ]));
    }
}
