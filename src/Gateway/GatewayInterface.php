<?php

declare(strict_types=1);

namespace Cleat\Gateway;

/**
 * Moves money and stores card tokens. Nothing else: every piece of billing
 * state lives in Cleat's own tables, and Cleat's scheduler decides when to
 * charge. Gateways never schedule anything (no ARB).
 *
 * Amounts are integer cents. Card data only ever arrives as Accept.js opaque
 * data: ['dataDescriptor' => ..., 'dataValue' => ...].
 */
interface GatewayInterface
{
    /** data: customer_profile_id */
    public function createCustomerProfile(string $merchantCustomerId, string $email, ?string $description = null): GatewayResult;

    /**
     * Attach a tokenized card to a customer profile and make it the default.
     *
     * @param array{dataDescriptor: string, dataValue: string} $opaqueData
     * data: payment_profile_id, card_brand, card_last_four, card_exp (YYYY-MM),
     * and original_network_transaction_id when saving the card already made a
     * stored-credential transaction (a live validation), else null
     */
    public function createPaymentProfile(string $profileId, array $opaqueData): GatewayResult;

    public function deletePaymentProfile(string $profileId, string $paymentProfileId): GatewayResult;

    /**
     * Charge a saved card. $storedCredential says who started the charge and
     * why (card-on-file indicators); Cleat passes it on every charge. Null
     * sends no indicators. The result's networkTransactionId is the network's
     * id for the charge, when the gateway returns one.
     */
    public function chargeProfile(
        string $profileId,
        string $paymentProfileId,
        int $amountCents,
        string $invoiceNumber,
        string $idempotencyKey,
        ?StoredCredential $storedCredential = null,
    ): GatewayResult;

    /**
     * Charge a single-use Accept.js token without storing the card. Not a
     * stored-credential transaction, so it carries no card-on-file indicators.
     *
     * @param array{dataDescriptor: string, dataValue: string} $opaqueData
     */
    public function chargeToken(
        array $opaqueData,
        int $amountCents,
        string $invoiceNumber,
        string $idempotencyKey,
        ?string $email = null,
        ?string $ip = null,
    ): GatewayResult;

    /** Refund a settled transaction. Authorize.net needs the card's last four digits. */
    public function refund(string $transactionId, int $amountCents, string $cardLastFour, string $idempotencyKey): GatewayResult;

    /** Void an unsettled transaction (always the full amount). */
    public function void(string $transactionId, string $idempotencyKey): GatewayResult;

    /** data: status, amount (cents), card_last_four, invoice_number, ref_transaction_id */
    public function getTransaction(string $transactionId): GatewayResult;

    /**
     * Look for a recent transaction by invoice number and amount. Used to
     * settle a charge whose response never arrived (timeout) before anyone is
     * allowed to try again.
     *
     * success=false means the gateway could not be asked (the caller must
     * not assume anything). On success, data: transaction_id (null when
     * nothing matched) and status: 'approved' | 'held' | 'not_captured'.
     */
    public function findTransaction(string $invoiceNumber, int $amountCents, \DateTimeImmutable $since): GatewayResult;
}
