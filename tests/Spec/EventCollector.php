<?php

declare(strict_types=1);

namespace MongoDB\Tests\Spec;

use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;

/**
 * Collects CommandStartedEvents for expectEvents assertions.
 */
final class EventCollector implements CommandSubscriber
{
    /** @var CommandStartedEvent[] */
    private array $events = [];

    public function commandStarted(CommandStartedEvent $event): void
    {
        $this->events[] = $event;
    }

    public function commandSucceeded(CommandSucceededEvent $event): void
    {
    }

    public function commandFailed(CommandFailedEvent $event): void
    {
    }

    /** @return CommandStartedEvent[] */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
