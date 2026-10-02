<?php

declare(strict_types=1);

namespace Cleat;

use Cleat\Connect\ConnectGatewayInterface;
use Cleat\Connect\NullConnectGateway;
use Cleat\Events\Dispatcher;
use Cleat\Exceptions\CleatException;
use Cleat\Exceptions\ConnectNotConfiguredException;
use Cleat\Gateway\AuthorizeNetGateway;
use Cleat\Gateway\FakeGateway;
use Cleat\Gateway\GatewayInterface;
use Cleat\Mail\MailerInterface;
use Cleat\Mail\NullMailer;
use Cleat\Support\ClockInterface;
use Cleat\Support\Log;
use Cleat\Support\SystemClock;
use DateTimeImmutable;
use PDO;

/**
 * Bootstrap and service locator. Cleat has no container: the host calls
 * configure() once per request (or once per worker) and everything else reads
 * from here.
 */
final class Cleat
{
    public const VERSION = '0.1.0';

    /** Used only when gateway = 'fake' and no app_key is set. Never secret. */
    private const FAKE_MODE_APP_KEY = 'cleat-fake-mode-insecure-app-key-do-not-use-in-production';

    private static ?PDO $pdo = null;
    /** @var array<string, mixed> */
    private static array $config = [];
    private static ?MailerInterface $mailer = null;
    private static ?GatewayInterface $gateway = null;
    private static ?ConnectGatewayInterface $connect = null;
    private static ?Dispatcher $events = null;
    private static ?ClockInterface $clock = null;

    /**
     * @param array<string, mixed> $config Same shape as config/cleat.php. Missing keys take the defaults.
     */
    public static function configure(PDO $pdo, array $config, ?MailerInterface $mailer = null): void
    {
        $merged = self::merge(self::defaults(), $config);
        self::validate($merged);

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        self::$pdo = $pdo;
        self::$config = $merged;
        self::$mailer = $mailer ?? new NullMailer();
        self::$gateway = null;
        self::$connect = null;
        self::$events ??= new Dispatcher();
    }

    /** Clears every static. For tests and long-running workers that reconfigure. */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$config = [];
        self::$mailer = null;
        self::$gateway = null;
        self::$connect = null;
        self::$events = null;
        self::$clock = null;
        Log::setLogger(null);
    }

    public static function isConfigured(): bool
    {
        return self::$pdo !== null;
    }

    public static function pdo(): PDO
    {
        return self::$pdo ?? throw new CleatException('Cleat is not configured. Call Cleat::configure($pdo, $config) first.');
    }

    /**
     * Read a config value with dot notation: Cleat::config('automation.retry_attempts').
     */
    public static function config(?string $key = null, mixed $default = null): mixed
    {
        if (self::$config === []) {
            throw new CleatException('Cleat is not configured. Call Cleat::configure($pdo, $config) first.');
        }
        if ($key === null) {
            return self::$config;
        }
        $value = self::$config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function gateway(): GatewayInterface
    {
        if (self::$gateway === null) {
            self::$gateway = match (self::config('gateway')) {
                'authorizenet' => new AuthorizeNetGateway(
                    (string) self::config('authorizenet.login_id'),
                    (string) self::config('authorizenet.transaction_key'),
                    (bool) self::config('authorizenet.sandbox'),
                ),
                default => new FakeGateway((int) self::config('fake.delay_ms', 0)),
            };
        }
        return self::$gateway;
    }

    /** Swap the gateway, e.g. to hand a test its own FakeGateway. */
    public static function setGateway(GatewayInterface $gateway): void
    {
        self::$gateway = $gateway;
    }

    /**
     * The Connect driver. Until the Connect driver ships this is always the
     * NullConnectGateway, whose every method throws ConnectNotConfiguredException.
     */
    public static function connect(): ConnectGatewayInterface
    {
        return self::$connect ??= new NullConnectGateway();
    }

    public static function setConnect(ConnectGatewayInterface $connect): void
    {
        self::$connect = $connect;
    }

    /**
     * Billing methods that accept a connected account call this first. With no
     * Connect driver configured, a connected account cannot be honoured, and
     * silently charging the platform instead would be worse than failing.
     */
    public static function assertConnectAvailable(?int $connectedAccountId): void
    {
        if ($connectedAccountId === null) {
            return;
        }
        if (self::config('connect.driver') === null || self::connect() instanceof NullConnectGateway) {
            throw new ConnectNotConfiguredException(sprintf(
                'Connected account %d was given, but Cleat Connect is not configured (connect.driver is null). '
                . 'Authorize.net cannot split funds or pay out to connected accounts, so Cleat refuses rather than '
                . 'charging the platform account. Configure a Connect driver, or leave connected_account_id empty.',
                $connectedAccountId,
            ));
        }
    }

    public static function mailer(): MailerInterface
    {
        return self::$mailer ??= new NullMailer();
    }

    public static function setMailer(MailerInterface $mailer): void
    {
        self::$mailer = $mailer;
    }

    public static function events(): Dispatcher
    {
        return self::$events ??= new Dispatcher();
    }

    public static function clock(): ClockInterface
    {
        return self::$clock ??= new SystemClock();
    }

    public static function setClock(?ClockInterface $clock): void
    {
        self::$clock = $clock;
    }

    /** Current time in UTC, from the injectable clock. */
    public static function now(): DateTimeImmutable
    {
        return self::clock()->now();
    }

    /** The CSRF secret. In fake mode with no key set, a fixed (public) dev key. */
    public static function appKey(): string
    {
        $key = (string) self::config('app_key', '');
        if ($key === '' && self::config('gateway') === 'fake') {
            return self::FAKE_MODE_APP_KEY;
        }
        return $key;
    }

    public static function isFake(): bool
    {
        return self::config('gateway') === 'fake';
    }

    public static function isSandbox(): bool
    {
        return (bool) self::config('authorizenet.sandbox', true);
    }

    public static function acceptJsUrl(): string
    {
        return self::isSandbox()
            ? 'https://jstest.authorize.net/v1/Accept.js'
            : 'https://js.authorize.net/v1/Accept.js';
    }

    /**
     * Absolute URL for a named route ('pay_invoice' or 'checkout') with the
     * public token substituted in. Never takes a sequential id.
     */
    public static function url(string $route, string $token): string
    {
        $pattern = self::config('routes.' . $route);
        if (!is_string($pattern) || !str_contains($pattern, '{token}')) {
            throw new CleatException(sprintf('Route "%s" is not configured or has no {token} placeholder.', $route));
        }
        $path = str_replace('{token}', rawurlencode($token), $pattern);
        return rtrim((string) self::config('base_url'), '/') . '/' . ltrim($path, '/');
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = require dirname(__DIR__) . '/config/cleat.php';
        return $defaults;
    }

    /**
     * Recursive merge where associative arrays merge key by key and lists
     * (retry_attempts, the [max, window] rate-limit pairs) replace wholesale.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])
                && !array_is_list($base[$key]) && $base[$key] !== []) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /** @param array<string, mixed> $c */
    private static function validate(array $c): void
    {
        $fail = static fn (string $msg) => throw new CleatException('Invalid Cleat config: ' . $msg);

        if (!in_array($c['gateway'], ['fake', 'authorizenet'], true)) {
            $fail("gateway must be 'fake' or 'authorizenet'.");
        }
        if ($c['gateway'] !== 'fake') {
            if (strlen((string) $c['app_key']) < 32) {
                $fail('app_key must be a secret of at least 32 bytes when the gateway is not fake. '
                    . 'Generate one with: php -r "echo bin2hex(random_bytes(32));"');
            }
            foreach (['login_id', 'transaction_key', 'client_key'] as $k) {
                if (trim((string) ($c['authorizenet'][$k] ?? '')) === '') {
                    $fail("authorizenet.$k is required when gateway is 'authorizenet'.");
                }
            }
        }
        if ($c['connect']['driver'] !== null) {
            $fail(sprintf("connect.driver '%s' is not available yet. Leave it null until the Connect driver ships.", (string) $c['connect']['driver']));
        }
        if (!is_string($c['currency']) || preg_match('/^[A-Z]{3}$/D', $c['currency']) !== 1) {
            $fail('currency must be a three-letter uppercase ISO 4217 code.');
        }
        // Authorize.net keeps 20 characters of order.invoiceNumber, and the
        // number has 6+ digits; a longer prefix would make captures impossible
        // to match back to their invoice.
        if (!is_string($c['invoice_prefix']) || strlen($c['invoice_prefix']) > 12) {
            $fail('invoice_prefix must be a string of at most 12 characters (invoice numbers must fit Authorize.net\'s 20).');
        }
        if (!is_string($c['base_url']) || !preg_match('#^https?://[^\s/]+#i', $c['base_url'])) {
            $fail('base_url must be an absolute http(s) URL.');
        }
        if ($c['invoice_link_days'] !== null && (!is_int($c['invoice_link_days']) || $c['invoice_link_days'] < 1)) {
            $fail('invoice_link_days must be a positive integer or null.');
        }
        if (!is_int($c['grace_days']) || $c['grace_days'] < 0) {
            $fail('grace_days must be a non-negative integer.');
        }
        foreach (['per_ip', 'per_link', 'failures_per_ip'] as $k) {
            $pair = $c['rate_limits'][$k] ?? null;
            if (!is_array($pair) || count($pair) !== 2 || !is_int($pair[0]) || !is_int($pair[1]) || $pair[0] < 1 || $pair[1] < 1) {
                $fail("rate_limits.$k must be [max hits, window seconds] with positive integers.");
            }
        }
        $a = $c['automation'];
        $retries = $a['retry_attempts'] ?? null;
        if (!is_array($retries) || !array_is_list($retries)) {
            $fail('automation.retry_attempts must be a list of day offsets.');
        }
        $previous = 0;
        foreach ($retries as $days) {
            if (!is_int($days) || $days <= $previous) {
                $fail('automation.retry_attempts must be positive integers in ascending order, e.g. [1, 3, 7].');
            }
            $previous = $days;
        }
        if (!in_array($a['failed_action'], ['past_due', 'active'], true)) {
            $fail("automation.failed_action must be 'past_due' (or 'active' to leave the status alone while retrying).");
        }
        if (!in_array($a['final_action'], ['unpaid', 'canceled'], true)) {
            $fail("automation.final_action must be 'unpaid' or 'canceled'.");
        }
        if (!is_string($c['deck_css']) || $c['deck_css'] === '') {
            $fail('deck_css must be a URL or path to deck.min.css.');
        }
        if ($c['templates_path'] !== null && !is_dir((string) $c['templates_path'])) {
            $fail('templates_path must be an existing directory or null.');
        }
    }
}
