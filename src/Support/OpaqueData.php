<?php

declare(strict_types=1);

namespace Cleat\Support;

use InvalidArgumentException;

/**
 * Accept.js opaque data is the only form of card data Cleat accepts. This
 * guard rejects anything that looks like a raw card number before it can
 * travel any further.
 */
final class OpaqueData
{
    /**
     * @param array<string, mixed> $data
     * @return array{dataDescriptor: string, dataValue: string}
     */
    public static function from(array $data): array
    {
        $descriptor = $data['dataDescriptor'] ?? null;
        $value = $data['dataValue'] ?? null;

        if (!is_string($descriptor) || !is_string($value) || trim($descriptor) === '' || trim($value) === '') {
            throw new InvalidArgumentException('Opaque data needs a dataDescriptor and a dataValue from Accept.js.');
        }
        foreach (array_keys($data) as $key) {
            if (preg_match('/card|pan|cvv|cvc|expir/i', (string) $key) === 1) {
                throw new InvalidArgumentException('Raw card fields are never accepted. Tokenize with Accept.js and pass only opaque data.');
            }
        }
        if (self::looksLikePan($value) || self::looksLikePan($descriptor)) {
            throw new InvalidArgumentException('That looks like a raw card number. Cleat only accepts Accept.js opaque data.');
        }
        if (strlen($descriptor) > 128 || strlen($value) > 8192) {
            throw new InvalidArgumentException('Opaque data is too long.');
        }

        return ['dataDescriptor' => $descriptor, 'dataValue' => $value];
    }

    /** 13 to 19 digits, optionally spaced or dashed, that pass a Luhn check. */
    public static function looksLikePan(string $value): bool
    {
        $digits = preg_replace('/[\s-]/', '', $value) ?? '';
        if (preg_match('/^\d{13,19}$/D', $digits) !== 1) {
            return false;
        }
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = !$double;
        }
        return $sum % 10 === 0;
    }
}
