<?php

declare(strict_types=1);

namespace MongoDB\Tests\BSON;

use MongoDB\BSON\Document;
use MongoDB\BSON\PackedArray;
use MongoDB\Driver\Exception\UnexpectedValueException;
use PHPUnit\Framework\TestCase;

class PackedArrayTest extends TestCase
{
    /** An empty JSON object {} must be rejected — it is not a JSON array. */
    public function testFromJSONRejectsEmptyObject(): void
    {
        $this->expectException(UnexpectedValueException::class);

        PackedArray::fromJSON('{}');
    }

    /** An empty JSON array [] is a valid PackedArray. */
    public function testFromJSONAcceptsEmptyArray(): void
    {
        $arr = PackedArray::fromJSON('[]');

        $this->assertSame([], $arr->toPHP());
    }

    /**
     * A nested empty JSON object {} inside the array must be encoded as an
     * empty BSON document, not as an empty BSON array.
     */
    public function testFromJSONNestedEmptyObjectBecomesDocument(): void
    {
        $arr = PackedArray::fromJSON('[{}]');

        $result = $arr->toPHP(['root' => 'array', 'document' => 'bson', 'array' => 'bson']);
        $this->assertInstanceOf(Document::class, $result[0]);
    }

    /**
     * A nested empty JSON array [] inside the array must be encoded as an
     * empty BSON array, not as an empty BSON document.
     */
    public function testFromJSONNestedEmptyArrayBecomesPackedArray(): void
    {
        $arr = PackedArray::fromJSON('[[]]');

        $result = $arr->toPHP(['root' => 'array', 'document' => 'bson', 'array' => 'bson']);
        $this->assertInstanceOf(PackedArray::class, $result[0]);
    }
}
