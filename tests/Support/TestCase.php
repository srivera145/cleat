<?php

declare(strict_types=1);

namespace Cleat\Tests\Support;

use Cleat\Billing\RunReport;
use Cleat\Billing\Runner;
use Cleat\Cleat;
use Cleat\Customer;
use Cleat\Gateway\FakeGateway;
use Cleat\Mail\ArrayMailer;
use Cleat\Price;
use Cleat\Product;
use Cleat\Support\FrozenClock;
use Cleat\Support\Log;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected const APP_KEY = 'test-app-key-0123456789abcdef0123456789abcdef';
    protected const SIGNATURE_KEY = 'BE1C1A250B948DACCA9879508BFF8B3D498DF9157C22EEF8ABF0E7DF9ECE4FEA87F311B4D6D6864A19FEE21E5138784DD97CAD7FF26CEBAC4DB95F40CE0A1FA4';

    protected PDO $pdo;
    protected FakeGateway $gateway;
    protected ArrayMailer $mailer;
    protected FrozenClock $clock;
    /** @var list<object> */
    protected array $events = [];
    /** @var list<array{level: string, message: string, context: array}> */
    protected array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = TestDatabase::connect();
        TestDatabase::truncate($this->pdo);

        Cleat::reset();
        $this->mailer = new ArrayMailer();
        Cleat::configure($this->pdo, $this->config(), $this->mailer);
        $this->gateway = new FakeGateway();
        Cleat::setGateway($this->gateway);
        $this->clock = new FrozenClock('2026-01-15 12:00:00');
        Cleat::setClock($this->clock);

        $this->events = [];
        Cleat::events()->throwListenerExceptions(true);
        Cleat::events()->listen('*', function (object $event): void {
            $this->events[] = $event;
        });
        $this->logs = [];
        Log::setLogger(function (string $level, string $message, array $context): void {
            $this->logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
        });
    }

    protected function tearDown(): void
    {
        Cleat::reset();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    protected function config(array $overrides = []): array
    {
        return array_replace_recursive([
            'gateway' => 'fake',
            'base_url' => 'https://billing.test',
            'app_key' => self::APP_KEY,
            'brand' => ['name' => 'Acme Tools', 'support_email' => 'help@acme.test'],
            'authorizenet' => ['signature_key' => self::SIGNATURE_KEY, 'login_id' => 'test-login', 'client_key' => 'test-client-key'],
        ], $overrides);
    }

    protected function reconfigure(array $overrides): void
    {
        $gateway = $this->gateway;
        Cleat::configure($this->pdo, $this->config($overrides), $this->mailer);
        Cleat::setGateway($gateway);
    }

    protected function product(string $name = 'Premium'): Product
    {
        return Product::create(['name' => $name, 'description' => 'Everything in the toolbox.']);
    }

    protected function monthlyPrice(string $key = 'premium-monthly', int $amount = 2900, int $trialDays = 0, ?Product $product = null): Price
    {
        return Price::create($product ?? $this->product(), [
            'lookup_key' => $key, 'amount' => $amount, 'billing_type' => 'recurring',
            'billing_interval' => 'month', 'trial_days' => $trialDays, 'nickname' => 'Monthly',
        ]);
    }

    protected function oneTimePrice(string $key = 'setup-fee', int $amount = 5000, ?Product $product = null): Price
    {
        return Price::create($product ?? $this->product('Setup'), ['lookup_key' => $key, 'amount' => $amount]);
    }

    protected function customer(string $email = 'jane@example.com', ?string $card = 'tok_visa'): Customer
    {
        $customer = Customer::create(['email' => $email, 'name' => 'Jane Doe', 'user_id' => random_int(1, PHP_INT_MAX >> 1)]);
        if ($card !== null) {
            $customer->updatePaymentMethod(self::opaque($card));
        }
        return $customer;
    }

    /** @return array{dataDescriptor: string, dataValue: string} */
    protected static function opaque(string $token): array
    {
        return ['dataDescriptor' => FakeGateway::DESCRIPTOR, 'dataValue' => $token];
    }

    protected function at(string $datetime): DateTimeImmutable
    {
        $this->clock->set($datetime);
        return $this->clock->now();
    }

    protected function runAt(string $datetime): RunReport
    {
        return (new Runner())->run($this->at($datetime));
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    protected function eventsOf(string $class): array
    {
        return array_values(array_filter($this->events, static fn (object $e) => $e instanceof $class));
    }

    protected function rows(string $table, string $where = '1=1', array $params = []): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM $table WHERE $where");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Pull the first https link to /pay/... out of an email body. */
    protected static function payLinkIn(string $html): ?string
    {
        return preg_match('#href="(https://billing\.test/pay/[a-f0-9]{64})"#', $html, $m) === 1 ? $m[1] : null;
    }
}
