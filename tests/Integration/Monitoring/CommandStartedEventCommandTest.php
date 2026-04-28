<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration\Monitoring;

use MongoDB\BSON\Serializable;
use MongoDB\Driver\BulkWriteCommand;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use MongoDB\Tests\Integration\IntegrationTestCase;
use stdClass;

use function getenv;
use function iterator_to_array;
use function MongoDB\Driver\Monitoring\addSubscriber;
use function MongoDB\Driver\Monitoring\removeSubscriber;
use function sprintf;
use function version_compare;

/**
 * A Serializable document whose bsonSerialize() returns an object with specific fields.
 * This simulates IndexInput (from the PHP library) or similar user objects.
 */
class SerializableDocument implements Serializable
{
    public function __construct(private array $fields)
    {
    }

    public function bsonSerialize(): array|stdClass
    {
        $obj = new stdClass();
        foreach ($this->fields as $k => $v) {
            $obj->$k = $v;
        }

        return $obj;
    }
}

class CommandStartedEventCommandTest extends IntegrationTestCase implements CommandSubscriber
{
    /** @var CommandStartedEvent[] */
    private array $capturedEvents = [];

    public function commandStarted(CommandStartedEvent $event): void
    {
        $this->capturedEvents[] = $event;
    }

    public function commandSucceeded(CommandSucceededEvent $event): void
    {
    }

    public function commandFailed(CommandFailedEvent $event): void
    {
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->capturedEvents = [];
    }

    private function createManager(): Manager
    {
        return new Manager(getenv('MONGODB_URI') ?: 'mongodb://127.0.0.1:27017');
    }

    private function capturedEventFor(string $commandName): ?CommandStartedEvent
    {
        foreach ($this->capturedEvents as $event) {
            if ($event->getCommandName() === $commandName) {
                return $event;
            }
        }

        return null;
    }

    private function requiresServerVersion(Manager $manager, string $minVersion): void
    {
        $cursor  = $manager->executeCommand('admin', new Command(['buildInfo' => 1]));
        $version = (string) (iterator_to_array($cursor)[0]->version ?? '0.0.0');

        if (! version_compare($version, $minVersion, '<')) {
            return;
        }

        $this->markTestSkipped(sprintf('Requires MongoDB %s or later (server is %s)', $minVersion, $version));
    }

    public function testCommandDocumentIsStdClass(): void
    {
        $manager = $this->createManager();

        addSubscriber($this);
        $manager->executeCommand('test', new Command(['ping' => 1]));
        removeSubscriber($this);

        $event = $this->capturedEventFor('ping');
        $this->assertNotNull($event);
        $command = $event->getCommand();
        $this->assertInstanceOf(stdClass::class, $command);
        $this->assertObjectHasProperty('ping', $command);
    }

    public function testSerializableObjectInCommandBecomesStdClass(): void
    {
        $manager = $this->createManager();

        $indexDoc   = new SerializableDocument(['key' => ['x' => 1], 'name' => 'x_1', 'sparse' => true]);
        $commandArr = ['createIndexes' => 'test', 'indexes' => [$indexDoc]];

        addSubscriber($this);
        $manager->executeCommand('test', new Command($commandArr));
        removeSubscriber($this);

        $event = $this->capturedEventFor('createIndexes');
        $this->assertNotNull($event);
        $command = $event->getCommand();
        $this->assertInstanceOf(stdClass::class, $command);

        // The Serializable document in the indexes list must appear as stdClass
        $this->assertIsArray($command->indexes);
        $index = $command->indexes[0];
        $this->assertInstanceOf(stdClass::class, $index);
        $this->assertObjectHasProperty('sparse', $index);
        $this->assertTrue($index->sparse);
    }

    /**
     * bulkWrite sends ops as an OP_MSG kind-1 document sequence.
     * The CommandStartedEvent must reconstruct them as a stdClass list
     * instead of leaving them as raw PHP associative arrays.
     *
     * Requires MongoDB 8.0+ (bulkWrite command).
     */
    public function testBulkWriteDocSequenceOpsAreNormalizedToStdClass(): void
    {
        $manager = $this->createManager();
        $this->requiresServerVersion($manager, '8.0');

        $bulk = new BulkWriteCommand();
        $bulk->insertOne('test.apm_docseq', ['x' => 1]);
        $bulk->updateOne('test.apm_docseq', ['x' => 1], ['$set' => ['y' => 2]]);
        $bulk->deleteOne('test.apm_docseq', ['x' => 1]);

        addSubscriber($this);
        $manager->executeBulkWriteCommand($bulk);
        removeSubscriber($this);

        $event = $this->capturedEventFor('bulkWrite');
        $this->assertNotNull($event);

        $command = $event->getCommand();
        $this->assertInstanceOf(stdClass::class, $command);

        // ops is a list (PHP array), not an associative array
        $this->assertIsArray($command->ops);
        $this->assertNotEmpty($command->ops);

        // Each op must be a stdClass, not a PHP array
        foreach ($command->ops as $op) {
            $this->assertInstanceOf(stdClass::class, $op);
        }

        // Insert op: document must be stdClass
        $insertOp = $command->ops[0];
        $this->assertObjectHasProperty('insert', $insertOp);
        $this->assertInstanceOf(stdClass::class, $insertOp->document);

        // Update op: filter and updateMods must be stdClass
        $updateOp = $command->ops[1];
        $this->assertObjectHasProperty('update', $updateOp);
        $this->assertInstanceOf(stdClass::class, $updateOp->filter);
        $this->assertInstanceOf(stdClass::class, $updateOp->updateMods);

        // Delete op: filter must be stdClass
        $deleteOp = $command->ops[2];
        $this->assertObjectHasProperty('delete', $deleteOp);
        $this->assertInstanceOf(stdClass::class, $deleteOp->filter);
    }
}
