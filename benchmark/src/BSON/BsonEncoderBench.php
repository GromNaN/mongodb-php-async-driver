<?php

declare(strict_types=1);

namespace MongoDB\Benchmark\BSON;

use MongoDB\BSON\Document;
use MongoDB\BSON\PackedArray;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

use function array_fill;
use function chr;
use function implode;
use function pack;
use function strlen;

/**
 * Benchmark BsonEncoder document/array encoding: string concatenation vs implode(parts[]).
 *
 * "concat"  = $body .= element  (O(n²) reallocations)
 * "implode" = $parts[] = element; implode('', $parts)  (O(n) single allocation)
 *
 * Measured via the public Document/PackedArray API which drives BsonEncoder internally.
 */
#[Revs(200)]
#[Warmup(2)]
final class BsonEncoderBench
{
    // ------------------------------------------------------------------
    // Parameter providers
    // ------------------------------------------------------------------

    /** @return iterable<string, array{doc: array}> */
    public function provideDocuments(): iterable
    {
        yield 'small_5'    => ['doc' => $this->makeDoc(5)];
        yield 'medium_20'  => ['doc' => $this->makeDoc(20)];
        yield 'large_100'  => ['doc' => $this->makeDoc(100)];
        yield 'xlarge_500' => ['doc' => $this->makeDoc(500)];
    }

    /** @return iterable<string, array{arr: array}> */
    public function provideArrays(): iterable
    {
        yield 'small_5'    => ['arr' => $this->makeArr(5)];
        yield 'medium_20'  => ['arr' => $this->makeArr(20)];
        yield 'large_100'  => ['arr' => $this->makeArr(100)];
        yield 'xlarge_500' => ['arr' => $this->makeArr(500)];
    }

    // ------------------------------------------------------------------
    // Current implementation (implode after this commit)
    // ------------------------------------------------------------------

    #[Groups(['doc_implode'])]
    #[ParamProviders('provideDocuments')]
    public function benchDocumentFromPHP(array $params): void
    {
        Document::fromPHP($params['doc']);
    }

    #[Groups(['arr_implode'])]
    #[ParamProviders('provideArrays')]
    public function benchArrayFromPHP(array $params): void
    {
        PackedArray::fromPHP($params['arr']);
    }

    // ------------------------------------------------------------------
    // Old implementation (concat in loop)
    // ------------------------------------------------------------------

    #[Groups(['doc_concat'])]
    #[ParamProviders('provideDocuments')]
    public function benchDocumentConcat(array $params): void
    {
        self::encodeDocumentConcat($params['doc']);
    }

    #[Groups(['arr_concat'])]
    #[ParamProviders('provideArrays')]
    public function benchArrayConcat(array $params): void
    {
        self::encodeArrayConcat($params['arr']);
    }

    // ------------------------------------------------------------------
    // Inlined old implementation (string concatenation)
    // ------------------------------------------------------------------

    private static function encodeDocumentConcat(array $doc): string
    {
        $body = '';
        foreach ($doc as $key => $value) {
            $body .= self::encodeElement((string) $key, $value);
        }

        $totalLen = 4 + strlen($body) + 1;

        return pack('V', $totalLen) . $body . "\x00";
    }

    private static function encodeArrayConcat(array $arr): string
    {
        $body = '';
        foreach ($arr as $index => $value) {
            $body .= self::encodeElement((string) $index, $value);
        }

        $totalLen = 4 + strlen($body) + 1;

        return pack('V', $totalLen) . $body . "\x00";
    }

    // ------------------------------------------------------------------
    // Inlined new implementation (collect + implode)
    // ------------------------------------------------------------------

    private static function encodeDocumentImplode(array $doc): string
    {
        $parts = [];
        foreach ($doc as $key => $value) {
            $parts[] = self::encodeElement((string) $key, $value);
        }

        $body     = implode('', $parts);
        $totalLen = 4 + strlen($body) + 1;

        return pack('V', $totalLen) . $body . "\x00";
    }

    private static function encodeArrayImplode(array $arr): string
    {
        $parts = [];
        foreach ($arr as $index => $value) {
            $parts[] = self::encodeElement((string) $index, $value);
        }

        $body     = implode('', $parts);
        $totalLen = 4 + strlen($body) + 1;

        return pack('V', $totalLen) . $body . "\x00";
    }

    /**
     * Simplified element encoder (string values only, for benchmark isolation).
     * Mirrors BSON string element: type(0x02) + ckey + int32_len + value + NUL
     */
    private static function encodeElement(string $key, string $value): string
    {
        $encoded = pack('V', strlen($value) + 1) . $value . "\x00";

        return chr(0x02) . $key . "\x00" . $encoded;
    }

    // ------------------------------------------------------------------
    // Isolated micro-benchmarks (no BsonEncoder overhead — pure string ops)
    // ------------------------------------------------------------------

    #[Groups(['micro_concat'])]
    #[ParamProviders('provideDocuments')]
    public function benchMicroConcat(array $params): void
    {
        self::encodeDocumentConcat($params['doc']);
    }

    #[Groups(['micro_implode'])]
    #[ParamProviders('provideDocuments')]
    public function benchMicroImplode(array $params): void
    {
        self::encodeDocumentImplode($params['doc']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Build an associative array with $n string fields (realistic BSON document). */
    private function makeDoc(int $n): array
    {
        $doc = [];
        for ($i = 0; $i < $n; $i++) {
            $doc["field_{$i}"] = "value_{$i}";
        }

        return $doc;
    }

    /** Build a sequential array with $n string values. */
    private function makeArr(int $n): array
    {
        return array_fill(0, $n, 'item_value');
    }
}
