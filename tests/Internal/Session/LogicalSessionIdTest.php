<?php

declare(strict_types=1);

namespace MongoDB\Tests\Internal\Session;

use MongoDB\BSON\Binary;
use MongoDB\Internal\Session\LogicalSessionId;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class LogicalSessionIdTest extends TestCase
{
    public function testBsonSerializeOnlyExposesId(): void
    {
        $binary = new Binary('0123456789abcdef', Binary::TYPE_UUID);
        $lsid   = new LogicalSessionId($binary);

        self::assertSame(['id' => $binary], $lsid->bsonSerialize());
    }

    public function testIsExpiredReturnsFalseForFreshSession(): void
    {
        $lsid = new LogicalSessionId(new Binary('0123456789abcdef', Binary::TYPE_UUID));

        self::assertFalse($lsid->isExpired(30));
    }

    public function testIsExpiredReturnsTrueWhenLastUseIsOld(): void
    {
        $lsid = new LogicalSessionId(new Binary('0123456789abcdef', Binary::TYPE_UUID));

        // Backdate lastUse by 30 minutes — exceeds the 29-minute effective threshold (30 - 1 buffer).
        $prop = new ReflectionProperty(LogicalSessionId::class, 'lastUse');
        $prop->setValue($lsid, $lsid->lastUse - 30 * 60 * 1_000_000_000);

        self::assertTrue($lsid->isExpired(30));
    }

    public function testIsExpiredUsesOneMinuteBuffer(): void
    {
        $lsid = new LogicalSessionId(new Binary('0123456789abcdef', Binary::TYPE_UUID));

        // Backdate by 28 minutes — within the 29-minute effective threshold (30 - 1 buffer).
        $prop = new ReflectionProperty(LogicalSessionId::class, 'lastUse');
        $prop->setValue($lsid, $lsid->lastUse - 28 * 60 * 1_000_000_000);

        self::assertFalse($lsid->isExpired(30));
    }

    public function testTouchUpdatesLastUse(): void
    {
        $lsid = new LogicalSessionId(new Binary('0123456789abcdef', Binary::TYPE_UUID));

        $prop = new ReflectionProperty(LogicalSessionId::class, 'lastUse');
        $prop->setValue($lsid, $lsid->lastUse - 5 * 60 * 1_000_000_000);

        $before = $lsid->lastUse;
        $lsid->touch();

        self::assertGreaterThan($before, $lsid->lastUse);
    }

    public function testLastUseIsSetByConstructor(): void
    {
        $lsid = new LogicalSessionId(new Binary('0123456789abcdef', Binary::TYPE_UUID));

        self::assertGreaterThan(0, $lsid->lastUse);
    }
}
