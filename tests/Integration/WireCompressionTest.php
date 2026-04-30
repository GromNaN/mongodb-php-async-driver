<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function current;
use function getenv;
use function random_bytes;
use function str_contains;
use function str_repeat;

/**
 * Integration tests for OP_COMPRESSED wire protocol support (T4-C).
 *
 * These tests verify that zlib compression negotiated during the hello
 * handshake is transparently applied to subsequent commands and that
 * responses are correctly decompressed.
 */
class WireCompressionTest extends TestCase
{
    private string $uri;

    protected function setUp(): void
    {
        $base = getenv('MONGODB_URI') ?: 'mongodb://localhost:27017/';
        // Append compressors option using the correct separator (? or &).
        $sep           = str_contains($base, '?') ? '&' : '?';
        $this->uri     = $base . $sep . 'compressors=zlib';
    }

    /**
     * A Manager with compressors=zlib should complete a ping without errors.
     * This verifies end-to-end: compression negotiation + compressed send + decompress receive.
     */
    public function testPingWithZlibCompression(): void
    {
        $manager = new Manager($this->uri);
        $result  = $manager->executeCommand('admin', new Command(['ping' => 1]));
        $doc     = current($result->toArray());

        self::assertEquals(1, $doc->ok);
    }

    /**
     * A Manager without compressors should also work (baseline / regression guard).
     */
    public function testPingWithoutCompression(): void
    {
        $base    = getenv('MONGODB_URI') ?: 'mongodb://localhost:27017/';
        $manager = new Manager($base);
        $result  = $manager->executeCommand('admin', new Command(['ping' => 1]));
        $doc     = current($result->toArray());

        self::assertEquals(1, $doc->ok);
    }

    /**
     * A round-trip insert + find over a compressed connection to verify that
     * both outgoing (compressed) and incoming (decompressed) paths work
     * with non-trivial payloads.
     */
    public function testInsertAndFindWithZlibCompression(): void
    {
        $manager    = new Manager($this->uri);
        $collection = 'wire_compression_test_' . bin2hex(random_bytes(4));
        $ns         = 'test.' . $collection;

        // Insert a document.
        $bulk = new BulkWrite();
        $bulk->insert(['x' => 42, 'msg' => str_repeat('hello', 100)]);
        $manager->executeBulkWrite($ns, $bulk);

        // Find it back.
        $cursor = $manager->executeQuery($ns, new Query(['x' => 42]));
        $docs   = $cursor->toArray();

        self::assertCount(1, $docs);
        self::assertSame(42, (int) $docs[0]->x);

        // Drop the test collection.
        $manager->executeCommand('test', new Command(['drop' => $collection]));
    }
}
