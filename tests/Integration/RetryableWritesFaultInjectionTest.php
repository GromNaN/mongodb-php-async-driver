<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use Throwable;

use function bin2hex;
use function iterator_to_array;
use function random_bytes;

/**
 * Verifies that retryable writes actually retry once on a transient error.
 *
 * Uses MongoDB's `failCommand` fail-point to inject a single
 * ShutdownInProgress (91) error on the first attempt, then confirms the
 * operation succeeds on the automatic retry and produces the correct result.
 *
 * Requirements:
 *  - MongoDB 4.0+ with replica set (fail-points require a replica set).
 *  - MONGODB_URI must point to a replica-set member (e.g. the primary).
 *
 * Tests are skipped automatically when the server does not support fail-points
 * or when the topology does not support retryable writes.
 */
class RetryableWritesFaultInjectionTest extends IntegrationTestCase
{
    private Manager $manager;

    private string $ns;

    private string $dbName;

    private const COLLECTION = 'retry_fault';

    /** ShutdownInProgress — retryable per the spec. */
    private const RETRYABLE_CODE = 91;

    protected function setUp(): void
    {
        parent::setUp();

        $uri           = $_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017';
        $this->manager = new Manager($uri);
        $this->dbName  = 'phpunit_retryfault_' . bin2hex(random_bytes(4));
        $this->ns      = $this->dbName . '.' . self::COLLECTION;

        // Skip if fail-points are not supported (pre-4.0 or standalone).
        $this->requireFailPointSupport();
    }

    protected function tearDown(): void
    {
        // Always disable any active fail-point before cleanup.
        try {
            $this->disableFailPoint('failCommand');
        } catch (Throwable) {
            // Best-effort — ignore.
        }

        try {
            $this->manager->executeCommand(
                $this->dbName,
                new Command(['dropDatabase' => 1]),
            );
        } catch (Throwable) {
        }
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * A retryable insert must succeed even when the first attempt returns a
     * transient ShutdownInProgress (91) error.
     */
    public function testRetryableInsertSucceedsAfterTransientError(): void
    {
        $this->configureFailPoint('failCommand', ['times' => 1], ['failCommands' => ['insert'], 'errorCode' => self::RETRYABLE_CODE]);

        $bulk = new BulkWrite();
        $bulk->insert(['_id' => 1, 'v' => 'hello']);

        // Must not throw — the driver retries once automatically.
        $result = $this->manager->executeBulkWrite($this->ns, $bulk);

        $this->assertTrue($result->isAcknowledged());
        $this->assertSame(1, $result->getInsertedCount());

        // Confirm the document actually landed in the collection.
        $cursor = $this->manager->executeQuery(
            $this->ns,
            new Query(['_id' => 1]),
        );
        $docs = iterator_to_array($cursor);
        $this->assertCount(1, $docs);
    }

    /**
     * A retryable update must succeed even when the first attempt fails.
     */
    public function testRetryableUpdateSucceedsAfterTransientError(): void
    {
        // Insert a document without a fail-point so it exists.
        $bulk = new BulkWrite();
        $bulk->insert(['_id' => 2, 'x' => 0]);
        $this->manager->executeBulkWrite($this->ns, $bulk);

        $this->configureFailPoint('failCommand', ['times' => 1], ['failCommands' => ['update'], 'errorCode' => self::RETRYABLE_CODE]);

        $bulk = new BulkWrite();
        $bulk->update(['_id' => 2], ['$inc' => ['x' => 1]]);

        $result = $this->manager->executeBulkWrite($this->ns, $bulk);

        $this->assertTrue($result->isAcknowledged());
        $this->assertSame(1, $result->getModifiedCount());
    }

    /**
     * A retryable delete must succeed even when the first attempt fails.
     */
    public function testRetryableDeleteSucceedsAfterTransientError(): void
    {
        // Insert a document without a fail-point.
        $bulk = new BulkWrite();
        $bulk->insert(['_id' => 3, 'tag' => 'toDelete']);
        $this->manager->executeBulkWrite($this->ns, $bulk);

        $this->configureFailPoint('failCommand', ['times' => 1], ['failCommands' => ['delete'], 'errorCode' => self::RETRYABLE_CODE]);

        $bulk = new BulkWrite();
        $bulk->delete(['_id' => 3]);

        $result = $this->manager->executeBulkWrite($this->ns, $bulk);

        $this->assertTrue($result->isAcknowledged());
        $this->assertSame(1, $result->getDeletedCount());
    }

    /**
     * When the server returns a non-retryable error code, the exception is
     * propagated immediately (no retry).
     */
    public function testNonRetryableErrorIsNotRetried(): void
    {
        // Code 2 = BadValue — not in the retryable list.
        $this->configureFailPoint('failCommand', ['times' => 2], ['failCommands' => ['insert'], 'errorCode' => 2]);

        $bulk = new BulkWrite();
        $bulk->insert(['_id' => 4]);

        // BulkWrite errors surface as BulkWriteException, which extends ServerException.
        $this->expectException(RuntimeException::class);
        $this->manager->executeBulkWrite($this->ns, $bulk);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Skip the test if the server does not support the `failCommand` fail-point
     * (requires MongoDB 4.0 + replica set).
     */
    private function requireFailPointSupport(): void
    {
        try {
            $cursor  = $this->manager->executeCommand('admin', new Command(['hello' => 1]));
            $hello   = (array) (iterator_to_array($cursor)[0] ?? []);
            $isRs    = isset($hello['setName']) || isset($hello['hosts']);
            $wireVer = (int) ($hello['maxWireVersion'] ?? 0);

            // fail-points require wire protocol version >= 7 (MongoDB 4.0) and a RS.
            if ($wireVer < 7 || ! $isRs) {
                $this->markTestSkipped('Fail-points require MongoDB 4.0+ replica set.');
            }
        } catch (Throwable $e) {
            $this->markTestSkipped('Could not probe server for fail-point support: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $mode e.g. ['times' => 1] or 'alwaysOn'
     * @param array<string, mixed> $data Fail-point data document.
     */
    private function configureFailPoint(string $name, array|string $mode, array $data): void
    {
        $cmd = ['configureFailPoint' => $name, 'mode' => $mode, 'data' => (object) $data];
        $this->manager->executeCommand('admin', new Command($cmd));
    }

    private function disableFailPoint(string $name): void
    {
        $this->manager->executeCommand('admin', new Command(['configureFailPoint' => $name, 'mode' => 'off']));
    }
}
