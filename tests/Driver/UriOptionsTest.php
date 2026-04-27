<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver;

use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Exception\UnexpectedValueException;
use MongoDB\Internal\Uri\UriOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function restore_error_handler;
use function set_error_handler;
use function sprintf;

use const E_USER_WARNING;

/**
 * Comprehensive unit tests for UriOptions::fromArray().
 *
 * Each group mirrors the validation section in fromArray() and is named after
 * the option category, so failures are easy to locate.
 */
class UriOptionsTest extends TestCase
{
    // =========================================================================
    // Defaults
    // =========================================================================

    public function testDefaultsAreAppliedForEmptyArray(): void
    {
        $opts = UriOptions::fromArray([]);

        self::assertSame(30000, $opts->serverSelectionTimeoutMS);
        self::assertSame(15, $opts->localThresholdMS);
        self::assertSame(10000, $opts->heartbeatFrequencyMS);
        self::assertSame(500, $opts->minHeartbeatFrequencyMS);
        self::assertSame(100, $opts->maxPoolSize);
        self::assertSame(0, $opts->minPoolSize);
        self::assertSame(2, $opts->maxConnecting);
        self::assertSame(0, $opts->waitQueueTimeoutMS);

        self::assertTrue($opts->retryWrites);
        self::assertTrue($opts->retryReads);
        self::assertFalse($opts->loadBalanced);
        self::assertFalse($opts->directConnection);

        self::assertNull($opts->timeoutMS);
        self::assertSame([], $opts->readPreferenceTags);
        self::assertSame([], $opts->compressors);
        self::assertSame([], $opts->authMechanismProperties);
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $opts = UriOptions::fromArray([
            '__srv'        => true,
            'unknownKey'   => 'value',
            'anotherUnknown' => 42,
        ]);

        // No exception, defaults still applied.
        self::assertSame(30000, $opts->serverSelectionTimeoutMS);
    }

    // =========================================================================
    // String options
    // =========================================================================

    #[DataProvider('provideStringOptions')]
    public function testStringOptionIsAccepted(string $key, string $value): void
    {
        if ($key === 'replicaSet') {
            $value = 'rs0'; // never empty
        }

        $opts = UriOptions::fromArray([$key => $value]);

        self::assertSame($value, $opts->$key);
    }

    public static function provideStringOptions(): array
    {
        return [
            'replicaSet'           => ['replicaSet', 'rs0'],
            'authMechanism'        => ['authMechanism', 'SCRAM-SHA-256'],
            'authSource'           => ['authSource', 'admin'],
            'readPreference'       => ['readPreference', 'secondary'],
            'tlsCAFile'            => ['tlsCAFile', '/path/to/ca.pem'],
            'tlsCertificateKeyFile' => ['tlsCertificateKeyFile', '/path/to/cert.pem'],
        ];
    }

    #[DataProvider('provideStringOptionInvalidTypes')]
    public function testStringOptionRejectsNonString(string $key, mixed $value, string $typeName): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Expected string for "%s" URI option, %s given', $key, $typeName));

        UriOptions::fromArray([$key => $value]);
    }

    public static function provideStringOptionInvalidTypes(): array
    {
        return [
            'replicaSet int'    => ['replicaSet',    42,          '32-bit integer'],
            'replicaSet bool'   => ['replicaSet',    true,        'boolean'],
            'replicaSet float'  => ['replicaSet',    1.5,         'double'],
            'replicaSet array'  => ['replicaSet',    [],          'array'],
            'replicaSet object' => ['replicaSet',    new stdClass(), 'stdClass'],
            'authSource int'    => ['authSource',    0,           '32-bit integer'],
            'authMechanism bool' => ['authMechanism', false,      'boolean'],
        ];
    }

    public function testReplicaSetEmptyStringIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value for URI option "replicaSet" cannot be empty string.');

        UriOptions::fromArray(['replicaSet' => '']);
    }

    // =========================================================================
    // Integer options with defaults
    // =========================================================================

    #[DataProvider('provideIntDefaultOptions')]
    public function testIntDefaultOptionAcceptsValidValue(string $key, int $value): void
    {
        $opts = UriOptions::fromArray([$key => $value]);

        self::assertSame($value, $opts->$key);
    }

    public static function provideIntDefaultOptions(): array
    {
        return [
            'serverSelectionTimeoutMS zero'  => ['serverSelectionTimeoutMS', 0],
            'serverSelectionTimeoutMS value' => ['serverSelectionTimeoutMS', 5000],
            'localThresholdMS'               => ['localThresholdMS', 50],
            'heartbeatFrequencyMS'           => ['heartbeatFrequencyMS', 20000],
            'minHeartbeatFrequencyMS'        => ['minHeartbeatFrequencyMS', 100],
            'maxPoolSize zero'               => ['maxPoolSize', 0],
            'maxPoolSize value'              => ['maxPoolSize', 50],
            'minPoolSize'                    => ['minPoolSize', 5],
            'maxConnecting'                  => ['maxConnecting', 4],
            'waitQueueTimeoutMS'             => ['waitQueueTimeoutMS', 1000],
        ];
    }

    #[DataProvider('provideIntDefaultOptionsInvalidType')]
    public function testIntDefaultOptionRejectsNonInt(string $key, mixed $value, string $typeName): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            sprintf('Expected 32-bit integer for "%s" URI option, %s given', $key, $typeName),
        );

        UriOptions::fromArray([$key => $value]);
    }

    public static function provideIntDefaultOptionsInvalidType(): array
    {
        return [
            'serverSelectionTimeoutMS string' => ['serverSelectionTimeoutMS', '5000', 'string'],
            'serverSelectionTimeoutMS float'  => ['serverSelectionTimeoutMS', 5.0,    'double'],
            'serverSelectionTimeoutMS bool'   => ['serverSelectionTimeoutMS', true,   'boolean'],
            'maxPoolSize string'              => ['maxPoolSize',              '100',  'string'],
            'localThresholdMS array'         => ['localThresholdMS',         [],     'array'],
        ];
    }

    #[DataProvider('provideIntDefaultOptionsNegative')]
    public function testIntDefaultOptionRejectsNegativeValue(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            sprintf('Expected 32-bit integer for "%s" URI option, negative number given', $key),
        );

        UriOptions::fromArray([$key => -1]);
    }

    public static function provideIntDefaultOptionsNegative(): array
    {
        return [
            'serverSelectionTimeoutMS' => ['serverSelectionTimeoutMS'],
            'localThresholdMS'        => ['localThresholdMS'],
            'maxPoolSize'             => ['maxPoolSize'],
            'minPoolSize'             => ['minPoolSize'],
        ];
    }

    // =========================================================================
    // Optional integer options
    // =========================================================================

    #[DataProvider('provideOptionalIntOptions')]
    public function testOptionalIntOptionAcceptsValidValue(string $key, int $value): void
    {
        $opts = UriOptions::fromArray([$key => $value]);

        self::assertSame($value, $opts->$key);
    }

    public static function provideOptionalIntOptions(): array
    {
        return [
            'connectTimeoutMS'      => ['connectTimeoutMS', 5000],
            'socketTimeoutMS'       => ['socketTimeoutMS', 10000],
            'maxIdleTimeMS'         => ['maxIdleTimeMS', 60000],
            'srvMaxHosts'           => ['srvMaxHosts', 3],
            'zlibCompressionLevel'  => ['zlibCompressionLevel', 6],
            'connectTimeoutMS zero' => ['connectTimeoutMS', 0],
        ];
    }

    /**
     * socketCheckIntervalMS is validated but not stored (no declared property);
     * just verify no exception is thrown.
     */
    public function testSocketCheckIntervalMSIsAccepted(): void
    {
        UriOptions::fromArray(['socketCheckIntervalMS' => 500]);
        self::assertTrue(true); // reached without exception
    }

    public function testOptionalIntOptionNotSetLeavesPropertyUninitialized(): void
    {
        $opts = UriOptions::fromArray([]);

        self::assertFalse(isset($opts->connectTimeoutMS));
        self::assertFalse(isset($opts->socketTimeoutMS));
        self::assertFalse(isset($opts->maxIdleTimeMS));
        self::assertFalse(isset($opts->zlibCompressionLevel));
    }

    #[DataProvider('provideOptionalIntOptionsInvalidType')]
    public function testOptionalIntOptionRejectsNonInt(string $key, mixed $value, string $typeName): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            sprintf('Expected 32-bit integer for "%s" URI option, %s given', $key, $typeName),
        );

        UriOptions::fromArray([$key => $value]);
    }

    public static function provideOptionalIntOptionsInvalidType(): array
    {
        return [
            'connectTimeoutMS string' => ['connectTimeoutMS', '5000', 'string'],
            'connectTimeoutMS float'  => ['connectTimeoutMS', 5.0,    'double'],
            'maxIdleTimeMS bool'      => ['maxIdleTimeMS',    true,   'boolean'],
            'zlibCompressionLevel array' => ['zlibCompressionLevel', [], 'array'],
        ];
    }

    public function testOptionalIntOptionRejectsNegativeValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected 32-bit integer for "connectTimeoutMS" URI option, negative number given',
        );

        UriOptions::fromArray(['connectTimeoutMS' => -1]);
    }

    // =========================================================================
    // wTimeoutMS
    // =========================================================================

    public function testWTimeoutMSAcceptsZero(): void
    {
        $opts = UriOptions::fromArray(['wTimeoutMS' => 0]);

        self::assertSame(0, $opts->wTimeoutMS);
    }

    public function testWTimeoutMSAcceptsPositiveInt(): void
    {
        $opts = UriOptions::fromArray(['wTimeoutMS' => 5000]);

        self::assertSame(5000, $opts->wTimeoutMS);
    }

    public function testWTimeoutMSRejectsString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected integer for "wTimeoutMS" URI option, string given');

        UriOptions::fromArray(['wTimeoutMS' => '5000']);
    }

    public function testWTimeoutMSRejectsFloat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected integer for "wTimeoutMS" URI option, double given');

        UriOptions::fromArray(['wTimeoutMS' => 5.0]);
    }

    public function testWTimeoutMSRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected wtimeoutMS to be >= 0, -1 given');

        UriOptions::fromArray(['wTimeoutMS' => -1]);
    }

    public function testWTimeoutMSNotSetLeavesPropertyUninitialized(): void
    {
        $opts = UriOptions::fromArray([]);

        self::assertFalse(isset($opts->wTimeoutMS));
    }

    // =========================================================================
    // timeoutMS (nullable)
    // =========================================================================

    public function testTimeoutMSDefaultsToNull(): void
    {
        $opts = UriOptions::fromArray([]);

        self::assertNull($opts->timeoutMS);
    }

    public function testTimeoutMSNullExplicit(): void
    {
        $opts = UriOptions::fromArray(['timeoutMS' => null]);

        self::assertNull($opts->timeoutMS);
    }

    public function testTimeoutMSAcceptsValidValue(): void
    {
        $opts = UriOptions::fromArray(['timeoutMS' => 10000]);

        self::assertSame(10000, $opts->timeoutMS);
    }

    public function testTimeoutMSAcceptsZero(): void
    {
        $opts = UriOptions::fromArray(['timeoutMS' => 0]);

        self::assertSame(0, $opts->timeoutMS);
    }

    public function testTimeoutMSRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected 32-bit integer for "timeoutMS" URI option, negative number given',
        );

        UriOptions::fromArray(['timeoutMS' => -1]);
    }

    public function testTimeoutMSRejectsString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected 32-bit integer for "timeoutMS" URI option, string given');

        UriOptions::fromArray(['timeoutMS' => '5000']);
    }

    // =========================================================================
    // Boolean options with defaults
    // =========================================================================

    #[DataProvider('provideBoolDefaultOptions')]
    public function testBoolDefaultOptionAcceptsValidValue(string $key, bool $value): void
    {
        $opts = UriOptions::fromArray([$key => $value]);

        self::assertSame($value, $opts->$key);
    }

    public static function provideBoolDefaultOptions(): array
    {
        return [
            'retryWrites true'       => ['retryWrites', true],
            'retryWrites false'      => ['retryWrites', false],
            'retryReads true'        => ['retryReads', true],
            'retryReads false'       => ['retryReads', false],
            'loadBalanced true'      => ['loadBalanced', true],
            'loadBalanced false'     => ['loadBalanced', false],
            'directConnection true'  => ['directConnection', true],
            'directConnection false' => ['directConnection', false],
        ];
    }

    #[DataProvider('provideBoolDefaultOptionsInvalidType')]
    public function testBoolDefaultOptionRejectsNonBool(string $key, mixed $value, string $typeName): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            sprintf('Expected boolean for "%s" URI option, %s given', $key, $typeName),
        );

        UriOptions::fromArray([$key => $value]);
    }

    public static function provideBoolDefaultOptionsInvalidType(): array
    {
        return [
            'retryWrites int'    => ['retryWrites',      1,     '32-bit integer'],
            'retryWrites string' => ['retryWrites',      'true', 'string'],
            'retryWrites float'  => ['retryWrites',      1.0,   'double'],
            'retryReads int'     => ['retryReads',       0,     '32-bit integer'],
            'loadBalanced array' => ['loadBalanced',     [],    'array'],
        ];
    }

    // =========================================================================
    // Optional boolean options
    // =========================================================================

    #[DataProvider('provideOptionalBoolOptions')]
    public function testOptionalBoolOptionAcceptsValidValue(string $key, bool $value): void
    {
        $opts = UriOptions::fromArray([$key => $value]);

        self::assertSame($value, $opts->$key);
    }

    public static function provideOptionalBoolOptions(): array
    {
        return [
            'ssl true'                          => ['ssl', true],
            'ssl false'                         => ['ssl', false],
            'tls true'                          => ['tls', true],
            'tls false'                         => ['tls', false],
            'tlsAllowInvalidCertificates true'  => ['tlsAllowInvalidCertificates', true],
            'tlsAllowInvalidCertificates false' => ['tlsAllowInvalidCertificates', false],
            'tlsAllowInvalidHostnames true'     => ['tlsAllowInvalidHostnames', true],
            'journal true'                      => ['journal', true],
            'journal false'                     => ['journal', false],
        ];
    }

    public function testOptionalBoolOptionNotSetLeavesPropertyUninitialized(): void
    {
        $opts = UriOptions::fromArray([]);

        self::assertFalse(isset($opts->ssl));
        self::assertFalse(isset($opts->tls));
        self::assertFalse(isset($opts->tlsAllowInvalidCertificates));
        self::assertFalse(isset($opts->tlsAllowInvalidHostnames));
        self::assertFalse(isset($opts->journal));
    }

    #[DataProvider('provideOptionalBoolOptionsInvalidType')]
    public function testOptionalBoolOptionRejectsNonBool(string $key, mixed $value, string $typeName): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            sprintf('Expected boolean for "%s" URI option, %s given', $key, $typeName),
        );

        UriOptions::fromArray([$key => $value]);
    }

    public static function provideOptionalBoolOptionsInvalidType(): array
    {
        return [
            'ssl int'                    => ['ssl',  1,      '32-bit integer'],
            'ssl string'                 => ['ssl',  'true', 'string'],
            'tls float'                  => ['tls',  0.0,    'double'],
            'journal array'              => ['journal', [],  'array'],
            'journal object'             => ['journal', new stdClass(), 'stdClass'],
            'tlsAllowInvalidCertificates int' => ['tlsAllowInvalidCertificates', 0, '32-bit integer'],
        ];
    }

    // =========================================================================
    // w option
    // =========================================================================

    #[DataProvider('provideWValidValues')]
    public function testWAcceptsValidValue(int|string $value): void
    {
        $opts = UriOptions::fromArray(['w' => $value]);

        self::assertSame($value, $opts->w);
    }

    public static function provideWValidValues(): array
    {
        return [
            'w zero'         => [0],
            'w one'          => [1],
            'w max int32'    => [2147483647],
            'w majority'     => ['majority'],
            'w tag'          => ['dc:east'],
            'w empty string' => [''],
        ];
    }

    public function testWRejectsFloat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected 32-bit integer or string for "w" URI option, double given',
        );

        UriOptions::fromArray(['w' => 1.5]);
    }

    public function testWRejectsBool(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected 32-bit integer or string for "w" URI option, boolean given',
        );

        UriOptions::fromArray(['w' => true]);
    }

    public function testWRejectsArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected 32-bit integer or string for "w" URI option, array given',
        );

        UriOptions::fromArray(['w' => []]);
    }

    public function testWRejectsObject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected 32-bit integer or string for "w" URI option, stdClass given',
        );

        UriOptions::fromArray(['w' => new stdClass()]);
    }

    public function testWRejectsInt64Overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected 32-bit integer or string for "w" URI option, 64-bit integer given',
        );

        UriOptions::fromArray(['w' => 2147483648]);
    }

    public function testWRejectsNegativeInt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported w value: -1');

        UriOptions::fromArray(['w' => -1]);
    }

    public function testWNotSetLeavesPropertyUninitialized(): void
    {
        $opts = UriOptions::fromArray([]);

        self::assertFalse(isset($opts->w));
    }

    // =========================================================================
    // readPreferenceTags
    // =========================================================================

    public function testReadPreferenceTagsDefaultsToEmptyArray(): void
    {
        $opts = UriOptions::fromArray([]);

        self::assertSame([], $opts->readPreferenceTags);
    }

    public function testReadPreferenceTagsAcceptsArray(): void
    {
        $tags = [['dc' => 'east'], ['dc' => 'west']];
        $opts = UriOptions::fromArray(['readPreferenceTags' => $tags]);

        self::assertSame($tags, $opts->readPreferenceTags);
    }

    public function testReadPreferenceTagsAcceptsEmptyArray(): void
    {
        $opts = UriOptions::fromArray(['readPreferenceTags' => []]);

        self::assertSame([], $opts->readPreferenceTags);
    }

    #[DataProvider('provideReadPreferenceTagsInvalidType')]
    public function testReadPreferenceTagsRejectsNonArray(mixed $value, string $typeName): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            sprintf('Expected array for "readPreferenceTags" URI option, %s given', $typeName),
        );

        UriOptions::fromArray(['readPreferenceTags' => $value]);
    }

    public static function provideReadPreferenceTagsInvalidType(): array
    {
        return [
            'string' => ['dc:east', 'string'],
            'int'    => [1,         '32-bit integer'],
            'bool'   => [true,      'boolean'],
            'object' => [new stdClass(), 'stdClass'],
        ];
    }

    // =========================================================================
    // compressors
    // =========================================================================

    public function testCompressorsDefaultsToEmptyArray(): void
    {
        $opts = UriOptions::fromArray([]);

        self::assertSame([], $opts->compressors);
    }

    #[DataProvider('provideCompressorsValidStrings')]
    public function testCompressorsAcceptsValidString(string $input, array $expected): void
    {
        $opts = UriOptions::fromArray(['compressors' => $input]);

        self::assertSame($expected, $opts->compressors);
    }

    public static function provideCompressorsValidStrings(): array
    {
        return [
            'empty string'        => ['', []],
            'single zlib'         => ['zlib', ['zlib']],
            'single snappy'       => ['snappy', ['snappy']],
            'single zstd'         => ['zstd', ['zstd']],
            'multiple'            => ['snappy,zlib', ['snappy', 'zlib']],
            'all three'           => ['snappy,zlib,zstd', ['snappy', 'zlib', 'zstd']],
            'with spaces'         => [' zlib , snappy ', ['zlib', 'snappy']],
        ];
    }

    public function testCompressorsIgnoresUnknownWithWarning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        }, E_USER_WARNING);

        try {
            $opts = UriOptions::fromArray(['compressors' => 'zlib,foo,snappy']);
        } finally {
            restore_error_handler();
        }

        self::assertSame(['zlib', 'snappy'], $opts->compressors);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('foo', $warnings[0]);
    }

    #[DataProvider('provideCompressorsInvalidType')]
    public function testCompressorsRejectsNonString(mixed $value, string $typeName): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            sprintf('Expected string for "compressors" URI option, %s given', $typeName),
        );

        UriOptions::fromArray(['compressors' => $value]);
    }

    public static function provideCompressorsInvalidType(): array
    {
        return [
            'int'    => [1,              '32-bit integer'],
            'bool'   => [true,           'boolean'],
            'array'  => [['zlib'],       'array'],
            'object' => [new stdClass(), 'stdClass'],
        ];
    }

    // =========================================================================
    // authMechanismProperties
    // =========================================================================

    public function testAuthMechanismPropertiesDefaultsToEmptyArray(): void
    {
        $opts = UriOptions::fromArray([]);

        self::assertSame([], $opts->authMechanismProperties);
    }

    public function testAuthMechanismPropertiesAcceptsAssocArray(): void
    {
        $props = ['SERVICE_NAME' => 'mongodb', 'CANONICALIZE_HOST_NAME' => 'false'];
        $opts  = UriOptions::fromArray(['authMechanismProperties' => $props]);

        self::assertSame($props, $opts->authMechanismProperties);
    }

    #[DataProvider('provideAuthMechanismPropertiesInvalidValue')]
    public function testAuthMechanismPropertiesRejectsInvalidValue(mixed $value, string $typeName): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Expected array or object for "authMechanismProperties" URI option, %s given',
                $typeName,
            ),
        );

        UriOptions::fromArray(['authMechanismProperties' => $value]);
    }

    public static function provideAuthMechanismPropertiesInvalidValue(): array
    {
        return [
            'empty list array'     => [[], 'array'],
            'list array'           => [['value'], 'array'],
            'string'               => ['SERVICE_NAME:mongodb', 'string'],
            'int'                  => [1, '32-bit integer'],
            'bool'                 => [true, 'boolean'],
            'stdClass'             => [new stdClass(), 'stdClass'],
        ];
    }

    // =========================================================================
    // compressors UTF-8
    // =========================================================================

    public function testCompressorsRejectsInvalidUtf8(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Detected invalid UTF-8 for field path "compressors"');

        UriOptions::fromArray(['compressors' => "\xFF\xFE"]);
    }

    /**
     * Unit tests for UriOptions::phpTypeName().
     *
     * The method must produce type names that match the error messages emitted by
     * ext-mongodb, so each case is tied to a specific ext-mongodb behaviour.
     */
    #[DataProvider('provideScalarTypes')]
    public function testScalarTypes(mixed $value, string $expected): void
    {
        self::assertSame($expected, UriOptions::phpTypeName($value));
    }

    public static function provideScalarTypes(): array
    {
        return [
            'null'   => [null,  'null'],
            'true'   => [true,  'boolean'],
            'false'  => [false, 'boolean'],
            'int'    => [42,    '32-bit integer'],
            'float'  => [3.14,  'double'],
            'string' => ['foo', 'string'],
        ];
    }

    #[DataProvider('provideArrayTypes')]
    public function testArrayTypes(mixed $value, string $expected): void
    {
        self::assertSame($expected, UriOptions::phpTypeName($value));
    }

    public static function provideArrayTypes(): array
    {
        return [
            'empty array (list)'      => [[], 'array'],
            'list array'              => [[1, 2, 3], 'array'],
            'assoc array (document)'  => [['a' => 1], 'document'],
            'mixed keys → list test'  => [['x', 'y'], 'array'],
        ];
    }

    #[DataProvider('provideObjectTypes')]
    public function testObjectTypes(mixed $value, string $expected): void
    {
        self::assertSame($expected, UriOptions::phpTypeName($value));
    }

    public static function provideObjectTypes(): array
    {
        return [
            'stdClass'    => [new stdClass(),    'stdClass'],
            'ObjectId'    => [new ObjectId(),    'ObjectId'],
            'Binary'      => [new Binary('x'),   'Binary'],
            'Regex'       => [new Regex('a'),    'Regex'],
            'UTCDateTime' => [new UTCDateTime(),  'UTCDateTime'],
            'Int64'       => [new Int64(1),      'Int64'],
            'MaxKey'      => [new MaxKey(),      'MaxKey'],
            'MinKey'      => [new MinKey(),      'MinKey'],
            'Document'    => [Document::fromPHP([]), 'Document'],
        ];
    }
}
