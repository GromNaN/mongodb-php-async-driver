<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver;

use MongoDB\BSON\Binary;
use MongoDB\BSON\Document;
use MongoDB\BSON\Int64;
use MongoDB\BSON\MaxKey;
use MongoDB\BSON\MinKey;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Internal\Uri\UriOptions;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests for UriOptions::phpTypeName().
 *
 * The method must produce type names that match the error messages emitted by
 * ext-mongodb, so each case is tied to a specific ext-mongodb behaviour.
 */
class UriOptionsPhpTypeNameTest extends TestCase
{
    /** @dataProvider provideScalarTypes */
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

    /** @dataProvider provideArrayTypes */
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

    /** @dataProvider provideObjectTypes */
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
