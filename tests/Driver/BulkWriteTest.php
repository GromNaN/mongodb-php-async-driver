<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver;

use MongoDB\BSON\Document;
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

        $this->assertInstanceOf(Document::class, $doc);
        $this->assertTrue($doc->has('_id'));
        $this->assertSame((string) $id, (string) $doc->get('_id'));
    }

    public function testInsertStdClassWithoutIdInjectsId(): void
    {
        $input  = new stdClass();
        $input->x = 1;
        $bw     = new BulkWrite();
        $id     = $bw->insert($input);
        $stored = $bw->getOperations()[0][1];

        $this->assertInstanceOf(Document::class, $stored);
        $this->assertTrue($stored->has('_id'));
        $this->assertSame((string) $id, (string) $stored->get('_id'));
    }

    public function testInsertGenericObjectWithoutIdConvertsToArrayWithId(): void
    {
        $doc    = new class {
            public int $x = 1;
        };
        $bw     = new BulkWrite();
        $id     = $bw->insert($doc);
        $stored = $bw->getOperations()[0][1];

        $this->assertInstanceOf(Document::class, $stored);
        $this->assertTrue($stored->has('_id'));
        $this->assertSame((string) $id, (string) $stored->get('_id'));
        $this->assertSame(1, $stored->get('x'));
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

        $this->assertInstanceOf(Document::class, $stored);
        $this->assertTrue($stored->has('_id'));
        $this->assertSame((string) $id, (string) $stored->get('_id'));
        $this->assertSame(42, $stored->get('x'));
    }

    public function testInsertArrayWithExistingIdPreservesIt(): void
    {
        $existingId = new ObjectId();
        $bw         = new BulkWrite();
        $id         = $bw->insert(['_id' => $existingId, 'x' => 1]);
        $stored     = $bw->getOperations()[0][1];

        $this->assertInstanceOf(Document::class, $stored);
        $this->assertSame((string) $existingId, (string) $id);
        $this->assertSame((string) $existingId, (string) $stored->get('_id'));
    }
}
