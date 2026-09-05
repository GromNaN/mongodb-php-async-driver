<?php

declare(strict_types=1);

namespace MongoDB\Benchmark\BSON;

use MongoDB\BSON\Decimal128;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

use function ctype_digit;
use function ltrim;
use function preg_match;
use function rtrim;
use function strlen;
use function strspn;
use function strtolower;

/**
 * Benchmark Decimal128 parsing across three implementations:
 *
 * "old"       = strtolower + fixed-string comparisons + simple numeric regex (no range checking)
 * "new"       = single named-group regex + coefficient/exponent range validation (string concat)
 * "optimized" = ctype_digit fast-path + positional groups + strspn (no concat for range check)
 */
#[Revs(1000)]
#[Warmup(2)]
final class Decimal128Bench
{
    // ------------------------------------------------------------------
    // Parameter providers
    // ------------------------------------------------------------------

    /** @return iterable<string, array{value: string}> */
    public function provideTypical(): iterable
    {
        yield 'integer'        => ['value' => '123456'];
        yield 'negative'       => ['value' => '-9999.99'];
        yield 'scientific'     => ['value' => '1.23456789E+10'];
        yield 'sci_neg_exp'    => ['value' => '9.87654321E-10'];
        yield 'max_precision'  => ['value' => '1.000000000000000000000000000001E+10'];
        yield 'infinity_upper' => ['value' => 'Infinity'];
        yield 'infinity_lower' => ['value' => 'infinity'];
        yield 'neg_infinity'   => ['value' => '-Infinity'];
        yield 'nan'            => ['value' => 'NaN'];
        yield 'nan_lower'      => ['value' => 'nan'];
        yield 'zero'           => ['value' => '0'];
        yield 'zero_dot'       => ['value' => '0.0'];
    }

    // ------------------------------------------------------------------
    // Benchmark subjects
    // ------------------------------------------------------------------

    #[Groups(['old'])]
    #[ParamProviders('provideTypical')]
    public function benchOld(array $params): void
    {
        self::normalizeOld($params['value']);
    }

    #[Groups(['new'])]
    #[ParamProviders('provideTypical')]
    public function benchNew(array $params): void
    {
        self::normalizeNew($params['value']);
    }

    #[Groups(['optimized'])]
    #[ParamProviders('provideTypical')]
    public function benchOptimized(array $params): void
    {
        self::normalizeOptimized($params['value']);
    }

    /** Public API — constructor (object allocation + parsing). */
    #[Groups(['ctor'])]
    #[ParamProviders('provideTypical')]
    public function benchCtor(array $params): void
    {
        new Decimal128($params['value']);
    }

    // ------------------------------------------------------------------
    // Implementations
    // ------------------------------------------------------------------

    /**
     * OLD: strtolower + fixed-string comparisons + simple numeric regex (no range validation).
     */
    private static function normalizeOld(string $value): string
    {
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

        if (! preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $value)) {
            return 'INVALID';
        }

        return $value;
    }

    /**
     * NEW: single named-group regex + range validation (string concatenation for coefficient).
     */
    private static function normalizeNew(string $value): string
    {
        if (
            ! preg_match(
                '/^(?P<sign>[+-]?)(?:(?P<inf>inf(?:inity)?)|(?P<nan>nan)|(?P<int>\d*)(?:\.(?P<frac>\d*))?(?:[eE](?P<exp>[+-]?\d+))?)$/i',
                $value,
                $m,
            )
        ) {
            return 'INVALID';
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

        if ($intPart === '' && $fracPart === '') {
            return 'INVALID';
        }

        $decimalPlaces = strlen($fracPart);
        $coeffDigits   = ltrim($intPart . $fracPart, '0') ?: '0';

        if ($coeffDigits !== '0') {
            $trimmedCoeff  = rtrim($coeffDigits, '0');
            $trailingZeros = strlen($coeffDigits) - strlen($trimmedCoeff);
            $sigDigits     = strlen($trimmedCoeff);
            $coeffLen      = strlen($coeffDigits);

            if ($sigDigits > 34) {
                return 'INVALID';
            }

            if ($parsedExp - $decimalPlaces + $coeffLen - 34 > 6111) {
                return 'INVALID';
            }

            if ($parsedExp - $decimalPlaces + $trailingZeros < -6176) {
                return 'INVALID';
            }
        }

        return $value;
    }

    /**
     * OPTIMIZED:
     *   1. ctype_digit fast path for plain unsigned integers ≤ 34 digits (most common in BSON)
     *   2. Positional regex groups instead of named groups (PHP stores both, positional is lighter)
     *   3. strspn to count leading zeros without concatenating intPart.fracPart
     *   4. Trailing zeros counted from parts directly, avoiding the combined string allocation
     *
     * Groups: $m[1]=sign  $m[2]=inf  $m[3]=nan  $m[4]=int  $m[5]=frac  $m[6]=exp
     */
    private static function normalizeOptimized(string $value): string
    {
        // Fast path: plain unsigned integer ≤ 34 digits — no regex, no range check needed.
        // sigDigits ≤ strlen ≤ 34, stored exponent = 0 ∈ [-6176, 6111].
        if (strlen($value) <= 34 && ctype_digit($value)) {
            return $value;
        }

        if (
            ! preg_match(
                '/^([+-]?)(?:(inf(?:inity)?)|(nan)|(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?)$/i',
                $value,
                $m,
            )
        ) {
            return 'INVALID';
        }

        if (($m[2] ?? '') !== '') {
            return $m[1] === '-' ? '-Infinity' : 'Infinity';
        }

        if (($m[3] ?? '') !== '') {
            return 'NaN';
        }

        $intPart   = $m[4];
        $fracPart  = $m[5] ?? '';
        $parsedExp = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;

        if ($intPart === '' && $fracPart === '') {
            return 'INVALID';
        }

        $intLen  = strlen($intPart);
        $fracLen = strlen($fracPart);

        // Count leading zeros of the coefficient (intPart . fracPart) without concatenation.
        $leadZeros = strspn($intPart, '0');
        if ($leadZeros === $intLen) {
            $leadZeros += strspn($fracPart, '0');
        }

        $coeffLen = $intLen + $fracLen - $leadZeros;

        if ($coeffLen > 0) {
            // Trailing zeros of the coefficient, counted from the end of fracPart then intPart.
            if ($fracLen > 0) {
                $rtrimFracLen  = strlen(rtrim($fracPart, '0'));
                $trailingZeros = $fracLen - $rtrimFracLen;
                if ($rtrimFracLen === 0) {
                    // All of fracPart is zeros — also count from end of intPart.
                    $trailingZeros += $intLen - strlen(rtrim($intPart, '0'));
                }
            } else {
                $trailingZeros = $intLen - strlen(rtrim($intPart, '0'));
            }

            $sigDigits = $coeffLen - $trailingZeros;

            if ($sigDigits > 34) {
                return 'INVALID';
            }

            if ($parsedExp - $fracLen + $coeffLen - 34 > 6111) {
                return 'INVALID';
            }

            if ($parsedExp - $fracLen + $trailingZeros < -6176) {
                return 'INVALID';
            }
        }

        return $value;
    }
}
