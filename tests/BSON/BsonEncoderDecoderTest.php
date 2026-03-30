<?php

declare(strict_types=1);

namespace MongoDB\Tests\BSON;

use MongoDB\BSON\Binary;
use MongoDB\BSON\Int64;
use MongoDB\BSON\MaxKey;
use MongoDB\BSON\MinKey;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Internal\BSON\BsonDecoder;
use MongoDB\Internal\BSON\BsonEncoder;
use PHPUnit\Framework\TestCase;
use stdClass;

class BsonEncoderDecoderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Encode $doc then decode it back to an array.
     *
     * @param array|object $doc
     * @return array
     */
    private function roundTrip(array|object $doc): array
    {
        $bson   = BsonEncoder::encode($doc);
        $result = BsonDecoder::decode($bson, ['root' => 'array', 'document' => 'array', 'array' => 'array']);
        return (array) $result;
    }

    // -------------------------------------------------------------------------
    // Scalar types
    // -------------------------------------------------------------------------

    public function testEncodeDecodeString(): void
    {
        $doc    = ['key' => 'hello world'];
        $result = $this->roundTrip($doc);

        $this->assertSame('hello world', $result['key']);
    }

    public function testEncodeDecodeInt32(): void
    {
        $doc    = ['n' => 42];
        $result = $this->roundTrip($doc);

        $this->assertSame(42, $result['n']);
    }

    public function testEncodeDecodeInt64(): void
    {
        // 2147483648 is larger than INT32_MAX so it must be encoded as BSON int64,
        // which decodes back as an Int64 object to preserve type fidelity.
        $doc    = ['n' => 2147483648];
        $result = $this->roundTrip($doc);

        $this->assertInstanceOf(Int64::class, $result['n']);
        $this->assertSame('2147483648', (string) $result['n']);
    }

    public function testEncodeDecodeFloat(): void
    {
        $doc    = ['f' => 3.14];
        $result = $this->roundTrip($doc);

        $this->assertEqualsWithDelta(3.14, $result['f'], 1e-10);
    }

    public function testEncodeDecodeBool(): void
    {
        $doc    = ['t' => true, 'f' => false];
        $result = $this->roundTrip($doc);

        $this->assertTrue($result['t']);
        $this->assertFalse($result['f']);
    }

    public function testEncodeDecodeNull(): void
    {
        $doc    = ['n' => null];
        $result = $this->roundTrip($doc);

        $this->assertArrayHasKey('n', $result);
        $this->assertNull($result['n']);
    }

    // -------------------------------------------------------------------------
    // BSON extension types
    // -------------------------------------------------------------------------

    public function testEncodeDecodeObjectId(): void
    {
        $id     = new ObjectId();
        $doc    = ['id' => $id];
        $result = $this->roundTrip($doc);

        $this->assertInstanceOf(ObjectId::class, $result['id']);
        $this->assertSame((string) $id, (string) $result['id']);
    }

    public function testEncodeDecodeUTCDateTime(): void
    {
        $dt     = new UTCDateTime(1_000_000_000_000);
        $doc    = ['dt' => $dt];
        $result = $this->roundTrip($doc);

        $this->assertInstanceOf(UTCDateTime::class, $result['dt']);
        $this->assertSame((string) $dt, (string) $result['dt']);
    }

    public function testEncodeDecodeBinary(): void
    {
        $bin    = new Binary('hello', Binary::TYPE_GENERIC);
        $doc    = ['b' => $bin];
        $result = $this->roundTrip($doc);

        $this->assertInstanceOf(Binary::class, $result['b']);
        $this->assertSame('hello', $result['b']->getData());
        $this->assertSame(Binary::TYPE_GENERIC, $result['b']->getType());
    }

    public function testEncodeDecodeInt64Object(): void
    {
        $n      = new Int64(PHP_INT_MAX);
        $doc    = ['n' => $n];
        $result = $this->roundTrip($doc);

        // BSON int64 decodes as Int64 to preserve type fidelity
        $this->assertInstanceOf(Int64::class, $result['n']);
        $this->assertSame((string) PHP_INT_MAX, (string) $result['n']);
    }

    public function testEncodeDecodeRegex(): void
    {
        $regex  = new Regex('pattern', 'i');
        $doc    = ['r' => $regex];
        $result = $this->roundTrip($doc);

        $this->assertInstanceOf(Regex::class, $result['r']);
        $this->assertSame('pattern', $result['r']->getPattern());
        $this->assertSame('i', $result['r']->getFlags());
    }

    public function testEncodeDecodeMaxKey(): void
    {
        $doc    = ['mk' => new MaxKey()];
        $result = $this->roundTrip($doc);

        $this->assertInstanceOf(MaxKey::class, $result['mk']);
    }

    public function testEncodeDecodeMinKey(): void
    {
        $doc    = ['mk' => new MinKey()];
        $result = $this->roundTrip($doc);

        $this->assertInstanceOf(MinKey::class, $result['mk']);
    }

    // -------------------------------------------------------------------------
    // Compound types
    // -------------------------------------------------------------------------

    public function testEncodeDecodeNestedDocument(): void
    {
        $doc    = ['outer' => ['inner' => 'value']];
        $result = $this->roundTrip($doc);

        $this->assertIsArray($result['outer']);
        $this->assertSame('value', $result['outer']['inner']);
    }

    public function testEncodeDecodeArray(): void
    {
        $doc    = ['arr' => [1, 2, 3]];
        $result = $this->roundTrip($doc);

        $this->assertSame([1, 2, 3], $result['arr']);
    }

    // -------------------------------------------------------------------------
    // Type-map options
    // -------------------------------------------------------------------------

    public function testTypeMapArray(): void
    {
        $bson   = BsonEncoder::encode(['a' => 1]);
        $result = BsonDecoder::decode($bson, ['root' => 'array']);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['a']);
    }

    public function testTypeMapObject(): void
    {
        $bson   = BsonEncoder::encode(['a' => 1]);
        $result = BsonDecoder::decode($bson, ['root' => 'object']);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame(1, $result->a);
    }

    // -------------------------------------------------------------------------
    // Round-trip idempotency
    // -------------------------------------------------------------------------

    public function testRoundTrip(): void
    {
        $doc = [
            'string' => 'hello',
            'int'    => 42,
            'float'  => 1.5,
            'bool'   => true,
            'null'   => null,
            'nested' => ['x' => 1],
        ];

        $bytes1 = BsonEncoder::encode($doc);
        // Decode then re-encode; bytes must be identical
        $decoded = BsonDecoder::decode($bytes1, ['root' => 'array', 'document' => 'array', 'array' => 'array']);
        $bytes2  = BsonEncoder::encode($decoded);

        $this->assertSame($bytes1, $bytes2, 'Re-encoded BSON bytes must match original.');
    }
}
