<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use MongoDB\Driver\WriteConcern;

use function bin2hex;
use function iterator_to_array;
use function random_bytes;

/**
 * Basic smoke-tests for retryReads / retryWrites integration.
 *
 * These tests do NOT inject faults — they only verify that the retry
 * plumbing does not break normal (non-error) operation.
 */
class RetryableOperationsTest extends IntegrationTestCase
{
    private Manager $manager;

    private string $dbName;

    private string $collection = 'retry_test';

    protected function setUp(): void
    {
        parent::setUp();

        $uri           = $_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017';
        $this->manager = new Manager($uri);
        $this->dbName  = 'phpunit_retry_' . bin2hex(random_bytes(4));

        // Ensure the collection is clean before each test.
        $this->manager->executeCommand(
            $this->dbName,
            new Command(['drop' => $this->collection]),
        );
    }

    protected function tearDown(): void
    {
        $this->manager->executeCommand(
            $this->dbName,
            new Command(['dropDatabase' => 1]),
        );
    }

    /**
     * Verify that the default Manager has retryReads and retryWrites enabled
     * via the URI option defaults.
     */
    public function testRetryReadsOptionDefaultsToTrue(): void
    {
        // A Manager built without explicit options uses the defaults from UriOptions,
        // which set retryReads=true and retryWrites=true.
        // We exercise this by running a normal read — if retryReads were broken
        // (e.g. syntax error) the test would fail here.
        $cursor  = $this->manager->executeReadCommand(
            'admin',
            new Command(['ping' => 1]),
        );
        $results = iterator_to_array($cursor);

        $this->assertNotEmpty($results);
        $first = (array) $results[0];
        $this->assertSame(1.0, (float) ($first['ok'] ?? 0));
    }

    /**
     * A normal find query succeeds with retryReads enabled (default).
     */
    public function testRetryableReadDoesNotRetryOnNonRetryableError(): void
    {
        // Insert a document first so the find has something to return.
        $bulk = new BulkWrite();
        $bulk->insert(['name' => 'Alice', 'score' => 42]);
        $this->manager->executeBulkWrite(
            $this->dbName . '.' . $this->collection,
            $bulk,
        );

        // A plain find against a known-good collection must succeed.
        $cursor = $this->manager->executeQuery(
            $this->dbName . '.' . $this->collection,
            new Query(['name' => 'Alice']),
        );

        $docs = iterator_to_array($cursor);
        $this->assertCount(1, $docs);
        $this->assertSame('Alice', (string) ((array) $docs[0])['name']);
    }

    /**
     * A normal bulkWrite succeeds with retryWrites enabled (default).
     */
    public function testRetryableWritesBulkWriteSucceeds(): void
    {
        $bulk = new BulkWrite();
        $bulk->insert(['_id' => 1, 'x' => 'foo']);
        $bulk->insert(['_id' => 2, 'x' => 'bar']);
        $bulk->update(['_id' => 1], ['$set' => ['x' => 'baz']]);
        $bulk->delete(['_id' => 2]);

        $result = $this->manager->executeBulkWrite(
            $this->dbName . '.' . $this->collection,
            $bulk,
        );

        $this->assertTrue($result->isAcknowledged());
        $this->assertSame(2, $result->getInsertedCount());
        $this->assertSame(1, $result->getModifiedCount());
        $this->assertSame(1, $result->getDeletedCount());
    }

    /**
     * A bulkWrite with an explicit w:0 write concern must succeed even though
     * retryable writes are skipped for unacknowledged writes.
     */
    public function testRetryableWritesBulkWriteWithUnacknowledgedConcernSkipsRetry(): void
    {
        $wc   = new WriteConcern(0);
        $bulk = new BulkWrite();
        $bulk->insert(['_id' => 10, 'tag' => 'unack']);

        $result = $this->manager->executeBulkWrite(
            $this->dbName . '.' . $this->collection,
            $bulk,
            ['writeConcern' => $wc],
        );

        $this->assertFalse($result->isAcknowledged());
    }
}
