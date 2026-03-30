<?php

declare(strict_types=1);

namespace MongoDB\Tests\BSON;

use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;

class ObjectIdTest extends TestCase
{
    public function testConstructorGeneratesUniqueIds(): void
    {
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = (string) new ObjectId();
        }

        $unique = array_unique($ids);
        $this->assertCount(10, $unique, 'All 10 generated ObjectIds must be unique.');
    }

    public function testConstructorWithHexString(): void
    {
        $hex = '507f1f77bcf86cd799439011';
        $id  = new ObjectId($hex);

        $this->assertSame($hex, (string) $id);
    }

    public function testConstructorWithInvalidHexThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ObjectId('not-a-valid-hex-string!!');
    }

    public function testGetTimestamp(): void
    {
        // 507f1f77 hex == 1350918007 decimal (Unix timestamp)
        $hex = '507f1f77bcf86cd799439011';
        $id  = new ObjectId($hex);

        $this->assertSame(0x507f1f77, $id->getTimestamp());
    }

    public function testJsonSerialize(): void
    {
        $hex    = '507f1f77bcf86cd799439011';
        $id     = new ObjectId($hex);
        $result = $id->jsonSerialize();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('$oid', $result);
        $this->assertSame($hex, $result['$oid']);
    }

    public function testSerialization(): void
    {
        $original     = new ObjectId('507f1f77bcf86cd799439011');
        $serialized   = serialize($original);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(ObjectId::class, $unserialized);
        $this->assertSame((string) $original, (string) $unserialized);
    }

    public function testSetState(): void
    {
        $hex = '507f1f77bcf86cd799439011';
        $id  = ObjectId::__set_state(['oid' => $hex]);

        $this->assertInstanceOf(ObjectId::class, $id);
        $this->assertSame($hex, (string) $id);
    }
}
