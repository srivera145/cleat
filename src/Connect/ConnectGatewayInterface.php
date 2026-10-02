<?php

declare(strict_types=1);

namespace Cleat\Connect;

use Cleat\Gateway\GatewayResult;

/**
 * Cleat Connect: connected accounts, split payments and payouts.
 *
 * Authorize.net cannot send money or split funds, so Connect runs on a
 * PayFac-as-a-Service provider through its own driver, never through
 * AuthorizeNetGateway. Only the contract and the tables exist today; the
 * NullConnectGateway is the only implementation.
 */
interface ConnectGatewayInterface
{
    public function createAccount(ConnectedAccount $account): GatewayResult;

    public function onboardingUrl(ConnectedAccount $account, string $returnUrl): string;

    public function refreshAccount(ConnectedAccount $account): GatewayResult;

    /** @param array<string, mixed> $paymentSource */
    public function chargeOnBehalfOf(
        ConnectedAccount $account,
        array $paymentSource,
        int $amountCents,
        int $applicationFeeCents,
        string $idempotencyKey,
    ): GatewayResult;

    public function transfer(ConnectedAccount $account, int $amountCents, ?int $chargeId, string $idempotencyKey): GatewayResult;

    public function payout(ConnectedAccount $account, int $amountCents, string $method, string $idempotencyKey): GatewayResult;

    /** @return array<string, mixed> */
    public function balance(ConnectedAccount $account): array;
}
