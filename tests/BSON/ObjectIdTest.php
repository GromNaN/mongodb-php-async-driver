<?php

declare(strict_types=1);

namespace MongoDB\Tests\BSON;

use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;

use function strlen;
use function time;

class ObjectIdTest extends TestCase
{
    public function testConsecutiveObjectIdsAreMonotonicallyIncreasing(): void
    {
        $a = new ObjectId();
        $b = new ObjectId();
        $c = new ObjectId();

        // ObjectIds generated in the same process share the same 5-byte random
        // component, so sorting is determined by the 3-byte counter alone when
        // the timestamp is the same.  PHP compares objects by their properties,
        // so $a->oid < $b->oid (lexicographic hex string order).
        $this->assertGreaterThan($a->__toString(), $b->__toString(), 'b.oid must be > a.oid');
        $this->assertGreaterThan($b->__toString(), $c->__toString(), 'c.oid must be > b.oid');
    }

    public function testObjectIdHasCorrectLength(): void
    {
        $oid = new ObjectId();
        $this->assertSame(24, strlen($oid->__toString()), 'ObjectId hex string must be 24 chars');
    }

    public function testObjectIdTimestampMatchesCreationTime(): void
    {
        $before = time();
        $oid    = new ObjectId();
        $after  = time();

        $this->assertGreaterThanOrEqual($before, $oid->getTimestamp());
        $this->assertLessThanOrEqual($after, $oid->getTimestamp());
    }
}
