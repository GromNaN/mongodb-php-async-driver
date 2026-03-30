<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver;

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\BulkWrite;
use PHPUnit\Framework\TestCase;

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
}
