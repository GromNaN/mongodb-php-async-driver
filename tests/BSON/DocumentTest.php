<?php

declare(strict_types=1);

namespace MongoDB\Tests\BSON;

use MongoDB\BSON\Document;
use MongoDB\BSON\Int64;
use MongoDB\BSON\PackedArray;
use MongoDB\Driver\Exception\LogicException;
use MongoDB\Internal\BSON\BsonEncoder;
use PHPUnit\Framework\TestCase;

use function json_decode;

class DocumentTest extends TestCase
{
    public function testFromPHP(): void
    {
        $doc = Document::fromPHP(['key' => 'val']);

        $this->assertSame('val', $doc->get('key'));
    }

    public function testHas(): void
    {
        $doc = Document::fromPHP(['key' => 'val']);

        $this->assertTrue($doc->has('key'));
        $this->assertFalse($doc->has('missing'));
    }

    public function testGetIterator(): void
    {
        $doc      = Document::fromPHP(['a' => 1, 'b' => 2]);
        $keys     = [];
        $values   = [];

        foreach ($doc as $key => $value) {
            $keys[]   = $key;
            $values[] = $value;
        }

        $this->assertSame(['a', 'b'], $keys);
        $this->assertSame([1, 2], $values);
    }

    public function testToPHP(): void
    {
        $original = ['x' => 10, 'y' => 'hello'];
        $doc      = Document::fromPHP($original);
        $result   = $doc->toPHP(['root' => 'array']);

        $this->assertIsArray($result);
        $this->assertSame(10, $result['x']);
        $this->assertSame('hello', $result['y']);
    }

    public function testFromBSON(): void
    {
        $bson = BsonEncoder::encode(['field' => 'value', 'num' => 99]);
        $doc  = Document::fromBSON($bson);

        $this->assertTrue($doc->has('field'));
        $this->assertSame('value', $doc->get('field'));
        $this->assertSame(99, $doc->get('num'));
    }

    public function testToCanonicalExtendedJSON(): void
    {
        $doc  = Document::fromPHP(['n' => new Int64(42)]);
        $json = $doc->toCanonicalExtendedJSON();

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        // Int64(42) canonical form: {"$numberLong": "42"}
        $this->assertArrayHasKey('n', $decoded);
        $this->assertSame(['$numberLong' => '42'], $decoded['n']);
    }

    public function testOffsetExists(): void
    {
        $doc = Document::fromPHP(['key' => 'val']);

        $this->assertTrue(isset($doc['key']));
        $this->assertFalse(isset($doc['nonexistent']));
    }

    public function testOffsetSetThrows(): void
    {
        $this->expectException(LogicException::class);

        $doc         = Document::fromPHP(['key' => 'val']);
        $doc['key']  = 'new_value';
    }

    /**
     * A nested empty JSON array [] inside a document must be encoded as an
     * empty BSON array, not as an empty BSON document.
     */
    public function testFromJSONNestedEmptyArrayBecomesPackedArray(): void
    {
        $doc = Document::fromJSON('{"items":[]}');

        $result = $doc->toPHP(['root' => 'array', 'document' => 'bson', 'array' => 'bson']);
        $this->assertInstanceOf(PackedArray::class, $result['items']);
    }

    /**
     * A nested empty JSON object {} inside a document must be encoded as an
     * empty BSON document, not as an empty BSON array.
     */
    public function testFromJSONNestedEmptyObjectBecomesDocument(): void
    {
        $doc = Document::fromJSON('{"sub":{}}');

        $result = $doc->toPHP(['root' => 'array', 'document' => 'bson', 'array' => 'bson']);
        $this->assertInstanceOf(Document::class, $result['sub']);
    }
}
