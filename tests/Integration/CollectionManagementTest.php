<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\ReadPreference;

use function array_column;
use function array_map;
use function bin2hex;
use function iterator_to_array;
use function random_bytes;
use function sprintf;
use function version_compare;

/**
 * Integration tests for collection and database management commands.
 *
 * Exercises createCollection, dropCollection, listCollections, listDatabases,
 * and dropDatabase via the driver's executeCommand family.
 */
class CollectionManagementTest extends IntegrationTestCase
{
    private Manager $manager;
    private string $dbName;

    protected function setUp(): void
    {
        parent::setUp();

        $uri           = $_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017';
        $this->manager = new Manager($uri);
        $this->dbName  = 'phpunit_coll_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->manager->executeCommand(
            $this->dbName,
            new Command(['dropDatabase' => 1]),
        );
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

    public function testCreateCollection(): void
    {
        $cursor = $this->manager->executeCommand(
            $this->dbName,
            new Command(['create' => 'my_collection']),
        );

        $result = (array) iterator_to_array($cursor)[0];
        $this->assertSame(1, (int) ($result['ok'] ?? 0));
    }

    public function testListCollections(): void
    {
        // Create two collections
        $this->manager->executeCommand($this->dbName, new Command(['create' => 'col_a']));
        $this->manager->executeCommand($this->dbName, new Command(['create' => 'col_b']));

        $cursor      = $this->manager->executeReadCommand(
            $this->dbName,
            new Command(['listCollections' => 1]),
            ['readPreference' => new ReadPreference(ReadPreference::PRIMARY)],
        );
        $collections = iterator_to_array($cursor);
        $names       = array_column(array_map(static fn ($c) => (array) $c, $collections), 'name');

        $this->assertContains('col_a', $names);
        $this->assertContains('col_b', $names);
    }

    public function testDropCollection(): void
    {
        // Create, seed, then drop
        $this->manager->executeCommand($this->dbName, new Command(['create' => 'to_drop']));

        $bw = new BulkWrite();
        $bw->insert(['x' => 1]);
        $this->manager->executeBulkWrite($this->dbName . '.to_drop', $bw);

        $cursor = $this->manager->executeCommand(
            $this->dbName,
            new Command(['drop' => 'to_drop']),
        );
        $result = (array) iterator_to_array($cursor)[0];
        $this->assertSame(1, (int) ($result['ok'] ?? 0));

        // Collection should no longer appear in listCollections
        $cursor      = $this->manager->executeReadCommand(
            $this->dbName,
            new Command(['listCollections' => 1]),
        );
        $collections = iterator_to_array($cursor);
        $names       = array_column(array_map(static fn ($c) => (array) $c, $collections), 'name');
        $this->assertNotContains('to_drop', $names);
    }

    public function testDropDatabase(): void
    {
        // Create a collection to ensure the database exists
        $this->manager->executeCommand($this->dbName, new Command(['create' => 'temp']));

        $cursor = $this->manager->executeCommand(
            $this->dbName,
            new Command(['dropDatabase' => 1]),
        );
        $result = (array) iterator_to_array($cursor)[0];
        $this->assertSame(1, (int) ($result['ok'] ?? 0));

        // The database should no longer appear in listDatabases
        $cursor = $this->manager->executeReadCommand(
            'admin',
            new Command(['listDatabases' => 1]),
            ['readPreference' => new ReadPreference(ReadPreference::PRIMARY)],
        );
        $raw   = (array) iterator_to_array($cursor)[0];
        $dbs   = (array) $raw['databases'];
        $names = array_column(array_map(static fn ($d) => (array) $d, $dbs), 'name');
        $this->assertNotContains($this->dbName, $names);
    }

    public function testListDatabases(): void
    {
        // Ensure database exists with a collection
        $this->manager->executeCommand($this->dbName, new Command(['create' => 'list_db_test']));

        $cursor = $this->manager->executeReadCommand(
            'admin',
            new Command(['listDatabases' => 1]),
            ['readPreference' => new ReadPreference(ReadPreference::PRIMARY)],
        );
        $result = (array) iterator_to_array($cursor)[0];
        $this->assertSame(1, (int) ($result['ok'] ?? 0));
        $this->assertArrayHasKey('databases', $result);

        $dbs   = (array) $result['databases'];
        $names = array_column(array_map(static fn ($d) => (array) $d, $dbs), 'name');
        $this->assertContains($this->dbName, $names);
    }

    public function testCreateCappedCollection(): void
    {
        $cursor = $this->manager->executeCommand(
            $this->dbName,
            new Command([
                'create'  => 'capped_col',
                'capped'  => true,
                'size'    => 1024 * 1024,
                'max'     => 100,
            ]),
        );

        $result = (array) iterator_to_array($cursor)[0];
        $this->assertSame(1, (int) ($result['ok'] ?? 0));

        // Verify via collStats (or collMod) that it is capped
        $cursor = $this->manager->executeReadCommand(
            $this->dbName,
            new Command(['collStats' => 'capped_col']),
        );
        $stats  = (array) iterator_to_array($cursor)[0];
        $this->assertTrue((bool) ($stats['capped'] ?? false));
    }

    public function testCreateCollectionWithValidator(): void
    {
        $this->requiresServerVersion('3.6');

        $cursor = $this->manager->executeCommand(
            $this->dbName,
            new Command([
                'create'    => 'validated_col',
                'validator' => ['name' => ['$type' => 'string']],
            ]),
        );

        $result = (array) iterator_to_array($cursor)[0];
        $this->assertSame(1, (int) ($result['ok'] ?? 0));
    }
}
