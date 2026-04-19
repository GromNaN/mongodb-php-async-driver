<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver\Monitoring;

use MongoDB\BSON\Serializable;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;

use function MongoDB\Driver\Monitoring\addSubscriber;
use function MongoDB\Driver\Monitoring\removeSubscriber;

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

class CommandStartedEventCommandTest extends TestCase implements CommandSubscriber
{
    private ?CommandStartedEvent $capturedEvent = null;

    public function commandStarted(CommandStartedEvent $event): void
    {
        $this->capturedEvent = $event;
    }

    public function commandSucceeded(CommandSucceededEvent $event): void
    {
    }

    public function commandFailed(CommandFailedEvent $event): void
    {
    }

    protected function setUp(): void
    {
        $this->capturedEvent = null;
    }

    public function testCommandDocumentIsStdClass(): void
    {
        $manager = new Manager('mongodb://127.0.0.1:27017/?serverSelectionTimeoutMS=1');

        addSubscriber($this);

        try {
            $manager->executeCommand('test', new Command(['ping' => 1]));
        } catch (Throwable) {
        }

        removeSubscriber($this);

        $this->assertNotNull($this->capturedEvent);
        $command = $this->capturedEvent->getCommand();
        $this->assertInstanceOf(stdClass::class, $command);
        $this->assertObjectHasProperty('ping', $command);
    }

    public function testSerializableObjectInCommandBecomesStdClass(): void
    {
        $manager = new Manager('mongodb://127.0.0.1:27017/?serverSelectionTimeoutMS=1');

        $indexDoc   = new SerializableDocument(['key' => ['x' => 1], 'name' => 'x_1', 'sparse' => true]);
        $commandArr = ['createIndexes' => 'test', 'indexes' => [$indexDoc]];

        addSubscriber($this);

        try {
            $manager->executeCommand('test', new Command($commandArr));
        } catch (Throwable) {
        }

        removeSubscriber($this);

        $this->assertNotNull($this->capturedEvent);
        $command = $this->capturedEvent->getCommand();
        $this->assertInstanceOf(stdClass::class, $command);

        // The Serializable document in the indexes list must appear as stdClass
        $this->assertIsArray($command->indexes);
        $index = $command->indexes[0];
        $this->assertInstanceOf(stdClass::class, $index);
        $this->assertObjectHasProperty('sparse', $index);
        $this->assertTrue($index->sparse);
    }
}
