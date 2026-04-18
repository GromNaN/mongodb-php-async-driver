<?php

declare(strict_types=1);

namespace MongoDB\Tests\BSON;

use MongoDB\BSON\Document;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_column;
use function array_combine;
use function array_filter;
use function array_intersect_key;
use function array_keys;
use function array_map;
use function array_merge;
use function basename;
use function dirname;
use function file_get_contents;
use function glob;
use function hex2bin;
use function in_array;
use function json_decode;
use function json_encode;
use function preg_replace_callback;
use function str_contains;

use const JSON_THROW_ON_ERROR;

final class CorpusTest extends TestCase
{
    private static array $tests = [];
    private static array $skippedTests = ['Double type (double.json)/-0.0' => 'PHP cannot represent negative zero'];

    public function setUp(): void
    {
        parent::setUp();

        if (! isset(self::$skippedTests[$this->dataName()])) {
            return;
        }

        $this->markTestSkipped(self::$skippedTests[$this->dataName()]);
    }

    #[DataProvider('provideValidTests')]
    public function testCanonicalBsonToCanonicalExtendedJson(
        string $canonicalBson,
        string $canonicalExtJson,
        string $relaxedExtJson,
        string $degenerateBson,
        string $degenerateExtJson,
        string $convertedBson,
        string $convertedExtJson,
        bool $lossy,
    ): void {
        $document = Document::fromBSON(hex2bin($canonicalBson));
        self::assertSame(hex2bin($canonicalBson), (string) $document);

        self::assertSame(
            $this->canonicalizeJson($canonicalExtJson),
            $this->canonicalizeJson($document->toCanonicalExtendedJSON()),
        );
    }

    #[DataProvider('provideValidTestsWithRelaxedExtendedJson')]
    public function testCanonicalBsonToRelaxedExtendedJson(
        string $canonicalBson,
        string $canonicalExtJson,
        string $relaxedExtJson,
        string $degenerateBson,
        string $degenerateExtJson,
        string $convertedBson,
        string $convertedExtJson,
        bool $lossy,
    ): void {
        $document = Document::fromBSON(hex2bin($canonicalBson));
        self::assertSame(hex2bin($canonicalBson), (string) $document);

        self::assertSame(
            $this->canonicalizeJson($relaxedExtJson),
            $this->canonicalizeJson($document->toRelaxedExtendedJSON()),
        );
    }

    #[DataProvider('provideDegenerateBsonTests')]
    public function testDegenerateBsonToCanonicalExtendedJson(
        string $canonicalBson,
        string $canonicalExtJson,
        string $relaxedExtJson,
        string $degenerateBson,
        string $degenerateExtJson,
        string $convertedBson,
        string $convertedExtJson,
        bool $lossy,
    ): void {
        $document = Document::fromBSON(hex2bin($degenerateBson));

        self::assertSame(
            $this->canonicalizeJson($canonicalExtJson),
            $this->canonicalizeJson($document->toCanonicalExtendedJSON()),
        );
    }

    #[DataProvider('provideValidTests')]
    public function testCanonicalExtendedJsonToCanonicalBson(
        string $canonicalBson,
        string $canonicalExtJson,
        string $relaxedExtJson,
        string $degenerateBson,
        string $degenerateExtJson,
        string $convertedBson,
        string $convertedExtJson,
        bool $lossy,
    ): void {
        if ($lossy) {
            $this->markTestSkipped('Lossy conversion cannot round-trip through extended JSON');
        }

        if (str_contains($canonicalExtJson, '\u0000')) {
            $this->markTestSkipped('Extension fromJSON uses C strings and truncates at embedded null bytes');
        }

        $document = Document::fromJSON($canonicalExtJson);
        self::assertSame(hex2bin($canonicalBson), (string) $document);
    }

    #[DataProvider('provideValidTestsWithDegenerateExtJson')]
    public function testDegenerateExtendedJsonToCanonicalBson(
        string $canonicalBson,
        string $canonicalExtJson,
        string $relaxedExtJson,
        string $degenerateBson,
        string $degenerateExtJson,
        string $convertedBson,
        string $convertedExtJson,
        bool $lossy,
    ): void {
        if ($lossy) {
            $this->markTestSkipped('Lossy conversion cannot round-trip through extended JSON');
        }

        $document = Document::fromJSON($degenerateExtJson);
        self::assertSame(hex2bin($canonicalBson), (string) $document);
    }

    #[DataProvider('provideValidTestsWithRelaxedExtendedJson')]
    public function testRelaxedExtendedJsonRoundTripping(
        string $canonicalBson,
        string $canonicalExtJson,
        string $relaxedExtJson,
        string $degenerateBson,
        string $degenerateExtJson,
        string $convertedBson,
        string $convertedExtJson,
        bool $lossy,
    ): void {
        $document = Document::fromJSON($relaxedExtJson);

        self::assertSame(
            $this->canonicalizeJson($relaxedExtJson),
            $this->canonicalizeJson($document->toRelaxedExtendedJSON()),
        );
    }

    #[DataProvider('provideParseErrorTests')]
    public function testParseErrors(string $bsonType, string $string): void
    {
        if ($bsonType === '0x13') {
            $this->markTestSkipped('Decimal128 string validation is not yet implemented');
        }

        // The extension's fromJSON permissively accepts numeric $date values; a native
        // parser must reject them per the Extended JSON v2 spec.
        $permissiveDateCases = ['Top-level document validity (top.json)/Bad $date (number, not string or hash)'];
        if (in_array($this->dataName(), $permissiveDateCases, true)) {
            $this->markTestSkipped('Extension fromJSON accepts numeric $date values contrary to Extended JSON v2 spec');
        }

        $this->expectException(Throwable::class);
        Document::fromJSON($string);
    }

    #[DataProvider('provideDecodeErrorTests')]
    public function testDecodeErrors(string $bson): void
    {
        $this->expectException(Throwable::class);
        Document::fromBSON($bson);
    }

    public static function provideDegenerateBsonTests(): iterable
    {
        return array_filter(
            self::provideValidTests(),
            static fn (array $test): bool => $test['degenerateBson'] !== '',
        );
    }

    public static function provideValidTestsWithDegenerateExtJson(): iterable
    {
        return array_filter(
            self::provideValidTests(),
            static fn (array $test): bool => $test['degenerateExtJson'] !== '',
        );
    }

    public static function provideParseErrorTests(): iterable
    {
        $tests = [];

        foreach (glob(dirname(__DIR__) . '/references/specifications/source/bson-corpus/tests/*.json') as $filename) {
            $basename  = basename($filename);
            $fileTests = self::readTestFile($filename);
            $group     = $fileTests['description'] . ' (' . $basename . ')';

            foreach ($fileTests['parseErrors'] ?? [] as $test) {
                $tests[$group . '/' . $test['description']] = [
                    'bsonType' => $fileTests['bson_type'],
                    'string'   => $test['string'],
                ];
            }
        }

        return $tests;
    }

    public static function provideValidTests(): iterable
    {
        $emptyTest = [
            'canonicalBson'    => '',
            'canonicalExtJson' => '',
            'relaxedExtJson'   => '',
            'degenerateBson'   => '',
            'degenerateExtJson' => '',
            'convertedBson'    => '',
            'convertedExtJson' => '',
            'lossy'            => false,
        ];

        return array_map(
            static fn (array $test) => array_intersect_key(
                array_merge($emptyTest, [
                    'canonicalBson'    => $test['canonical_bson'] ?? '',
                    'canonicalExtJson' => $test['canonical_extjson'] ?? '',
                    'relaxedExtJson'   => $test['relaxed_extjson'] ?? '',
                    'degenerateBson'   => $test['degenerate_bson'] ?? '',
                    'degenerateExtJson' => $test['degenerate_extjson'] ?? '',
                    'convertedBson'    => $test['converted_bson'] ?? '',
                    'convertedExtJson' => $test['converted_extjson'] ?? '',
                    'lossy'            => $test['lossy'] ?? false,
                ]),
                $emptyTest,
            ),
            self::provideTests('valid'),
        );
    }

    public static function provideValidTestsWithRelaxedExtendedJson(): iterable
    {
        return array_filter(
            self::provideValidTests(),
            static fn (array $test): bool => ($test['relaxedExtJson'] ?? '') !== '',
        );
    }

    public static function provideDecodeErrorTests(): iterable
    {
        $emptyTest = ['bson' => ''];

        return array_map(
            static fn (array $test) => array_intersect_key(array_merge($emptyTest, $test), $emptyTest),
            self::provideTests('decodeErrors'),
        );
    }

    private static function provideTests(string $key): array
    {
        $tests = [];

        foreach (glob(dirname(__DIR__) . '/references/specifications/source/bson-corpus/tests/*.json') as $filename) {
            $basename = basename($filename);

            $fileTests = self::readTestFile($filename);
            $group     = $fileTests['description'] . ' (' . $basename . ')';

            $groupTests = array_column($fileTests[$key] ?? [], null, 'description');
            $tests[]    = array_combine(
                array_map(
                    static fn (string $k) => $group . '/' . $k,
                    array_keys($groupTests),
                ),
                $groupTests,
            );
        }

        return array_merge(...$tests);
    }

    private static function readTestFile(string $filename): array
    {
        return static::$tests[$filename] ??= json_decode(file_get_contents($filename), true);
    }

    private function canonicalizeJson(string $json): string
    {
        $json = json_encode(json_decode($json, flags: JSON_THROW_ON_ERROR));

        /* Canonicalize string values for $numberDouble to ensure they are converted
         * the same as number literals in legacy and relaxed output. This is needed
         * because the printf format in _bson_as_json_visit_double uses a high level
         * of precision and may not produce the exponent notation expected by the
         * BSON corpus tests. */
        $json = preg_replace_callback(
            '/{"\$numberDouble":"(-?\d+(\.\d+([eE]\+\d+)?)?)"}/',
            static fn ($matches) => '{"$numberDouble":"' . json_encode(json_decode($matches[1])) . '"}',
            $json,
        );

        return $json;
    }
}
