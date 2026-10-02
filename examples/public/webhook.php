<?php

declare(strict_types=1);

// Authorize.net webhook endpoint at /webhooks/authorizenet.
// Register this URL in the Authorize.net merchant interface (Account > Webhooks).

use Cleat\Webhooks\WebhookHandler;

require dirname(__DIR__) . '/bootstrap.php';

$headers = function_exists('getallheaders') ? getallheaders() : $_SERVER;
$response = (new WebhookHandler())->handle((string) file_get_contents('php://input'), $headers);

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header("$name: $value");
}
echo $response->body;
