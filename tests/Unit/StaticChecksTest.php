<?php

declare(strict_types=1);

namespace Cleat\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Self-check 16, done with PHP's own tokenizer rather than a text grep, so
 * comments and strings cannot hide or fake a match.
 */
final class StaticChecksTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** No float anywhere on the money path: no float literals, casts, division, or rounding functions in src/. */
    public function test_no_float_math_in_src(): void
    {
        $banned = ['round', 'floor', 'ceil', 'floatval', 'fdiv', 'fmod', 'number_format', 'money_format', 'bcdiv'];
        $problems = [];
        foreach ($this->phpFiles('src') as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            foreach ($tokens as $i => $t) {
                $line = is_array($t) ? $t[2] : null;
                if (is_array($t) && in_array($t[0], [T_DNUMBER, T_DOUBLE_CAST], true)) {
                    $problems[] = sprintf('%s:%d %s', $this->rel($file), $line, token_name($t[0]) . ' ' . $t[1]);
                }
                if ($t === '/' || (is_array($t) && $t[0] === T_DIV_EQUAL)) {
                    $problems[] = sprintf('%s: division operator', $this->rel($file));
                }
                if (is_array($t) && $t[0] === T_STRING && in_array(strtolower($t[1]), $banned, true) && $this->nextSignificant($tokens, $i) === '(') {
                    $problems[] = sprintf('%s:%d %s()', $this->rel($file), $line, $t[1]);
                }
            }
        }
        $this->assertSame([], $problems);
    }

    /** No echo, print, inline HTML, header(), http_response_code() or exit in src/Http (or anywhere in src/). */
    public function test_no_output_or_header_calls_in_src(): void
    {
        $problems = [];
        foreach ($this->phpFiles('src') as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            foreach ($tokens as $i => $t) {
                if (!is_array($t)) {
                    continue;
                }
                if (in_array($t[0], [T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO, T_EXIT], true)
                    || ($t[0] === T_INLINE_HTML && trim($t[1]) !== '')) {
                    $problems[] = sprintf('%s:%d %s', $this->rel($file), $t[2], token_name($t[0]));
                }
                if ($t[0] === T_STRING && in_array(strtolower($t[1]), ['header', 'http_response_code', 'setcookie', 'header_remove', 'ob_end_flush', 'flush'], true)
                    && $this->nextSignificant($tokens, $i) === '(' && !$this->isMethodOrFunctionDefinition($tokens, $i)) {
                    $problems[] = sprintf('%s:%d %s()', $this->rel($file), $t[2], $t[1]);
                }
            }
        }
        $this->assertSame([], $problems);
        $this->assertNotEmpty($this->phpFiles('src/Http'));
    }

    /** No card input carries a name attribute; only the opaque token fields post. */
    public function test_card_inputs_have_no_name_attribute(): void
    {
        $source = (string) file_get_contents(self::ROOT . '/templates/partials/payment_form.php');
        preg_match_all('/<input\b[^>]*>/i', $source, $inputs);
        $cardInputs = array_filter($inputs[0], static fn (string $tag) => str_contains($tag, 'data-cleat-card') || preg_match('/autocomplete="cc-/', $tag));
        $this->assertCount(4, $cardInputs, 'number, expiry, code, zip');
        foreach ($cardInputs as $tag) {
            $this->assertDoesNotMatchRegularExpression('/\sname\s*=/i', $tag, $tag);
        }
        $this->assertMatchesRegularExpression('/<select class="select" id="cleat-test-token">/', $source, 'fake-mode test card picker has no name either');

        // Across every template, nothing that looks like a card field is named.
        foreach ($this->phpFiles('templates') as $file) {
            preg_match_all('/<(?:input|select|textarea)\b[^>]*>/i', (string) file_get_contents($file), $m);
            foreach ($m[0] as $tag) {
                $this->assertDoesNotMatchRegularExpression('/name="(card|cc|cvv|cvc|exp|number|pan)/i', $tag, $this->rel($file));
            }
        }
        $this->assertStringContainsString("apiLoginID", $source);
        $this->assertStringContainsString("clientKey", $source);
        $this->assertStringNotContainsStringIgnoringCase('transaction_key', $source);
        $this->assertStringNotContainsStringIgnoringCase('transactionKey', $source);
    }

    /** Secrets and opaque card data are never passed to the log, and nothing logs around Log. */
    public function test_no_secrets_or_opaque_data_in_log_calls(): void
    {
        $problems = [];
        foreach ($this->phpFiles('src') as $file) {
            $code = (string) file_get_contents($file);
            if (!str_ends_with($file, 'Support' . DIRECTORY_SEPARATOR . 'Log.php') && !str_ends_with($file, 'Support/Log.php')
                && preg_match('/\b(error_log|syslog|var_dump|print_r|var_export|file_put_contents)\s*\(/', $code, $m) === 1) {
                $problems[] = $this->rel($file) . ': ' . $m[1] . '()';
            }
            preg_match_all('/Log::(?:info|warning|error)\((.*?)\);/s', $code, $calls);
            foreach ($calls[1] as $args) {
                if (preg_match('/opaque|dataValue|dataDescriptor|transactionKey|transaction_key|signature_?key|appKey|app_key|\$json|\$body|\$payload|rawBody/i', $args) === 1) {
                    $problems[] = $this->rel($file) . ': ' . trim(preg_replace('/\s+/', ' ', $args));
                }
            }
        }
        $this->assertSame([], $problems);
    }

    public function test_every_template_print_is_escaped(): void
    {
        $problems = [];
        foreach ($this->phpFiles('templates') as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all('/<\?=\s*(.{0,40})/s', $code, $m);
            foreach ($m[1] as $expr) {
                if (preg_match('/^\$(e|partial|json|raw)\(/', $expr) !== 1) {
                    $problems[] = $this->rel($file) . ': <?= ' . strtok($expr, "\n");
                }
            }
            if (preg_match('/<\?php\s+(echo|print)\b/', $code) === 1) {
                $problems[] = $this->rel($file) . ': echo/print';
            }
            if (substr_count($code, '$raw(') > 0 && !str_ends_with(str_replace('\\', '/', $file), 'partials/payment_form.php')) {
                $problems[] = $this->rel($file) . ': $raw() outside the captcha slot';
            }
        }
        $this->assertSame([], $problems);
    }

    public function test_every_file_declares_strict_types(): void
    {
        foreach ($this->phpFiles('src') as $file) {
            $this->assertStringContainsString('declare(strict_types=1);', (string) file_get_contents($file), $this->rel($file));
        }
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    private function rel(string $file): string
    {
        return str_replace([realpath(self::ROOT) . DIRECTORY_SEPARATOR, '\\'], ['', '/'], realpath($file) ?: $file);
    }

    private function nextSignificant(array $tokens, int $i): mixed
    {
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }
        return null;
    }

    private function isMethodOrFunctionDefinition(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $prev = $tokens[$j];
            return (is_array($prev) && in_array($prev[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true));
        }
        return false;
    }
}
