<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration;

use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use MongoDB\Driver\Manager;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the driver throws ConnectionTimeoutException quickly when no
 * server is available, instead of blocking indefinitely.
 *
 * These tests intentionally target a non-existent server and do NOT skip when
 * MongoDB is unavailable — that is precisely the scenario being tested.
 */
class ConnectionFailureTest extends TestCase
{
    public function testServerSelectionTimeoutIsRespected(): void
    {
        $manager = new Manager('mongodb://127.0.0.1:1/?serverSelectionTimeoutMS=100');

        $this->expectException(ConnectionTimeoutException::class);

        $manager->executeCommand('admin', new Command(['ping' => 1]));
    }
}
