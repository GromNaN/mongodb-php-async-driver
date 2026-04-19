<?php

declare(strict_types=1);

namespace MongoDB\Tests\BSON;

use MongoDB\Internal\BSON\TypeMapper;
use PHPUnit\Framework\TestCase;
use stdClass;

class TypeMapperTest extends TestCase
{
    // -------------------------------------------------------------------------
    // stdClass alias for 'object'
    // -------------------------------------------------------------------------

    public function testStdClassAliasProducesStdClass(): void
    {
        $input  = ['a' => 1, 'b' => 2];
        $result = TypeMapper::apply($input, ['root' => 'stdClass']);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame(1, $result->a);
        $this->assertSame(2, $result->b);
    }

    public function testStdClassAliasOnDocumentType(): void
    {
        $input  = ['nested' => ['x' => 42]];
        $result = TypeMapper::apply($input, ['root' => 'array', 'document' => 'stdClass']);

        $this->assertIsArray($result);
        $this->assertInstanceOf(stdClass::class, $result['nested']);
        $this->assertSame(42, $result['nested']->x);
    }

    // -------------------------------------------------------------------------
    // fieldPaths: direct key override
    // -------------------------------------------------------------------------

    public function testFieldPathOverridesDocumentType(): void
    {
        // root=array, document=object, but fieldPaths['nested']='array'
        $input  = ['nested' => ['x' => 1]];
        $result = TypeMapper::apply($input, [
            'root'       => 'array',
            'document'   => 'object',
            'fieldPaths' => ['nested' => 'array'],
        ]);

        $this->assertIsArray($result);
        $this->assertIsArray($result['nested']);
    }

    // -------------------------------------------------------------------------
    // fieldPaths: dot-notation propagation
    // -------------------------------------------------------------------------

    public function testFieldPathDotNotationApplied(): void
    {
        // nested.child should be 'array', other nested docs stay 'object'
        $input = [
            'nested' => [
                'child' => ['a' => 1],
                'other' => ['b' => 2],
            ],
        ];
        $result = TypeMapper::apply($input, [
            'root'       => 'array',
            'document'   => 'object',
            'fieldPaths' => ['nested.child' => 'array'],
        ]);

        $this->assertIsArray($result);
        $this->assertInstanceOf(stdClass::class, $result['nested']);
        $this->assertIsArray($result['nested']->child);
        $this->assertInstanceOf(stdClass::class, $result['nested']->other);
    }

    // -------------------------------------------------------------------------
    // fieldPaths: '$' wildcard for array elements
    // -------------------------------------------------------------------------

    public function testWildcardAppliesTypeToAllArrayElements(): void
    {
        // values.$ = 'object' means each element of 'values' array becomes stdClass
        $input = ['values' => [['x' => 1], ['x' => 2]]];

        $result = TypeMapper::apply($input, [
            'root'       => 'object',
            'document'   => 'array',
            'fieldPaths' => [
                'values'   => 'array',
                'values.$' => 'object',
            ],
        ]);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertIsArray($result->values);
        $this->assertInstanceOf(stdClass::class, $result->values[0]);
        $this->assertInstanceOf(stdClass::class, $result->values[1]);
        $this->assertSame(1, $result->values[0]->x);
    }

    public function testWildcardMixedArrayLeavesScalarsUntouched(): void
    {
        // Scalar elements (int 4) must pass through even when wildcard is 'object'
        $input = ['values' => [['x' => 1], 4, ['x' => 2]]];

        $result = TypeMapper::apply($input, [
            'root'       => 'object',
            'document'   => 'array',
            'fieldPaths' => [
                'values'   => 'array',
                'values.$' => 'object',
            ],
        ]);

        $this->assertInstanceOf(stdClass::class, $result->values[0]);
        $this->assertSame(4, $result->values[1]);
        $this->assertInstanceOf(stdClass::class, $result->values[2]);
    }
}
