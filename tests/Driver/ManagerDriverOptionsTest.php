<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver;

use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Manager;
use MongoDB\Driver\ServerApi;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests for Manager::__construct() driver options (3rd parameter).
 *
 * Validation happens before any network I/O, so no MongoDB instance is needed.
 */
class ManagerDriverOptionsTest extends TestCase
{
    private const URI = 'mongodb://localhost:27017';

    // =========================================================================
    // Valid driver options — no exception expected
    // =========================================================================

    public function testNoDriverOptionsIsAccepted(): void
    {
        $manager = new Manager(self::URI);

        self::assertInstanceOf(Manager::class, $manager);
    }

    public function testNullDriverOptionsIsAccepted(): void
    {
        $manager = new Manager(self::URI, null, null);

        self::assertInstanceOf(Manager::class, $manager);
    }

    public function testEmptyDriverOptionsIsAccepted(): void
    {
        $manager = new Manager(self::URI, null, []);

        self::assertInstanceOf(Manager::class, $manager);
    }

    public function testDriverOptionWithValidArrayIsAccepted(): void
    {
        $manager = new Manager(self::URI, null, [
            'driver' => [
                'name'     => 'MyLib',
                'version'  => '1.0.0',
                'platform' => 'PHP 8.4',
            ],
        ]);

        self::assertInstanceOf(Manager::class, $manager);
    }

    public function testDriverOptionWithPartialFieldsIsAccepted(): void
    {
        $manager = new Manager(self::URI, null, ['driver' => ['name' => 'MyLib']]);

        self::assertInstanceOf(Manager::class, $manager);
    }

    public function testDriverOptionWithEmptyArrayIsAccepted(): void
    {
        $manager = new Manager(self::URI, null, ['driver' => []]);

        self::assertInstanceOf(Manager::class, $manager);
    }

    public function testServerApiOptionWithValidInstanceIsAccepted(): void
    {
        $manager = new Manager(self::URI, null, ['serverApi' => new ServerApi('1')]);

        self::assertInstanceOf(Manager::class, $manager);
    }

    // =========================================================================
    // driver option — wrong type for the option itself
    // =========================================================================

    public function testDriverOptionRejectsStdClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected "driver" driver option to be an array, stdClass given',
        );

        new Manager(self::URI, null, ['driver' => new stdClass()]);
    }

    public function testDriverOptionRejectsString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected "driver" driver option to be an array, string given',
        );

        new Manager(self::URI, null, ['driver' => 'MyLib/1.0']);
    }

    public function testDriverOptionRejectsInt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected "driver" driver option to be an array, 32-bit integer given',
        );

        new Manager(self::URI, null, ['driver' => 42]);
    }

    public function testDriverOptionRejectsBool(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected "driver" driver option to be an array, boolean given',
        );

        new Manager(self::URI, null, ['driver' => true]);
    }

    // =========================================================================
    // driver sub-fields — wrong type for name/version/platform
    // =========================================================================

    public function testDriverNameRejectsArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected "name" handshake option to be a string, array given',
        );

        new Manager(self::URI, null, ['driver' => ['name' => []]]);
    }

    public function testDriverVersionRejectsArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected "version" handshake option to be a string, array given',
        );

        new Manager(self::URI, null, ['driver' => ['version' => []]]);
    }

    public function testDriverPlatformRejectsArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected "platform" handshake option to be a string, array given',
        );

        new Manager(self::URI, null, ['driver' => ['platform' => []]]);
    }

    public function testDriverNameRejectsInt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected "name" handshake option to be a string, 32-bit integer given',
        );

        new Manager(self::URI, null, ['driver' => ['name' => 42]]);
    }

    public function testDriverVersionRejectsBool(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected "version" handshake option to be a string, boolean given',
        );

        new Manager(self::URI, null, ['driver' => ['version' => false]]);
    }

    public function testDriverPlatformRejectsObject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Expected "platform" handshake option to be a string, stdClass given',
        );

        new Manager(self::URI, null, ['driver' => ['platform' => new stdClass()]]);
    }

    // =========================================================================
    // serverApi option
    // =========================================================================

    public function testServerApiOptionRejectsString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Manager(self::URI, null, ['serverApi' => '1']);
    }

    public function testServerApiOptionRejectsArray(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Manager(self::URI, null, ['serverApi' => ['version' => '1']]);
    }
}
