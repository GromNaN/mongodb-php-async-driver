<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use JsonSerializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use Stringable;

use function is_string;
use function ltrim;
use function preg_match;
use function rtrim;
use function sprintf;
use function strlen;

final class Decimal128 implements Decimal128Interface, JsonSerializable, Type, Stringable
{
    public readonly string $dec;

    final public function __construct(string $value)
    {
        $this->dec = self::normalizeAndValidate($value);
    }

    // ------------------------------------------------------------------
    // Decimal128Interface / Stringable
    // ------------------------------------------------------------------

    final public function __toString(): string
    {
        return $this->dec;
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    final public function jsonSerialize(): mixed
    {
        return ['$numberDecimal' => $this->dec];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    final public function __serialize(): array
    {
        return ['dec' => $this->dec];
    }

    final public function __unserialize(array $data): void
    {
        if (! is_string($data['dec'] ?? null)) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\Decimal128 initialization requires "dec" string field',
            );
        }

        $this->dec = self::normalizeAndValidate($data['dec']);
    }

    final public static function __set_state(array $properties): static
    {
        if (! is_string($properties['dec'] ?? null)) {
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
    // Private helpers
    // ------------------------------------------------------------------

    private static function normalizeAndValidate(string $value): string
    {
        // Single regex validates the entire input and captures components for further checks.
        //   Group 1 (sign):  optional leading sign
        //   Group 2 (inf):   infinity keyword (case-insensitive)
        //   Group 3 (nan):   NaN keyword (case-insensitive)
        //   Group 4 (int):   integer digits of mantissa (may be empty when leading dot is used)
        //   Group 5 (frac):  fractional digits after the decimal point (absent when no dot)
        //   Group 6 (exp):   signed exponent digits after E (absent when no exponent)
        if (
            ! preg_match(
                '/^(?P<sign>[+-]?)(?:(?P<inf>inf(?:inity)?)|(?P<nan>nan)|(?P<int>\d*)(?:\.(?P<frac>\d*))?(?:[eE](?P<exp>[+-]?\d+))?)$/i',
                $value,
                $m,
            )
        ) {
            throw new InvalidArgumentException(
                sprintf('Error parsing Decimal128 string: %s', $value),
            );
        }

        if (isset($m['inf']) && $m['inf'] !== '') {
            return $m['sign'] === '-' ? '-Infinity' : 'Infinity';
        }

        if (isset($m['nan']) && $m['nan'] !== '') {
            return 'NaN';
        }

        $intPart   = $m['int'];
        $fracPart  = $m['frac'] ?? '';
        $parsedExp = isset($m['exp']) && $m['exp'] !== '' ? (int) $m['exp'] : 0;

        // Reject strings that are neither inf/nan nor have any digits (e.g. bare "+" or ".")
        if ($intPart === '' && $fracPart === '') {
            throw new InvalidArgumentException(
                sprintf('Error parsing Decimal128 string: %s', $value),
            );
        }

        // Validate significant digits and exponent range for Decimal128 (34 digits, exp -6176..6111)
        $decimalPlaces = strlen($fracPart);
        $coeffDigits   = ltrim($intPart . $fracPart, '0') ?: '0';

        if ($coeffDigits !== '0') {
            $trimmedCoeff  = rtrim($coeffDigits, '0');
            $trailingZeros = strlen($coeffDigits) - strlen($trimmedCoeff);
            $sigDigits     = strlen($trimmedCoeff);
            $coeffLen      = strlen($coeffDigits);

            // Too many significant digits to fit in a 34-digit coefficient
            if ($sigDigits > 34) {
                throw new InvalidArgumentException(
                    sprintf('Error parsing Decimal128 string: %s', $value),
                );
            }

            // Overflow: even filling the coefficient to 34 digits the stored exponent exceeds 6111.
            // E_min_achievable = parsedExp - decimalPlaces + coeffLen - 34
            if ($parsedExp - $decimalPlaces + $coeffLen - 34 > 6111) {
                throw new InvalidArgumentException(
                    sprintf('Error parsing Decimal128 string: %s', $value),
                );
            }

            // Underflow: even removing all trailing zeros the stored exponent is below -6176.
            // E_max_achievable = parsedExp - decimalPlaces + trailingZeros
            if ($parsedExp - $decimalPlaces + $trailingZeros < -6176) {
                throw new InvalidArgumentException(
                    sprintf('Error parsing Decimal128 string: %s', $value),
                );
            }
        }

        return $value;
    }
}
