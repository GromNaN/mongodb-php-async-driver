<?php

declare(strict_types=1);

namespace MongoDB\Tests\BSON;

use MongoDB\BSON\Binary;
use MongoDB\BSON\Document;
use MongoDB\BSON\Int64;
use MongoDB\BSON\MaxKey;
use MongoDB\BSON\MinKey;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Internal\BSON\BsonDecoder;
use MongoDB\Internal\BSON\BsonEncoder;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

use function pack;

use const PHP_INT_MAX;

class BsonEncoderDecoderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Encode $doc then decode it back to an array.
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
        // 2147483648 is larger than INT32_MAX so it must be encoded as BSON int64.
        // On 64-bit PHP (matching ext-mongodb), it decodes back as a native PHP int.
        $doc    = ['n' => 2147483648];
        $result = $this->roundTrip($doc);

        $this->assertSame(2147483648, $result['n']);
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

        // On 64-bit PHP, BSON int64 decodes as native PHP int (matching ext-mongodb)
        $this->assertSame(PHP_INT_MAX, $result['n']);
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

    public function testDecodeStdClassAliasInDocumentTypeMap(): void
    {
        // 'stdClass' is a valid alias for 'object' in the document typeMap key
        $bson   = BsonEncoder::encode(['sub' => ['x' => 1]]);
        $result = BsonDecoder::decode($bson, ['root' => 'array', 'document' => stdClass::class]);

        $this->assertIsArray($result);
        $this->assertInstanceOf(stdClass::class, $result['sub']);
        $this->assertSame(1, $result['sub']->x);
    }

    // -------------------------------------------------------------------------
    // Bounds-check: truncated BSON fields
    // -------------------------------------------------------------------------

    /**
     * A BSON document where the string length prefix claims more bytes than the
     * document actually contains must throw, not silently return truncated data.
     */
    public function testDecodeThrowsOnTruncatedString(): void
    {
        // {"s": <string claiming 200 bytes>}, total doc = 12 bytes
        // offset layout: [4 doc-len][1 type=0x02][2 key "s\0"][4 str-len=200][1 doc-term]
        $bson = pack('V', 12) . "\x02s\x00" . pack('V', 200) . "\x00";

        $this->expectException(RuntimeException::class);
        BsonDecoder::decode($bson, ['root' => 'array']);
    }

    /**
     * A BSON document where the binary data length prefix claims more bytes
     * than the document actually contains must throw.
     */
    public function testDecodeThrowsOnTruncatedBinary(): void
    {
        // {"b": <binary, subtype 0, claiming 200 bytes>}, total doc = 13 bytes
        // offset layout: [4 doc-len][1 type=0x05][2 key "b\0"][4 bin-len=200][1 subtype=0][1 doc-term]
        $bson = pack('V', 13) . "\x05b\x00" . pack('V', 200) . "\x00\x00";

        $this->expectException(RuntimeException::class);
        BsonDecoder::decode($bson, ['root' => 'array']);
    }

    /**
     * A CodeWithScope field where there are fewer than 4 bytes remaining for
     * the scope document length must throw when the field value is accessed.
     *
     * Layout (21 bytes):
     *   [4 doc-len=21][1 type=0x0F][3 key "js\0"]
     *   [4 cws-outer=12][4 code-len=4][4 code="var\0"]
     *   [1 doc-term]   ← only 1 byte left, need 4 for scope length
     */
    public function testDecodeThrowsOnCodeWithScopeMissingScopeLength(): void
    {
        $bson = pack('V', 21)    // doc total
            . "\x0Fjs\x00"       // type + key
            . pack('V', 12)      // cws outer int32 (4 outer + 4 code-len + 4 code-bytes)
            . pack('V', 4)       // code string length (4 = "var\0")
            . "var\x00"          // code string
            . "\x00";            // doc terminator

        $doc = Document::fromBSON($bson);

        $this->expectException(RuntimeException::class);
        $doc->get('js');
    }

    /**
     * A CodeWithScope field where the scope document length prefix claims more
     * bytes than the document actually contains must throw.
     *
     * Layout (30 bytes):
     *   [4 doc-len=30][1 type=0x0F][3 key "js\0"]
     *   [4 cws-outer=21][4 code-len=4][4 code="var\0"]
     *   [4 scope-len=100][5 scope-data (only 5 bytes, not 100)]
     *   [1 doc-term]
     */
    public function testDecodeThrowsOnCodeWithScopeOversizedScope(): void
    {
        $bson = pack('V', 30)         // doc total
            . "\x0Fjs\x00"            // type + key
            . pack('V', 21)           // cws outer int32
            . pack('V', 4)            // code string length
            . "var\x00"               // code string
            . pack('V', 100)          // scope length claiming 100 bytes
            . "\x05\x00\x00\x00\x00" // only 5 bytes of scope data
            . "\x00";                 // doc terminator

        $doc = Document::fromBSON($bson);

        $this->expectException(RuntimeException::class);
        $doc->get('js');
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
