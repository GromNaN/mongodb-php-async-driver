<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver;

use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\ReadPreference;
use PHPUnit\Framework\TestCase;
use stdClass;

class ReadPreferenceTest extends TestCase
{
    public function testPrimaryMode(): void
    {
        $rp = new ReadPreference(ReadPreference::PRIMARY);

        $this->assertSame('primary', $rp->getModeString());
    }

    public function testSecondaryMode(): void
    {
        $rp = new ReadPreference(ReadPreference::SECONDARY);

        $this->assertSame('secondary', $rp->getModeString());
    }

    public function testInvalidModeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReadPreference('invalidMode');
    }

    public function testTagSets(): void
    {
        $tagSets = [['dc' => 'us-east']];
        $rp      = new ReadPreference(ReadPreference::SECONDARY, $tagSets);

        $this->assertSame($tagSets, $rp->getTagSets());
    }

    public function testMaxStaleness(): void
    {
        $rp = new ReadPreference(ReadPreference::SECONDARY, null, ['maxStalenessSeconds' => 120]);

        $this->assertSame(120, $rp->getMaxStalenessSeconds());
    }

    public function testBsonSerialize(): void
    {
        $rp     = new ReadPreference(ReadPreference::PRIMARY);
        $result = $rp->bsonSerialize();

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertObjectHasProperty('mode', $result);
        $this->assertSame('primary', $result->mode);
    }
}
