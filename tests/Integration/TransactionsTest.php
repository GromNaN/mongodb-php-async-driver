<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\Session;
use RuntimeException;

use function bin2hex;
use function iterator_to_array;
use function random_bytes;
use function sprintf;
use function version_compare;

/**
 * Integration tests for multi-document transactions (requires replica set or sharded cluster).
 */
class TransactionsTest extends IntegrationTestCase
{
    private Manager $manager;
    private string $dbName;
    private string $ns;

    protected function setUp(): void
    {
        parent::setUp();

        $uri           = $_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017';
        $this->manager = new Manager($uri);
        $this->dbName  = 'phpunit_txn_' . bin2hex(random_bytes(4));
        $this->ns      = $this->dbName . '.txn_test';

        // Create the collection explicitly (required before using it in a transaction)
        $this->manager->executeCommand(
            $this->dbName,
            new Command(['create' => 'txn_test']),
        );
    }

    protected function tearDown(): void
    {
        $this->manager->executeCommand(
            $this->dbName,
            new Command(['dropDatabase' => 1]),
        );
    }

    private function requiresReplicaSet(): void
    {
        $cursor = $this->manager->executeCommand('admin', new Command(['hello' => 1]));
        $hello  = (array) iterator_to_array($cursor)[0];

        if (isset($hello['setName'])) {
            return;
        }

        $this->markTestSkipped('Transactions require a replica set or sharded cluster');
    }

    private function requiresServerVersion(string $minVersion): void
    {
        $cursor  = $this->manager->executeCommand('admin', new Command(['buildInfo' => 1]));
        $version = (string) (iterator_to_array($cursor)[0]->version ?? '0.0.0');

        if (! version_compare($version, $minVersion, '<')) {
            return;
        }

        $this->markTestSkipped(sprintf('Requires MongoDB %s or later (server is %s)', $minVersion, $version));
    }

    public function testStartTransactionIncrementsTxnNumber(): void
    {
        $session = $this->manager->startSession();

        $this->assertSame(Session::TRANSACTION_NONE, $session->getTransactionState());
        $this->assertSame(0, $session->getTxnNumber());

        $session->startTransaction();
        $this->assertSame(Session::TRANSACTION_STARTING, $session->getTransactionState());
        $this->assertSame(1, $session->getTxnNumber());

        $session->abortTransaction();
        $this->assertSame(Session::TRANSACTION_ABORTED, $session->getTransactionState());

        $session->startTransaction();
        $this->assertSame(2, $session->getTxnNumber());
    }

    public function testEmptyTransactionCommit(): void
    {
        $session = $this->manager->startSession();

        $session->startTransaction();
        $this->assertSame(Session::TRANSACTION_STARTING, $session->getTransactionState());

        // Commit without sending any commands — no network round-trip needed.
        $session->commitTransaction();
        $this->assertSame(Session::TRANSACTION_COMMITTED, $session->getTransactionState());
    }

    public function testEmptyTransactionAbort(): void
    {
        $session = $this->manager->startSession();

        $session->startTransaction();
        $session->abortTransaction();
        $this->assertSame(Session::TRANSACTION_ABORTED, $session->getTransactionState());
    }

    public function testCommitVisibleAfterCommit(): void
    {
        $this->requiresReplicaSet();
        $this->requiresServerVersion('4.0');

        $session = $this->manager->startSession();
        $session->startTransaction();

        $bw = new BulkWrite();
        $bw->insert(['txn' => 1, 'value' => 'committed']);
        $this->manager->executeBulkWrite($this->ns, $bw, ['session' => $session]);

        $session->commitTransaction();
        $this->assertSame(Session::TRANSACTION_COMMITTED, $session->getTransactionState());

        // Document must be visible outside the transaction after commit.
        $cursor  = $this->manager->executeQuery(
            $this->ns,
            new Query(['txn' => 1]),
            ['readPreference' => new ReadPreference(ReadPreference::PRIMARY)],
        );
        $results = iterator_to_array($cursor);
        $this->assertCount(1, $results);
        $this->assertSame('committed', ((array) $results[0])['value']);
    }

    public function testAbortRollsBackWrites(): void
    {
        $this->requiresReplicaSet();
        $this->requiresServerVersion('4.0');

        $session = $this->manager->startSession();
        $session->startTransaction();

        $bw = new BulkWrite();
        $bw->insert(['txn' => 2, 'value' => 'rolled_back']);
        $this->manager->executeBulkWrite($this->ns, $bw, ['session' => $session]);

        $session->abortTransaction();
        $this->assertSame(Session::TRANSACTION_ABORTED, $session->getTransactionState());

        // Document must NOT be visible after abort.
        $cursor  = $this->manager->executeQuery(
            $this->ns,
            new Query(['txn' => 2]),
            ['readPreference' => new ReadPreference(ReadPreference::PRIMARY)],
        );
        $results = iterator_to_array($cursor);
        $this->assertCount(0, $results);
    }

    public function testWithTransactionCommitsOnSuccess(): void
    {
        $this->requiresReplicaSet();
        $this->requiresServerVersion('4.0');

        $session = $this->manager->startSession();
        $ns      = $this->ns;
        $manager = $this->manager;

        $session->withTransaction(static function (Session $s) use ($manager, $ns): void {
            $bw = new BulkWrite();
            $bw->insert(['wt' => 1]);
            $manager->executeBulkWrite($ns, $bw, ['session' => $s]);
        });

        $this->assertSame(Session::TRANSACTION_COMMITTED, $session->getTransactionState());

        $cursor  = $this->manager->executeQuery($this->ns, new Query(['wt' => 1]));
        $results = iterator_to_array($cursor);
        $this->assertCount(1, $results);
    }

    public function testWithTransactionAbortsOnCallbackException(): void
    {
        $this->requiresReplicaSet();
        $this->requiresServerVersion('4.0');

        $session = $this->manager->startSession();
        $ns      = $this->ns;
        $manager = $this->manager;

        try {
            $session->withTransaction(static function (Session $s) use ($manager, $ns): void {
                $bw = new BulkWrite();
                $bw->insert(['wt' => 2]);
                $manager->executeBulkWrite($ns, $bw, ['session' => $s]);

                throw new RuntimeException('callback error');
            });
            $this->fail('withTransaction should rethrow callback exceptions');
        } catch (RuntimeException $e) {
            $this->assertSame('callback error', $e->getMessage());
        }

        // Rolled back: no document inserted.
        $cursor  = $this->manager->executeQuery($this->ns, new Query(['wt' => 2]));
        $results = iterator_to_array($cursor);
        $this->assertCount(0, $results);
    }

    public function testMultipleOperationsInTransaction(): void
    {
        $this->requiresReplicaSet();
        $this->requiresServerVersion('4.0');

        $session = $this->manager->startSession();
        $session->startTransaction();

        // Insert
        $bw = new BulkWrite();
        $id = $bw->insert(['multi' => true, 'step' => 1]);
        $this->manager->executeBulkWrite($this->ns, $bw, ['session' => $session]);

        // Update
        $bw2 = new BulkWrite();
        $bw2->update(['_id' => $id], ['$set' => ['step' => 2]]);
        $this->manager->executeBulkWrite($this->ns, $bw2, ['session' => $session]);

        // Read within transaction
        $cursor  = $this->manager->executeQuery(
            $this->ns,
            new Query(['_id' => $id]),
            ['session' => $session],
        );
        $results = iterator_to_array($cursor);
        $this->assertCount(1, $results);
        $this->assertSame(2, (int) ((array) $results[0])['step']);

        $session->commitTransaction();

        // Verify committed
        $cursor  = $this->manager->executeQuery($this->ns, new Query(['_id' => $id]));
        $results = iterator_to_array($cursor);
        $this->assertSame(2, (int) ((array) $results[0])['step']);
    }
}
