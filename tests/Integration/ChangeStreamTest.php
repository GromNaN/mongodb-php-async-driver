<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\ReadPreference;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function current;
use function getenv;
use function random_bytes;

/**
 * Integration tests for Change Stream support (T4-B).
 *
 * These tests verify that the driver correctly supports the `$changeStream`
 * aggregation stage required by the mongodb/mongodb library's watch() API.
 * The full change-stream spec (resume tokens, auto-resume, error labels) is
 * covered by the library's own unified spec tests; here we exercise the
 * driver-level primitives directly.
 */
class ChangeStreamTest extends TestCase
{
    private Manager $manager;
    private string $collectionName;
    private string $dbName;

    protected function setUp(): void
    {
        $base                 = getenv('MONGODB_URI') ?: 'mongodb://localhost:27017/';
        $this->manager        = new Manager($base);
        $this->dbName         = 'change_stream_test';
        $this->collectionName = 'events_' . bin2hex(random_bytes(4));

        // Change streams require a replica set or sharded cluster.
        if ($this->getTopologyType() !== 'single') {
            return;
        }

        self::markTestSkipped('Change streams require a replica set or sharded cluster');
    }

    protected function tearDown(): void
    {
        $this->manager->executeCommand(
            $this->dbName,
            new Command(['drop' => $this->collectionName]),
        );
    }

    /**
     * Opening a change stream with the $changeStream aggregation stage
     * should return a tailable cursor (cursor ID > 0).
     */
    public function testOpenChangeStreamCursorIsAlive(): void
    {
        // Create the collection first (change streams require it to exist).
        $this->manager->executeCommand(
            $this->dbName,
            new Command(['create' => $this->collectionName]),
        );

        // Open the change stream with an empty initial batch.
        $cursor = $this->manager->executeCommand(
            $this->dbName,
            new Command([
                'aggregate' => $this->collectionName,
                'pipeline'  => [['$changeStream' => (object) []]],
                'cursor'    => (object) ['batchSize' => 0],
            ]),
            ['readPreference' => new ReadPreference(ReadPreference::PRIMARY)],
        );

        // The returned cursor's ID must be non-zero (tailable cursor alive on server).
        self::assertNotEquals(0, (int) (string) $cursor->getId());

        // Kill the server-side cursor to avoid leaking it.
        $this->manager->executeCommand(
            $this->dbName,
            new Command(['killCursors' => $this->collectionName, 'cursors' => [$cursor->getId()]]),
        );
    }

    /**
     * A change event inserted after the stream opens must appear in a
     * subsequent getMore, verifying the end-to-end $changeStream pipeline.
     *
     * The stream is opened with maxAwaitTimeMS=500 so the blocking getMore
     * call returns promptly once the inserted document triggers the event.
     */
    public function testChangeEventAppearsInGetMore(): void
    {
        // Create the collection before opening the stream.
        $this->manager->executeCommand(
            $this->dbName,
            new Command(['create' => $this->collectionName]),
        );

        // Open the change stream; batchSize=0 so the first batch is empty.
        $streamCursor = $this->manager->executeCommand(
            $this->dbName,
            new Command([
                'aggregate' => $this->collectionName,
                'pipeline'  => [['$changeStream' => (object) []]],
                'cursor'    => (object) ['batchSize' => 0],
            ]),
            ['readPreference' => new ReadPreference(ReadPreference::PRIMARY)],
        );

        $cursorId = $streamCursor->getId();
        self::assertNotEquals(0, (int) (string) $cursorId);

        // Insert a document to produce a change event.
        $bulk = new BulkWrite();
        $bulk->insert(['x' => 999]);
        $this->manager->executeBulkWrite($this->dbName . '.' . $this->collectionName, $bulk);

        // The next iteration on the tailable cursor should yield the change event.
        $streamCursor->next();

        self::assertTrue($streamCursor->valid());
        $event = $streamCursor->current();
        self::assertIsObject($event);
        self::assertSame('insert', $event->operationType);
        self::assertSame(999, (int) $event->fullDocument->x);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getTopologyType(): string
    {
        $result = $this->manager->executeCommand('admin', new Command(['hello' => 1]));
        $doc    = current($result->toArray());

        if (isset($doc->msg) && $doc->msg === 'isdbgrid') {
            return 'sharded';
        }

        if (isset($doc->setName)) {
            return 'replicaset';
        }

        return 'single';
    }
}
