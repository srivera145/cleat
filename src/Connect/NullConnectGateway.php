<?php

declare(strict_types=1);

namespace Cleat\Connect;

use Cleat\Exceptions\ConnectNotConfiguredException;
use Cleat\Gateway\GatewayResult;

/** The Connect driver when connect.driver is null. Every method refuses. */
final class NullConnectGateway implements ConnectGatewayInterface
{
    public function createAccount(ConnectedAccount $account): GatewayResult
    {
        throw self::notConfigured(__FUNCTION__);
    }

    public function onboardingUrl(ConnectedAccount $account, string $returnUrl): string
    {
        throw self::notConfigured(__FUNCTION__);
    }

    public function refreshAccount(ConnectedAccount $account): GatewayResult
    {
        throw self::notConfigured(__FUNCTION__);
    }

    public function chargeOnBehalfOf(ConnectedAccount $account, array $paymentSource, int $amountCents, int $applicationFeeCents, string $idempotencyKey): GatewayResult
    {
        throw self::notConfigured(__FUNCTION__);
    }

    public function transfer(ConnectedAccount $account, int $amountCents, ?int $chargeId, string $idempotencyKey): GatewayResult
    {
        throw self::notConfigured(__FUNCTION__);
    }

    public function payout(ConnectedAccount $account, int $amountCents, string $method, string $idempotencyKey): GatewayResult
    {
        throw self::notConfigured(__FUNCTION__);
    }

    public function balance(ConnectedAccount $account): array
    {
        throw self::notConfigured(__FUNCTION__);
    }

    private static function notConfigured(string $method): ConnectNotConfiguredException
    {
        return new ConnectNotConfiguredException(sprintf(
            'Cleat Connect is not configured, so %s() is unavailable. Set connect.driver once a Connect driver '
            . '(e.g. Finix) is installed. Authorize.net cannot split funds or pay out, so Connect never runs through it.',
            $method,
        ));
    }
}
