<?php

declare(strict_types=1);

namespace Cleat\Tests\Feature;

use Cleat\Cleat;
use Cleat\Connect\ConnectedAccount;
use Cleat\Connect\NullConnectGateway;
use Cleat\Enums\ConnectedAccountStatus;
use Cleat\Exceptions\CleatException;
use Cleat\Exceptions\ConnectNotConfiguredException;
use Cleat\PaymentLink;
use Cleat\Tests\Support\TestCase;

/** Self-check 14. */
final class ConnectTest extends TestCase
{
    public function test_subscription_on_behalf_of_a_connected_account_throws(): void
    {
        $this->monthlyPrice();
        $this->expectException(ConnectNotConfiguredException::class);
        $this->expectExceptionMessage('connect.driver is null');
        $this->customer()->newSubscription('premium-monthly')->onBehalfOf(7, '10.00')->create();
    }

    public function test_payment_link_with_connected_account_throws(): void
    {
        $this->oneTimePrice();
        $this->expectException(ConnectNotConfiguredException::class);
        PaymentLink::create('setup-fee', ['connected_account_id' => 7]);
    }

    public function test_invoice_and_charge_with_connected_account_throw(): void
    {
        $this->oneTimePrice();
        $customer = $this->customer();
        try {
            $customer->newInvoice()->addItem('Work', 1000)->onBehalfOf(7);
            $this->fail('newInvoice()->onBehalfOf() should throw');
        } catch (ConnectNotConfiguredException) {
        }
        try {
            $customer->charge('setup-fee', 1, ['connected_account_id' => 7]);
            $this->fail('charge() should throw');
        } catch (ConnectNotConfiguredException) {
        }
        $this->assertSame(0, $this->rows('cleat_invoices'), 'nothing was created');
        $this->assertSame(0, $this->gateway->chargeCount(), 'nothing was charged to the platform instead');
    }

    public function test_null_connect_gateway_refuses_every_method(): void
    {
        $connect = Cleat::connect();
        $this->assertInstanceOf(NullConnectGateway::class, $connect);
        $account = ConnectedAccount::create(['owner_type' => 'organization', 'owner_id' => 42, 'gateway' => 'finix']);

        $calls = [
            fn () => $connect->createAccount($account),
            fn () => $connect->onboardingUrl($account, 'https://example.com/back'),
            fn () => $connect->refreshAccount($account),
            fn () => $connect->chargeOnBehalfOf($account, [], 1000, 100, 'k'),
            fn () => $connect->transfer($account, 1000, null, 'k'),
            fn () => $connect->payout($account, 1000, 'ach', 'k'),
            fn () => $connect->balance($account),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('expected ConnectNotConfiguredException');
            } catch (ConnectNotConfiguredException $e) {
                $this->assertStringContainsString('Cleat Connect is not configured', $e->getMessage());
            }
        }
    }

    public function test_connected_account_model_reads_and_writes_without_gateway_calls(): void
    {
        $account = ConnectedAccount::create([
            'owner_type' => 'organization', 'owner_id' => 42, 'gateway' => 'finix',
            'business_name' => 'Northwind', 'email' => 'ops@northwind.test', 'capabilities' => ['card_payments' => 'pending'],
        ]);
        $this->assertSame(ConnectedAccountStatus::Pending, $account->status);
        $this->assertSame('US', $account->country);
        $updated = $account->update(['status' => 'active', 'charges_enabled' => true, 'gateway_account_id' => 'MU123']);
        $this->assertSame(ConnectedAccountStatus::Active, $updated->status);
        $this->assertTrue($updated->charges_enabled);
        $this->assertSame(['card_payments' => 'pending'], $updated->capabilities);
        $this->assertCount(1, ConnectedAccount::forOwner('organization', 42));
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_configuring_an_unknown_connect_driver_is_refused(): void
    {
        $this->expectException(CleatException::class);
        $this->reconfigure(['connect' => ['driver' => 'finix']]);
    }
}
