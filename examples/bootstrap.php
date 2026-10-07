<?php

declare(strict_types=1);

/*
 * Demo wiring shared by the examples. A real app does the same in its own
 * bootstrap: build a PDO, load the config, pass a mailer, call configure().
 *
 * Settings come from the environment or examples/.env (see env.php). All optional:
 *   AUTHORIZENET_LOGIN_ID, AUTHORIZENET_TRANSACTION_KEY,
 *   AUTHORIZENET_SIGNATURE_KEY, AUTHORIZENET_CLIENT_KEY
 *                   when the login id is set the examples use the Authorize.net
 *                   sandbox; otherwise FakeGateway
 *   AUTHORIZENET_SANDBOX  must be "true" whenever a login id is set
 *   CLEAT_APP_KEY   CSRF secret, 32+ bytes (required with Authorize.net)
 *   CLEAT_DB_DSN    default mysql:host=127.0.0.1;port=3306;dbname=cleat_demo;charset=utf8mb4
 *                   (dbname=cleat_sandbox with Authorize.net)
 *   CLEAT_DB_USER   default root
 *   CLEAT_DB_PASS   default empty
 *   CLEAT_BASE_URL  default http://localhost:8080
 */

use Cleat\Cleat;
use Cleat\Mail\MailerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/env.php';

$authorizeNet = example_uses_authorizenet();
if ($authorizeNet) {
    example_require_sandbox();
}

$pdo = new PDO(
    example_env('CLEAT_DB_DSN', 'mysql:host=127.0.0.1;port=3306;dbname=' . ($authorizeNet ? 'cleat_sandbox' : 'cleat_demo') . ';charset=utf8mb4'),
    example_env('CLEAT_DB_USER', 'root'),
    example_env('CLEAT_DB_PASS'),
);

$config = require dirname(__DIR__) . '/config/cleat.php';
$config['base_url'] = example_env('CLEAT_BASE_URL', 'http://localhost:8080');
$config['brand'] = ['name' => 'Acme Tools', 'support_email' => 'billing@acme.test'] + $config['brand'];
if ($authorizeNet) {
    $config['gateway'] = 'authorizenet';
    $config['app_key'] = example_env('CLEAT_APP_KEY');
    $config['authorizenet'] = [
        'login_id' => example_env('AUTHORIZENET_LOGIN_ID'),
        'transaction_key' => example_env('AUTHORIZENET_TRANSACTION_KEY'),
        'signature_key' => example_env('AUTHORIZENET_SIGNATURE_KEY'),
        'client_key' => example_env('AUTHORIZENET_CLIENT_KEY'),
        'sandbox' => true,
    ];
}

// Demo mailer: each email is written to a temp folder so you can open it in a browser.
$mailer = new class implements MailerInterface {
    public function send(string $to, string $subject, string $html, ?string $text = null): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cleat-demo-mail';
        is_dir($dir) || mkdir($dir, 0777, true);
        $name = gmdate('Ymd-His') . '-' . trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($subject)), '-') . '.html';
        file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $html);
    }
};

Cleat::configure($pdo, $config, $mailer);
