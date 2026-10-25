<?php

declare(strict_types=1);

// Authorize.net webhook endpoint at /webhooks/authorizenet.
// Register this URL in the Authorize.net merchant interface (Account > Webhooks).

use Cleat\Webhooks\WebhookHandler;

require dirname(__DIR__) . '/bootstrap.php';

$headers = function_exists('getallheaders') ? getallheaders() : $_SERVER;
$rawBody = (string) file_get_contents('php://input');
$response = (new WebhookHandler())->handle($rawBody, $headers);

// Sandbox debugging: with CLEAT_WEBHOOK_CAPTURE_DIR set, each raw notification,
// its X-ANET-Signature and Cleat's answer are saved there, one JSON file each.
// Notifications carry no card data or keys. Leave it unset in production.
if (($captureDir = example_env('CLEAT_WEBHOOK_CAPTURE_DIR')) !== '') {
    $signature = null;
    foreach ($headers as $name => $value) {
        if (strcasecmp((string) $name, 'X-ANET-Signature') === 0) {
            $signature = (string) $value;
        }
    }
    is_dir($captureDir) || mkdir($captureDir, 0777, true);
    file_put_contents(
        $captureDir . DIRECTORY_SEPARATOR . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json',
        json_encode([
            'received_at' => gmdate('c'),
            'signature' => $signature,
            'status' => $response->status,
            'response' => $response->body,
            'body' => $rawBody,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    );
}

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header("$name: $value");
}
echo $response->body;
