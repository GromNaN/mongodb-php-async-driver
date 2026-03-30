<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver;

use MongoDB\Driver\WriteConcern;
use PHPUnit\Framework\TestCase;
use stdClass;

class WriteConcernTest extends TestCase
{
    public function testWEquals1(): void
    {
        $wc = new WriteConcern(1);

        $this->assertSame(1, $wc->getW());
        $this->assertFalse($wc->isDefault());
    }

    public function testWMajority(): void
    {
        $wc = new WriteConcern(WriteConcern::MAJORITY);

        $this->assertSame('majority', $wc->getW());
    }

    public function testWtimeout(): void
    {
        $wc = new WriteConcern(1, 5000);

        $this->assertSame(5000, $wc->getWtimeout());
    }

    public function testJournal(): void
    {
        $wc = new WriteConcern(1, 0, true);

        $this->assertTrue($wc->getJournal());
    }

    public function testBsonSerialize(): void
    {
        $wc     = new WriteConcern(1);
        $result = $wc->bsonSerialize();

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertObjectHasProperty('w', $result);
        $this->assertSame(1, $result->w);
    }
}
