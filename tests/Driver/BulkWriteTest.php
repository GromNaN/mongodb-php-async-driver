<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Serializable as BsonSerializable;
use MongoDB\Driver\BulkWrite;
use PHPUnit\Framework\TestCase;
use stdClass;

class BulkWriteTest extends TestCase
{
    public function testInsert(): void
    {
        $bw  = new BulkWrite();
        $id  = $bw->insert(['key' => 'val']);

        $this->assertNotNull($id);
    }

    public function testInsertGeneratesObjectId(): void
    {
        $bw = new BulkWrite();
        $id = $bw->insert(['key' => 'val']);

        $this->assertInstanceOf(ObjectId::class, $id);
    }

    public function testUpdate(): void
    {
        $bw = new BulkWrite();
        $bw->update(['x' => 1], ['$set' => ['x' => 2]]);

        $ops = $bw->getOperations();
        $this->assertCount(1, $ops);
        $this->assertSame('update', $ops[0][0]);
    }

    public function testDelete(): void
    {
        $bw = new BulkWrite();
        $bw->delete(['x' => 1]);

        $ops = $bw->getOperations();
        $this->assertCount(1, $ops);
        $this->assertSame('delete', $ops[0][0]);
    }

    public function testCount(): void
    {
        $bw = new BulkWrite();
        $bw->insert(['a' => 1]);
        $bw->insert(['b' => 2]);
        $bw->delete(['a' => 1]);

        $this->assertCount(3, $bw);
        $this->assertSame(3, $bw->count());
    }

    public function testOrdered(): void
    {
        $bw = new BulkWrite();

        $options = $bw->getOptions();
        $this->assertTrue($options['ordered']);
    }

    // -------------------------------------------------------------------------
    // _id injection: returned ID must match the document stored for encoding
    // -------------------------------------------------------------------------

    public function testInsertArrayWithoutIdInjectsId(): void
    {
        $bw  = new BulkWrite();
        $id  = $bw->insert(['x' => 1]);
        $doc = $bw->getOperations()[0][1];

        $this->assertIsArray($doc);
        $this->assertArrayHasKey('_id', $doc);
        $this->assertSame((string) $id, (string) $doc['_id']);
    }

    public function testInsertStdClassWithoutIdInjectsId(): void
    {
        $doc       = new stdClass();
        $doc->x    = 1;
        $bw        = new BulkWrite();
        $id        = $bw->insert($doc);
        $storedDoc = $bw->getOperations()[0][1];

        $this->assertInstanceOf(stdClass::class, $storedDoc);
        $this->assertObjectHasProperty('_id', $storedDoc);
        $this->assertSame((string) $id, (string) $storedDoc->_id);
    }

    public function testInsertGenericObjectWithoutIdConvertsToArrayWithId(): void
    {
        // Plain PHP class (not stdClass) — cannot safely add dynamic properties.
        $doc    = new class {
            public int $x = 1;
        };
        $bw     = new BulkWrite();
        $id     = $bw->insert($doc);
        $stored = $bw->getOperations()[0][1];

        $this->assertIsArray($stored);
        $this->assertArrayHasKey('_id', $stored);
        $this->assertSame((string) $id, (string) $stored['_id']);
        $this->assertSame(1, $stored['x']);
    }

    public function testInsertSerializableWithoutIdInjectsId(): void
    {
        $serializable = new class implements BsonSerializable {
            public function bsonSerialize(): array|stdClass
            {
                return ['x' => 42];
            }
        };

        $bw     = new BulkWrite();
        $id     = $bw->insert($serializable);
        $stored = $bw->getOperations()[0][1];

        $this->assertIsArray($stored);
        $this->assertArrayHasKey('_id', $stored);
        $this->assertSame((string) $id, (string) $stored['_id']);
        $this->assertSame(42, $stored['x']);
    }

    public function testInsertArrayWithExistingIdPreservesIt(): void
    {
        $existingId = new ObjectId();
        $bw         = new BulkWrite();
        $id         = $bw->insert(['_id' => $existingId, 'x' => 1]);
        $stored     = $bw->getOperations()[0][1];

        $this->assertSame((string) $existingId, (string) $id);
        $this->assertSame((string) $existingId, (string) $stored['_id']);
    }
}
