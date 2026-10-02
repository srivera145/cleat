<?php

declare(strict_types=1);

namespace Cleat\Support;

use BackedEnum;
use Cleat\Cleat;
use Cleat\Exceptions\CleatException;
use Cleat\Money;
use DateTimeInterface;
use Stringable;
use Throwable;

/**
 * Renders the plain-PHP templates. Every template gets three helpers and no
 * other way to print:
 *
 *   $e($value)            htmlspecialchars, for every value. Money prints formatted.
 *   $json($value)         JSON safe to drop inside a <script> block.
 *   $partial($name, $v)   another template's already-escaped output.
 *
 * plus $raw($html), used in exactly one place: a captcha widget the host
 * supplied as trusted HTML.
 */
final class View
{
    /** @param array<string, mixed> $data */
    public static function render(string $template, array $data = []): string
    {
        $file = self::resolve($template);

        $e = static fn (mixed $value): string => htmlspecialchars(self::stringify($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $json = static fn (mixed $value): string => json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $partial = static fn (string $name, array $vars = []): string => self::render('partials/' . $name, $vars);
        $raw = static fn (string $trustedHtml): string => $trustedHtml;

        $render = static function (string $__file, array $__data) use ($e, $json, $partial, $raw): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
                return (string) ob_get_clean();
            } catch (Throwable $t) {
                ob_end_clean();
                throw $t;
            }
        };

        return $render($file, $data);
    }

    public static function resolve(string $template): string
    {
        if (preg_match('#^[a-z0-9_]+(/[a-z0-9_]+)*$#', $template) !== 1) {
            throw new CleatException(sprintf('Invalid template name "%s".', $template));
        }
        $override = Cleat::isConfigured() ? Cleat::config('templates_path') : null;
        foreach ([$override, dirname(__DIR__, 2) . '/templates'] as $dir) {
            if ($dir !== null && is_file($path = rtrim((string) $dir, '/\\') . '/' . $template . '.php')) {
                return $path;
            }
        }
        throw new CleatException(sprintf('Template "%s" not found.', $template));
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof Money => $value->format(),
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format('M j, Y'),
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => throw new CleatException('Cannot print a value of type ' . get_debug_type($value) . ' in a template.'),
        };
    }
}
