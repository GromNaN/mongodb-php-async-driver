<?php

declare(strict_types=1);

namespace MongoDB\Tests\BSON;

use MongoDB\BSON\Binary;
use MongoDB\BSON\Document;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Persistable;
use MongoDB\BSON\Serializable as BsonSerializable;
use MongoDB\Internal\BSON\BsonDecoder;
use MongoDB\Internal\BSON\BsonEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for BsonEncoder::encodeDocumentWithId().
 *
 * Each case verifies:
 *  - the returned BSON decodes to a document containing _id
 *  - $outId matches the _id value in the decoded document
 *  - when the input already has _id, $outId equals it
 *  - when the input has no _id, $outId is a freshly generated ObjectId
 *  - Persistable inputs produce a __pclass field in the encoded document
 */
class BsonEncoderDocumentWithIdTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function decode(string $bson): array
    {
        return (array) BsonDecoder::decode($bson, ['root' => 'array', 'document' => 'array', 'array' => 'array']);
    }

    // -------------------------------------------------------------------------
    // DataProvider
    // -------------------------------------------------------------------------

    public static function provideInputsWithExistingId(): array
    {
        $oid = new ObjectId();

        $arr          = ['_id' => $oid, 'x' => 1];
        $obj          = new stdClass();
        $obj->_id     = $oid;
        $obj->x       = 1;

        $serializable = new class ($oid) implements BsonSerializable {
            public function __construct(private readonly ObjectId $id)
            {
            }

            public function bsonSerialize(): array
            {
                return ['_id' => $this->id, 'x' => 42];
            }
        };

        $persistable = new class ($oid) implements Persistable {
            public function __construct(private readonly ObjectId $id)
            {
            }

            public function bsonSerialize(): array
            {
                return ['_id' => $this->id, 'y' => 99];
            }

            public function bsonUnserialize(array $data): void
            {
            }
        };

        $bsonDoc = Document::fromPHP(['_id' => $oid, 'z' => 7]);

        return [
            'array with _id'                  => [$arr,          $oid, ['x' => 1],  false],
            'stdClass with _id'               => [$obj,          $oid, ['x' => 1],  false],
            'BsonSerializable with _id'       => [$serializable, $oid, ['x' => 42], false],
            'Persistable with _id'            => [$persistable,  $oid, ['y' => 99], true],
            'Document (BSON) with _id'        => [$bsonDoc,      $oid, ['z' => 7],  false],
        ];
    }

    public static function provideInputsWithoutId(): array
    {
        $obj    = new stdClass();
        $obj->x = 5;

        $serializable = new class implements BsonSerializable {
            public function bsonSerialize(): array
            {
                return ['a' => 1];
            }
        };

        $persistable = new class implements Persistable {
            public function bsonSerialize(): array
            {
                return ['b' => 2];
            }

            public function bsonUnserialize(array $data): void
            {
            }
        };

        $genericObj    = new class {
            public int $c = 3;
        };

        $bsonDocNoId = Document::fromPHP(['w' => 8]);

        return [
            'array without _id'              => [['x' => 10],   ['x' => 10],  false],
            'stdClass without _id'           => [$obj,           ['x' => 5],   false],
            'BsonSerializable without _id'   => [$serializable,  ['a' => 1],   false],
            'Persistable without _id'        => [$persistable,   ['b' => 2],   true],
            'generic object without _id'     => [$genericObj,    ['c' => 3],   false],
            'Document (BSON) without _id'    => [$bsonDocNoId,   ['w' => 8],   false],
        ];
    }

    // -------------------------------------------------------------------------
    // Tests: inputs that already have _id
    // -------------------------------------------------------------------------

    #[DataProvider('provideInputsWithExistingId')]
    public function testPreservesExistingId(
        array|object $input,
        ObjectId $existingId,
        array $expectedFields,
        bool $expectPclass,
    ): void {
        $outId = null;
        $bson  = BsonEncoder::encodeDocumentWithId($input, $outId);

        // $outId must equal the existing _id
        $this->assertSame((string) $existingId, (string) $outId);

        $decoded = $this->decode($bson);

        // _id in the decoded document must match
        $this->assertSame((string) $existingId, (string) $decoded['_id']);

        // Additional fields are present
        foreach ($expectedFields as $key => $value) {
            $this->assertArrayHasKey($key, $decoded);
            $this->assertSame($value, $decoded[$key]);
        }

        if (! $expectPclass) {
            return;
        }

        $this->assertArrayHasKey('__pclass', $decoded);
    }

    // -------------------------------------------------------------------------
    // Tests: inputs that have no _id → ObjectId is auto-generated
    // -------------------------------------------------------------------------

    #[DataProvider('provideInputsWithoutId')]
    public function testInjectsObjectIdWhenMissing(
        array|object $input,
        array $expectedFields,
        bool $expectPclass,
    ): void {
        $outId = null;
        $bson  = BsonEncoder::encodeDocumentWithId($input, $outId);

        // $outId must be a freshly generated ObjectId
        $this->assertInstanceOf(ObjectId::class, $outId);

        $decoded = $this->decode($bson);

        // _id in the decoded document must match $outId
        $this->assertSame((string) $outId, (string) $decoded['_id']);

        // Additional fields are present
        foreach ($expectedFields as $key => $value) {
            $this->assertArrayHasKey($key, $decoded);
            $this->assertSame($value, $decoded[$key]);
        }

        if (! $expectPclass) {
            return;
        }

        $this->assertArrayHasKey('__pclass', $decoded);
        $this->assertInstanceOf(Binary::class, $decoded['__pclass']);
        $this->assertSame(Binary::TYPE_USER_DEFINED, $decoded['__pclass']->getType());
    }

    // -------------------------------------------------------------------------
    // Additional edge-case tests
    // -------------------------------------------------------------------------

    public function testPersistableIdStoredInOutId(): void
    {
        // Persistable whose __pclass class name is the expected value in __pclass binary.
        $oid         = new ObjectId();
        $persistable = new class ($oid) implements Persistable {
            public function __construct(private readonly ObjectId $id)
            {
            }

            public function bsonSerialize(): array
            {
                return ['_id' => $this->id];
            }

            public function bsonUnserialize(array $data): void
            {
            }
        };

        $outId = null;
        $bson  = BsonEncoder::encodeDocumentWithId($persistable, $outId);

        $this->assertSame((string) $oid, (string) $outId);

        $decoded = $this->decode($bson);
        $this->assertArrayHasKey('__pclass', $decoded);
        $this->assertInstanceOf(Binary::class, $decoded['__pclass']);
        $this->assertSame($persistable::class, $decoded['__pclass']->getData());
    }

    public function testDocumentWithIdReturnsSameBsonBytes(): void
    {
        // When the input is a Document that already has _id, no re-encode should occur.
        // We verify the returned bytes equal the Document's own bytes.
        $oid = new ObjectId();
        $doc = Document::fromPHP(['_id' => $oid, 'k' => 'v']);

        $outId = null;
        $bson  = BsonEncoder::encodeDocumentWithId($doc, $outId);

        $this->assertSame((string) $doc, $bson);
        $this->assertSame((string) $oid, (string) $outId);
    }

    public function testNonStringIdIsPreserved(): void
    {
        // _id can be any BSON type, not just ObjectId.
        $bw = ['_id' => 42, 'x' => 'hello'];

        $outId = null;
        $bson  = BsonEncoder::encodeDocumentWithId($bw, $outId);

        $this->assertSame(42, $outId);

        $decoded = $this->decode($bson);
        $this->assertSame(42, $decoded['_id']);
    }
}
