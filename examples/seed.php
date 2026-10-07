<?php

declare(strict_types=1);

/*
 * Creates (or resets) the demo database with sample data and prints links to
 * try. Without Authorize.net keys it uses cleat_demo and the FakeGateway, so no
 * account is needed. With sandbox keys in examples/.env it uses cleat_sandbox
 * and creates the sample customer's CIM profile in the Authorize.net sandbox.
 *
 *   php examples/seed.php
 *   php -S localhost:8080 -t examples/public examples/public/router.php
 */

use Cleat\Customer;
use Cleat\PaymentLink;
use Cleat\Price;
use Cleat\Product;
use Cleat\Support\Schema;

require_once __DIR__ . '/env.php';

$dbName = example_uses_authorizenet() ? 'cleat_sandbox' : 'cleat_demo';
$dsn = example_env('CLEAT_DB_DSN', "mysql:host=127.0.0.1;port=3306;dbname=$dbName;charset=utf8mb4");
if (preg_match('/dbname=([A-Za-z0-9_]+)/', $dsn, $m) === 1) {
    $server = new PDO(preg_replace('/dbname=[A-Za-z0-9_]+;?/', '', $dsn), example_env('CLEAT_DB_USER', 'root'), example_env('CLEAT_DB_PASS'));
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$m[1]}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

require __DIR__ . '/bootstrap.php';

$pdo = \Cleat\Cleat::pdo();
Schema::dropAll($pdo);
Schema::migrate($pdo);

$team = Product::create(['name' => 'Team plan', 'description' => 'Shared projects, client invoicing and priority support for up to 10 people.']);
Price::create($team, ['lookup_key' => 'team-monthly', 'nickname' => 'Monthly', 'amount' => 2900, 'billing_type' => 'recurring', 'billing_interval' => 'month', 'trial_days' => 14]);
$guide = Product::create(['name' => 'The Field Guide', 'description' => 'A 180-page PDF on running a service business. Instant download.']);
Price::create($guide, ['lookup_key' => 'field-guide', 'amount' => 1900]);

$client = Customer::create(['email' => 'sam@example.com', 'name' => 'Sam Rivera']);
$invoice = $client->newInvoice()
    ->addItem('Website redesign, phase 2', 240000)
    ->addItem('Managed hosting (monthly)', 2500, 12)
    ->memo('Thanks for working with us. Payment is due within 14 days.')
    ->dueIn(14)
    ->send();

$trialLink = PaymentLink::create('team-monthly', ['allow_quantity_change' => true, 'max_quantity' => 10]);
$guideLink = PaymentLink::create('field-guide', ['max_uses' => 100]);

$mail = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cleat-demo-mail';
$mode = \Cleat\Cleat::isFake() ? 'FakeGateway: pick a test card on the payment form' : 'Authorize.net sandbox: use a sandbox test card';
fwrite(STDOUT, <<<TXT
Cleat demo data created ($mode).

  Invoice {$invoice->number}:  {$invoice->paymentUrl()}
  Subscription link:     {$trialLink->url()}
  One-time link:         {$guideLink->url()}

Emails are written to: $mail
Serve with: php -S localhost:8080 -t examples/public examples/public/router.php

TXT);
