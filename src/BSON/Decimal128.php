<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use JsonSerializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use Stringable;

use function bin2hex;
use function gmp_add;
use function gmp_and;
use function gmp_init;
use function gmp_mul;
use function gmp_pow;
use function gmp_strval;
use function is_string;
use function ord;
use function preg_match;
use function sprintf;
use function str_repeat;
use function strlen;
use function strrev;
use function strtolower;
use function substr;

final class Decimal128 implements Decimal128Interface, JsonSerializable, Type, Stringable
{
    public readonly string $dec;

    public function __construct(string $value)
    {
        $this->dec = self::normalizeAndValidate($value);
    }

    // ------------------------------------------------------------------
    // Decimal128Interface / Stringable
    // ------------------------------------------------------------------

    public function __toString(): string
    {
        return $this->dec;
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    public function jsonSerialize(): mixed
    {
        return ['$numberDecimal' => $this->dec];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    public function __serialize(): array
    {
        return ['dec' => $this->dec];
    }

    public function __unserialize(array $data): void
    {
        if (! isset($data['dec']) || ! is_string($data['dec'])) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\Decimal128 initialization requires "dec" string field',
            );
        }

        $this->dec = self::normalizeAndValidate($data['dec']);
    }

    public static function __set_state(array $properties): static
    {
        if (! isset($properties['dec']) || ! is_string($properties['dec'])) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\Decimal128 initialization requires "dec" string field',
            );
        }

        return new static($properties['dec']);
    }

    public function __debugInfo(): array
    {
        return ['dec' => $this->dec];
    }

    // ------------------------------------------------------------------
    // Binary decoding (IEEE 754-2008 Decimal128 BID format)
    // ------------------------------------------------------------------

    public static function fromBinaryBytes(string $bytes): self
    {
        $b15    = ord($bytes[15]);
        $b14    = ord($bytes[14]);
        $sign   = ($b15 >> 7) & 1;
        $combo5 = ($b15 >> 2) & 0x1F;

        // Special values: combo5 >= 30 means Infinity or NaN
        if ($combo5 >= 0x1E) {
            return new self($combo5 === 0x1E ? ($sign ? '-Infinity' : 'Infinity') : 'NaN');
        }

        // Large coefficient form (combination bits 11xxx): coefficient overflows, treat as 0
        if ($combo5 >= 0x18) {
            $biasedExp = (($b15 & 0x1F) << 9) | ($b14 << 1) | ((ord($bytes[13]) >> 7) & 1);

            return new self(self::decimalToString($sign, '0', $biasedExp - 6176));
        }

        // Normal form: biased exponent from bits 62-49 (high word)
        $biasedExp = (($b15 & 0x7F) << 7) | ($b14 >> 1);
        $exp       = $biasedExp - 6176;

        // 113-bit coefficient: high49 bits from high word + full low 64-bit word
        $highGmp = gmp_init('0x' . bin2hex(strrev(substr($bytes, 8, 8))));
        $lowGmp  = gmp_init('0x' . bin2hex(strrev(substr($bytes, 0, 8))));
        $high49  = gmp_and($highGmp, gmp_init('0x0001FFFFFFFFFFFF'));
        $coeff   = gmp_add(gmp_mul($high49, gmp_pow(gmp_init(2), 64)), $lowGmp);

        return new self(self::decimalToString($sign, gmp_strval($coeff), $exp));
    }

    private static function decimalToString(int $sign, string $coeffStr, int $exp): string
    {
        $prefix = $sign ? '-' : '';

        if ($coeffStr === '0') {
            if ($exp === 0) {
                return $prefix . '0';
            }

            if ($exp > 0 || $exp < -6) {
                return $prefix . '0E' . ($exp > 0 ? '+' : '') . $exp;
            }

            return $prefix . '0.' . str_repeat('0', -$exp);
        }

        $d           = strlen($coeffStr);
        $adjustedExp = $exp + $d - 1;

        if ($exp <= 0 && $adjustedExp >= -6) {
            if ($exp === 0) {
                return $prefix . $coeffStr;
            }

            $decimalPlaces = -$exp;
            if ($decimalPlaces >= $d) {
                return $prefix . '0.' . str_repeat('0', $decimalPlaces - $d) . $coeffStr;
            }

            return $prefix . substr($coeffStr, 0, $d - $decimalPlaces) . '.' . substr($coeffStr, $d - $decimalPlaces);
        }

        $mantissa = $d === 1 ? $coeffStr : ($coeffStr[0] . '.' . substr($coeffStr, 1));

        return $prefix . $mantissa . 'E' . ($adjustedExp >= 0 ? '+' : '') . $adjustedExp;
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private static function normalizeAndValidate(string $value): string
    {
        // Normalize case-insensitive infinity/nan forms
        $lower = strtolower($value);

        if ($lower === 'inf' || $lower === 'infinity' || $lower === '+inf' || $lower === '+infinity') {
            return 'Infinity';
        }

        if ($lower === '-inf' || $lower === '-infinity') {
            return '-Infinity';
        }

        if ($lower === 'nan' || $lower === '+nan' || $lower === '-nan') {
            return 'NaN';
        }

        // Validate as numeric decimal string
        if (! preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $value)) {
            throw new InvalidArgumentException(
                sprintf('Error parsing Decimal128 string: %s', $value),
            );
        }

        return $value;
    }
}
