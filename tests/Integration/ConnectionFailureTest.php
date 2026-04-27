<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration;

use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use MongoDB\Driver\Manager;
use PHPUnit\Framework\TestCase;

use function explode;
use function fclose;
use function sprintf;
use function stream_socket_get_name;
use function stream_socket_server;

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

    public function testConnectTimeoutMsIsRespected(): void
    {
        // Open a TCP server that listens but never sends data back.
        // The kernel completes the TCP 3-way handshake automatically (via the
        // backlog), so the driver's connect() succeeds immediately, but the
        // hello exchange then blocks — connectTimeoutMS must fire.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server, 'Could not create local test server');

        $addr = stream_socket_get_name($server, false);
        [, $port] = explode(':', $addr);

        $uri     = sprintf('mongodb://127.0.0.1:%d/?connectTimeoutMS=150&serverSelectionTimeoutMS=500', $port);
        $manager = new Manager($uri);

        $this->expectException(ConnectionTimeoutException::class);

        try {
            $manager->executeCommand('admin', new Command(['ping' => 1]));
        } finally {
            fclose($server);
        }
    }
}
