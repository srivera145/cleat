<?php

declare(strict_types=1);

/**
 * One checkout submit in its own PHP process, for the real concurrency test.
 * Usage: php checkout_worker.php <link-token> <email> <ip> <app-key>
 * Prints one JSON line: {"status": 200|303|410|..., "body": "..."}.
 */

use Cleat\Cleat;
use Cleat\Gateway\FakeGateway;
use Cleat\Http\CheckoutPage;
use Cleat\Http\Csrf;
use Cleat\Mail\ArrayMailer;
use Cleat\Support\Log;
use Cleat\Tests\Support\TestDatabase;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $token, $email, $ip, $appKey] = $argv;

Cleat::configure(TestDatabase::connect(), [
    'gateway' => 'fake',
    'base_url' => 'https://billing.test',
    'app_key' => $appKey,
    'brand' => ['name' => 'Acme Tools'],
], new ArrayMailer());
// A slow gateway so both processes pass the "sold out?" pre-check before either finishes.
Cleat::setGateway(new FakeGateway(delayMs: 1500));
Log::setLogger(static function (): void {
});

$session = 'worker-' . getmypid();
$response = (new CheckoutPage($session))->submit($token, [
    '_csrf' => (new Csrf($appKey))->issue($token, $session, Cleat::now()),
    'email' => $email,
    'name' => 'Racer',
    'dataDescriptor' => FakeGateway::DESCRIPTOR,
    'dataValue' => 'tok_visa',
], $ip);

echo json_encode(['status' => $response->status, 'body' => strip_tags($response->body)]), "\n";
