<?php

declare(strict_types=1);

namespace Cleat\Support;

/**
 * Logging with redaction built in. Cleat never passes a request body, an
 * opaque card token, or a credential to the log, and redact() is a second
 * line of defence for anything that slips into a context array.
 *
 * Host apps route logs anywhere with Log::setLogger(fn ($level, $message, $context) => ...).
 */
final class Log
{
    /** @var (callable(string, string, array<string, mixed>): void)|null */
    private static $logger = null;

    private const SENSITIVE = '/(transaction_?key|signature_?key|app_?key|client_?key|data_?value|opaque|card_?number|card_?code|cvv|cvc|password|secret|token)/i';

    /** @param (callable(string, string, array<string, mixed>): void)|null $logger */
    public static function setLogger(?callable $logger): void
    {
        self::$logger = $logger;
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function redact(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key) === 1) {
                $clean[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $clean[$key] = self::redact($value);
            } else {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }

    /** @param array<string, mixed> $context */
    private static function write(string $level, string $message, array $context): void
    {
        $context = self::redact($context);
        if (self::$logger !== null) {
            (self::$logger)($level, $message, $context);
            return;
        }
        $suffix = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        error_log(sprintf('[cleat] %s: %s%s', $level, $message, $suffix));
    }
}
