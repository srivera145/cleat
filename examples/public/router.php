<?php

declare(strict_types=1);

// Router for PHP's built-in server:  php -S localhost:8080 -t examples/public examples/public/router.php

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if (preg_match('#^/(pay|checkout)/([a-f0-9]{64})$#', $path, $m) === 1) {
    $_GET['token'] = $m[2];
    require __DIR__ . '/' . $m[1] . '.php';
    return true;
}
if ($path === '/webhooks/authorizenet') {
    require __DIR__ . '/webhook.php';
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not found. Run `php examples/seed.php` for demo links.\n";
return true;
