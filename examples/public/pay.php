<?php

declare(strict_types=1);

// Hosted invoice page at /pay/{token}. Apache: see .htaccess. php -S: see router.php.

use Cleat\Http\InvoicePayPage;

require dirname(__DIR__) . '/bootstrap.php'; // Cleat::configure(...)
session_start();                             // Cleat keeps no session; it binds CSRF to yours

$page = new InvoicePayPage(session_id());
$token = (string) ($_GET['token'] ?? '');
$response = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $page->submit($token, $_POST, (string) ($_SERVER['REMOTE_ADDR'] ?? ''))
    : $page->show($token);

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header("$name: $value");
}
echo $response->body;
