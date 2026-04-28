<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Manager;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\WriteConcern;

use function bin2hex;
use function count;
use function iterator_to_array;
use function random_bytes;

/**
 * Integration tests for index management commands via the driver.
 *
 * These tests use executeWriteCommand / executeReadCommand to exercise
 * createIndexes, listIndexes, and dropIndexes — the same commands the
 * PHP library relies on.
 */
class IndexManagementTest extends IntegrationTestCase
{
    private Manager $manager;
    private string $dbName;
    private string $collection;
    private string $ns;

    protected function setUp(): void
    {
        parent::setUp();

        $uri             = $_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017';
        $this->manager   = new Manager($uri);
        $this->dbName    = 'phpunit_idx_' . bin2hex(random_bytes(4));
        $this->collection = 'idx_test';
        $this->ns        = $this->dbName . '.' . $this->collection;

        // Seed a document so the collection exists
        $bw = new BulkWrite();
        $bw->insert(['_init' => 1]);
        $this->manager->executeBulkWrite($this->ns, $bw);
    }

    protected function tearDown(): void
    {
        $this->manager->executeCommand(
            $this->dbName,
            new Command(['dropDatabase' => 1]),
        );
    }

    public function testCreateIndex(): void
    {
        $cursor = $this->manager->executeWriteCommand(
            $this->dbName,
            new Command([
                'createIndexes' => $this->collection,
                'indexes'       => [
                    ['key' => ['x' => 1], 'name' => 'x_1'],
                ],
            ]),
        );

        $result = (array) iterator_to_array($cursor)[0];
        $this->assertSame(1, (int) ($result['ok'] ?? 0));
    }

    public function testListIndexes(): void
    {
        // Create a named index first
        $this->manager->executeWriteCommand(
            $this->dbName,
            new Command([
                'createIndexes' => $this->collection,
                'indexes'       => [
                    ['key' => ['y' => -1], 'name' => 'y_-1'],
                ],
            ]),
        );

        $cursor  = $this->manager->executeReadCommand(
            $this->dbName,
            new Command(['listIndexes' => $this->collection]),
            ['readPreference' => new ReadPreference(ReadPreference::PRIMARY)],
        );
        $indexes = iterator_to_array($cursor);

        // Always at least _id + the one we created
        $this->assertGreaterThanOrEqual(2, count($indexes));

        $names = [];
        foreach ($indexes as $idx) {
            $names[] = (string) ((array) $idx)['name'];
        }

        $this->assertContains('_id_', $names);
        $this->assertContains('y_-1', $names);
    }

    public function testDropIndex(): void
    {
        // Create then drop
        $this->manager->executeWriteCommand(
            $this->dbName,
            new Command([
                'createIndexes' => $this->collection,
                'indexes'       => [
                    ['key' => ['z' => 1], 'name' => 'z_1'],
                ],
            ]),
        );

        $cursor = $this->manager->executeWriteCommand(
            $this->dbName,
            new Command([
                'dropIndexes' => $this->collection,
                'index'       => 'z_1',
            ]),
        );

        $result = (array) iterator_to_array($cursor)[0];
        $this->assertSame(1, (int) ($result['ok'] ?? 0));

        // Confirm index is gone
        $cursor  = $this->manager->executeReadCommand(
            $this->dbName,
            new Command(['listIndexes' => $this->collection]),
        );
        $indexes = iterator_to_array($cursor);
        $names   = [];
        foreach ($indexes as $idx) {
            $names[] = (string) ((array) $idx)['name'];
        }

        $this->assertNotContains('z_1', $names);
    }

    public function testDropAllIndexes(): void
    {
        $this->manager->executeWriteCommand(
            $this->dbName,
            new Command([
                'createIndexes' => $this->collection,
                'indexes'       => [
                    ['key' => ['a' => 1], 'name' => 'a_1'],
                    ['key' => ['b' => -1], 'name' => 'b_-1'],
                ],
            ]),
        );

        $cursor = $this->manager->executeWriteCommand(
            $this->dbName,
            new Command([
                'dropIndexes' => $this->collection,
                'index'       => '*',
            ]),
        );

        $result = (array) iterator_to_array($cursor)[0];
        $this->assertSame(1, (int) ($result['ok'] ?? 0));

        // Only _id_ should remain
        $cursor  = $this->manager->executeReadCommand(
            $this->dbName,
            new Command(['listIndexes' => $this->collection]),
        );
        $indexes = iterator_to_array($cursor);
        $this->assertCount(1, $indexes);
        $this->assertSame('_id_', (string) ((array) $indexes[0])['name']);
    }

    public function testCreateSparseIndex(): void
    {
        $cursor = $this->manager->executeWriteCommand(
            $this->dbName,
            new Command([
                'createIndexes' => $this->collection,
                'indexes'       => [
                    ['key' => ['sparse_field' => 1], 'name' => 'sparse_field_1', 'sparse' => true],
                ],
            ]),
        );

        $result = (array) iterator_to_array($cursor)[0];
        $this->assertSame(1, (int) ($result['ok'] ?? 0));

        // Verify sparse flag is set
        $cursor  = $this->manager->executeReadCommand(
            $this->dbName,
            new Command(['listIndexes' => $this->collection]),
        );
        $indexes = iterator_to_array($cursor);
        $found   = false;
        foreach ($indexes as $idx) {
            $idxArr = (array) $idx;
            if (! (($idxArr['name'] ?? '') === 'sparse_field_1')) {
                continue;
            }

            $this->assertTrue((bool) ($idxArr['sparse'] ?? false));
            $found = true;
        }

        $this->assertTrue($found, 'Sparse index not found in listIndexes output');
    }

    public function testCreateUniqueIndex(): void
    {
        // Insert a document with a unique field
        $bw = new BulkWrite();
        $bw->insert(['unique_field' => 'alpha']);
        $this->manager->executeBulkWrite($this->ns, $bw);

        $cursor = $this->manager->executeWriteCommand(
            $this->dbName,
            new Command([
                'createIndexes' => $this->collection,
                'indexes'       => [
                    ['key' => ['unique_field' => 1], 'name' => 'unique_field_1', 'unique' => true],
                ],
            ]),
        );

        $result = (array) iterator_to_array($cursor)[0];
        $this->assertSame(1, (int) ($result['ok'] ?? 0));

        // Inserting a duplicate must fail
        try {
            $bw2 = new BulkWrite();
            $bw2->insert(['unique_field' => 'alpha']);
            $this->manager->executeBulkWrite(
                $this->ns,
                $bw2,
                ['writeConcern' => new WriteConcern(WriteConcern::MAJORITY)],
            );
            $this->fail('Expected duplicate key exception');
        } catch (BulkWriteException $e) {
            $this->assertStringContainsString('duplicate key', $e->getMessage());
        }
    }
}
