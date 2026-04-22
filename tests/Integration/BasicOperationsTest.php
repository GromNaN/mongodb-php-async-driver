<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use MongoDB\Driver\ReadPreference;

use function bin2hex;
use function iterator_to_array;
use function random_bytes;

/** @group integration */
class BasicOperationsTest extends IntegrationTestCase
{
    private Manager $manager;

    /** Unique database name so parallel CI runs do not collide. */
    private string $dbName;

    /** Collection used by all tests in this class. */
    private string $collection = 'test_basic_ops';

    protected function setUp(): void
    {
        $uri           = $_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017';
        $this->manager = new Manager($uri);
        $this->dbName  = 'phpunit_' . bin2hex(random_bytes(4));

        // Drop the collection before each test for isolation
        $this->manager->executeCommand(
            $this->dbName,
            new Command(['drop' => $this->collection]),
        );
    }

    protected function tearDown(): void
    {
        // Drop the test database after each test to keep the server clean
        $this->manager->executeCommand(
            $this->dbName,
            new Command(['dropDatabase' => 1]),
        );
    }

    public function testPing(): void
    {
        $cursor = $this->manager->executeCommand(
            'admin',
            new Command(['ping' => 1]),
        );

        $results = iterator_to_array($cursor);
        $this->assertNotEmpty($results);

        $first = (array) $results[0];
        $this->assertSame(1.0, (float) ($first['ok'] ?? 0), 'ping must return ok: 1');
    }

    public function testInsertAndFind(): void
    {
        $bw  = new BulkWrite();
        $id  = $bw->insert(['name' => 'Alice', 'age' => 30]);
        $this->manager->executeBulkWrite($this->dbName . '.' . $this->collection, $bw);

        $query  = new Query(['_id' => $id]);
        $cursor = $this->manager->executeQuery(
            $this->dbName . '.' . $this->collection,
            $query,
            ['readPreference' => new ReadPreference(ReadPreference::PRIMARY)],
        );

        $results = iterator_to_array($cursor);
        $this->assertCount(1, $results);

        $doc = (array) $results[0];
        $this->assertSame('Alice', $doc['name']);
        $this->assertSame(30, $doc['age']);
    }

    public function testUpdate(): void
    {
        // Insert a document
        $bw = new BulkWrite();
        $id = $bw->insert(['counter' => 0]);
        $this->manager->executeBulkWrite($this->dbName . '.' . $this->collection, $bw);

        // Update it
        $bw2 = new BulkWrite();
        $bw2->update(['_id' => $id], ['$set' => ['counter' => 42]]);
        $result = $this->manager->executeBulkWrite($this->dbName . '.' . $this->collection, $bw2);

        $this->assertSame(1, $result->getModifiedCount());

        // Verify
        $cursor  = $this->manager->executeQuery(
            $this->dbName . '.' . $this->collection,
            new Query(['_id' => $id]),
        );
        $results = iterator_to_array($cursor);
        $doc     = (array) $results[0];
        $this->assertSame(42, $doc['counter']);
    }

    public function testDelete(): void
    {
        // Insert then delete
        $bw = new BulkWrite();
        $id = $bw->insert(['x' => 1]);
        $this->manager->executeBulkWrite($this->dbName . '.' . $this->collection, $bw);

        $bw2 = new BulkWrite();
        $bw2->delete(['_id' => $id]);
        $result = $this->manager->executeBulkWrite($this->dbName . '.' . $this->collection, $bw2);

        $this->assertSame(1, $result->getDeletedCount());

        // Verify the document is gone
        $cursor  = $this->manager->executeQuery(
            $this->dbName . '.' . $this->collection,
            new Query(['_id' => $id]),
        );
        $results = iterator_to_array($cursor);
        $this->assertCount(0, $results);
    }

    public function testCount(): void
    {
        $bw = new BulkWrite();
        $bw->insert(['n' => 1]);
        $bw->insert(['n' => 2]);
        $bw->insert(['n' => 3]);
        $this->manager->executeBulkWrite($this->dbName . '.' . $this->collection, $bw);

        $cursor  = $this->manager->executeCommand(
            $this->dbName,
            new Command(['count' => $this->collection]),
        );
        $results = iterator_to_array($cursor);
        $first   = (array) $results[0];

        $this->assertSame(3, (int) ($first['n'] ?? 0));
    }
}
