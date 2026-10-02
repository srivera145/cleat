<?php

declare(strict_types=1);

namespace Cleat\Tests\Unit;

use Cleat\Cleat;
use Cleat\Exceptions\CleatException;
use Cleat\Http\Csrf;
use Cleat\Support\Log;
use Cleat\Support\OpaqueData;
use Cleat\Token;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class SecurityPrimitivesTest extends TestCase
{
    protected function tearDown(): void
    {
        Cleat::reset();
    }

    public function test_tokens_are_64_hex_chars_and_unique(): void
    {
        $tokens = array_map(static fn () => Token::generate(), range(1, 200));
        foreach ($tokens as $t) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $t);
            $this->assertTrue(Token::isValid($t));
        }
        $this->assertCount(200, array_unique($tokens));
        $this->assertFalse(Token::isValid('123'));
        $this->assertFalse(Token::isValid(str_repeat('G', 64)));
        $this->assertFalse(Token::isValid(str_repeat('a', 64) . "\n"));
    }

    public function test_csrf_binds_link_session_and_time(): void
    {
        $csrf = new Csrf(str_repeat('k', 40));
        $now = new DateTimeImmutable('2026-01-01 00:00:00');
        $token = $csrf->issue('link-a', 'session-1', $now);

        $this->assertTrue($csrf->verify($token, 'link-a', 'session-1', $now->modify('+1 hour')));
        $this->assertFalse($csrf->verify($token, 'link-b', 'session-1', $now), 'other link');
        $this->assertFalse($csrf->verify($token, 'link-a', 'session-2', $now), 'other session');
        $this->assertFalse($csrf->verify($token, 'link-a', 'session-1', $now->modify('+3 hours')), 'expired');
        $this->assertFalse((new Csrf(str_repeat('x', 40)))->verify($token, 'link-a', 'session-1', $now), 'other key');
        $this->assertFalse($csrf->verify(null, 'link-a', 'session-1', $now));
        $this->assertFalse($csrf->verify([], 'link-a', 'session-1', $now));
        [$issued, $mac] = explode('.', $token);
        $this->assertFalse($csrf->verify(($issued + 100) . '.' . $mac, 'link-a', 'session-1', $now->modify('+200 seconds')), 'issue time is covered by the MAC');
    }

    public function test_opaque_data_guard(): void
    {
        $this->assertSame(['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => 'eyJ...'], OpaqueData::from(['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => 'eyJ...', 'extra' => 'dropped']));
        $this->assertTrue(OpaqueData::looksLikePan('4111111111111111'));
        $this->assertTrue(OpaqueData::looksLikePan('3782 822463 10005'));
        $this->assertFalse(OpaqueData::looksLikePan('4111111111111112'), 'fails Luhn');
        $this->expectException(InvalidArgumentException::class);
        OpaqueData::from(['dataDescriptor' => 'x']);
    }

    public function test_log_redacts_sensitive_keys(): void
    {
        $clean = Log::redact([
            'transaction_key' => 'abc', 'signatureKey' => 'def', 'dataValue' => 'ghi', 'opaqueData' => ['x' => 1],
            'nested' => ['cardNumber' => '4111', 'ok' => 'visible'], 'invoice_id' => 5,
        ]);
        $this->assertSame('[redacted]', $clean['transaction_key']);
        $this->assertSame('[redacted]', $clean['signatureKey']);
        $this->assertSame('[redacted]', $clean['dataValue']);
        $this->assertSame('[redacted]', $clean['opaqueData']);
        $this->assertSame('[redacted]', $clean['nested']['cardNumber']);
        $this->assertSame('visible', $clean['nested']['ok']);
        $this->assertSame(5, $clean['invoice_id']);
    }

    public function test_config_refuses_to_boot_without_app_key_outside_fake_mode(): void
    {
        $pdo = $this->createStub(PDO::class);
        $live = ['gateway' => 'authorizenet', 'authorizenet' => ['login_id' => 'l', 'transaction_key' => 't', 'client_key' => 'c']];
        try {
            Cleat::configure($pdo, $live);
            $this->fail('booted without app_key');
        } catch (CleatException $e) {
            $this->assertStringContainsString('app_key', $e->getMessage());
        }
        try {
            Cleat::configure($pdo, $live + ['app_key' => 'too-short']);
            $this->fail('booted with a short app_key');
        } catch (CleatException) {
        }
        Cleat::configure($pdo, $live + ['app_key' => str_repeat('s', 32)]);
        $this->assertTrue(Cleat::isConfigured());

        Cleat::configure($pdo, ['gateway' => 'fake']);
        $this->assertNotSame('', Cleat::appKey(), 'fake mode boots with a fixed dev key');
    }

    public function test_config_validation(): void
    {
        $pdo = $this->createStub(PDO::class);
        foreach ([
            ['gateway' => 'stripe'],
            ['currency' => 'usd'],
            ['base_url' => 'not a url'],
            ['automation' => ['retry_attempts' => [3, 1]]],
            ['automation' => ['final_action' => 'deleted']],
            ['rate_limits' => ['per_ip' => [10]]],
        ] as $bad) {
            try {
                Cleat::configure($pdo, $bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (CleatException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_urls_carry_tokens_not_ids(): void
    {
        Cleat::configure($this->createStub(PDO::class), ['gateway' => 'fake', 'base_url' => 'https://pay.example.com/', 'routes' => ['pay_invoice' => '/i/{token}', 'checkout' => 'buy/{token}']]);
        $this->assertSame('https://pay.example.com/i/abc', Cleat::url('pay_invoice', 'abc'));
        $this->assertSame('https://pay.example.com/buy/abc', Cleat::url('checkout', 'abc'));
    }
}
