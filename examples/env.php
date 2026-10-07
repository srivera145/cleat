<?php

declare(strict_types=1);

/*
 * Settings for the examples. A real environment variable wins; examples/.env
 * (gitignored, KEY=value lines, # comments, no inline comments) fills in the
 * rest. Values are read here and never echoed.
 */
function example_env(string $name, string $default = ''): string
{
    static $file = null;
    if ($file === null) {
        $file = [];
        $path = __DIR__ . DIRECTORY_SEPARATOR . '.env';
        foreach (is_readable($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $file[$key] = $value;
        }
    }
    $value = getenv($name);
    if (is_string($value) && $value !== '') {
        return $value;
    }
    return ($file[$name] ?? '') !== '' ? $file[$name] : $default;
}

/** True when Authorize.net credentials are configured (otherwise the examples use FakeGateway). */
function example_uses_authorizenet(): bool
{
    return example_env('AUTHORIZENET_LOGIN_ID') !== '';
}

/** The examples talk to the Authorize.net sandbox only. Anything else stops here. */
function example_require_sandbox(): void
{
    if (strtolower(example_env('AUTHORIZENET_SANDBOX')) !== 'true') {
        throw new RuntimeException('The examples run against the Authorize.net sandbox only. Set AUTHORIZENET_SANDBOX=true in examples/.env.');
    }
}
