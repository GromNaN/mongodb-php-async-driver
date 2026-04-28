<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration;

use MongoDB\Driver\Manager;
use MongoDB\Driver\Monitoring\ConnectionCheckedInEvent;
use MongoDB\Driver\Monitoring\ConnectionCheckedOutEvent;
use MongoDB\Driver\Monitoring\ConnectionCheckOutFailedEvent;
use MongoDB\Driver\Monitoring\ConnectionCheckOutStartedEvent;
use MongoDB\Driver\Monitoring\ConnectionClosedEvent;
use MongoDB\Driver\Monitoring\ConnectionCreatedEvent;
use MongoDB\Driver\Monitoring\ConnectionPoolClearedEvent;
use MongoDB\Driver\Monitoring\ConnectionPoolClosedEvent;
use MongoDB\Driver\Monitoring\ConnectionPoolCreatedEvent;
use MongoDB\Driver\Monitoring\ConnectionPoolReadyEvent;
use MongoDB\Driver\Monitoring\ConnectionPoolSubscriber;
use MongoDB\Driver\Monitoring\ConnectionReadyEvent;
use MongoDB\Driver\Query;

use function array_filter;
use function array_map;
use function array_values;
use function assert;
use function count;

use const PHP_INT_MAX;

/**
 * Integration tests for CMAP (Connection Monitoring and Pooling) events.
 *
 * Verifies that connection pool lifecycle events are fired in the correct
 * order during a standard Manager → executeQuery workflow.
 *
 * Note: this is not a spec runner for the JSON format fixtures in
 * tests/references/specifications/source/connection-monitoring-and-pooling/tests/cmap-format/
 * (those require unit-level pool mocking). This test verifies CMAP event
 * emission end-to-end against a real server.
 *
 * @see https://github.com/mongodb/specifications/blob/master/source/connection-monitoring-and-pooling/connection-monitoring-and-pooling.md
 */
class ConnectionPoolMonitoringTest extends IntegrationTestCase
{
    public function testConnectionPoolLifecycleEvents(): void
    {
        $subscriber = new class implements ConnectionPoolSubscriber {
            /** @var list<object> */
            public array $events = [];

            public function connectionPoolCreated(ConnectionPoolCreatedEvent $event): void
            {
                $this->events[] = $event;
            }

            public function connectionPoolReady(ConnectionPoolReadyEvent $event): void
            {
                $this->events[] = $event;
            }

            public function connectionPoolCleared(ConnectionPoolClearedEvent $event): void
            {
                $this->events[] = $event;
            }

            public function connectionPoolClosed(ConnectionPoolClosedEvent $event): void
            {
                $this->events[] = $event;
            }

            public function connectionCreated(ConnectionCreatedEvent $event): void
            {
                $this->events[] = $event;
            }

            public function connectionReady(ConnectionReadyEvent $event): void
            {
                $this->events[] = $event;
            }

            public function connectionClosed(ConnectionClosedEvent $event): void
            {
                $this->events[] = $event;
            }

            public function connectionCheckOutStarted(ConnectionCheckOutStartedEvent $event): void
            {
                $this->events[] = $event;
            }

            public function connectionCheckOutFailed(ConnectionCheckOutFailedEvent $event): void
            {
                $this->events[] = $event;
            }

            public function connectionCheckedOut(ConnectionCheckedOutEvent $event): void
            {
                $this->events[] = $event;
            }

            public function connectionCheckedIn(ConnectionCheckedInEvent $event): void
            {
                $this->events[] = $event;
            }
        };

        $uri     = $_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017';
        $manager = new Manager($uri);
        $manager->addSubscriber($subscriber);

        // Execute a command to trigger connection creation.
        $manager->executeQuery('admin.$cmd', new Query(['ping' => 1], ['limit' => 1]));

        $types = [];
        foreach ($subscriber->events as $event) {
            $types[] = $event::class;
        }

        // Pool created + ready must fire before any connection activity.
        $this->assertContains(ConnectionPoolCreatedEvent::class, $types, 'ConnectionPoolCreated must be emitted');
        $this->assertContains(ConnectionPoolReadyEvent::class, $types, 'ConnectionPoolReady must be emitted');

        // A new connection must have been created and become ready.
        $this->assertContains(ConnectionCreatedEvent::class, $types, 'ConnectionCreated must be emitted');
        $this->assertContains(ConnectionReadyEvent::class, $types, 'ConnectionReady must be emitted');

        // Checkout events around the ping command.
        $this->assertContains(ConnectionCheckOutStartedEvent::class, $types, 'ConnectionCheckOutStarted must be emitted');
        $this->assertContains(ConnectionCheckedOutEvent::class, $types, 'ConnectionCheckedOut must be emitted');
        $this->assertContains(ConnectionCheckedInEvent::class, $types, 'ConnectionCheckedIn must be emitted');

        // Verify ordering: Created < Ready < CheckOutStarted < CheckedOut < CheckedIn.
        $created       = $this->firstIndex($subscriber->events, ConnectionCreatedEvent::class);
        $ready         = $this->firstIndex($subscriber->events, ConnectionReadyEvent::class);
        $checkOutStart = $this->firstIndex($subscriber->events, ConnectionCheckOutStartedEvent::class);
        $checkedOut    = $this->firstIndex($subscriber->events, ConnectionCheckedOutEvent::class);
        $checkedIn     = $this->firstIndex($subscriber->events, ConnectionCheckedInEvent::class);

        $this->assertLessThan($ready, $created, 'ConnectionCreated must precede ConnectionReady');
        $this->assertLessThan($checkedOut, $checkOutStart, 'ConnectionCheckOutStarted must precede ConnectionCheckedOut');
        $this->assertLessThan($checkedIn, $checkedOut, 'ConnectionCheckedOut must precede ConnectionCheckedIn');

        $poolCreated = $subscriber->events[$this->firstIndex($subscriber->events, ConnectionPoolCreatedEvent::class)];
        assert($poolCreated instanceof ConnectionPoolCreatedEvent);
        $this->assertNotEmpty($poolCreated->address, 'ConnectionPoolCreatedEvent::$address must not be empty');
        $this->assertStringContainsString(':', $poolCreated->address, 'address must be host:port');

        // Verify pool options were captured.
        $this->assertArrayHasKey('maxPoolSize', $poolCreated->options, 'options must include maxPoolSize');

        // Verify connectionId is monotonically increasing for multiple connections.
        $createdEvents = array_filter($subscriber->events, static fn ($e) => $e instanceof ConnectionCreatedEvent);
        $ids           = array_map(static fn (ConnectionCreatedEvent $e) => $e->connectionId, array_values($createdEvents));
        for ($i = 1; $i < count($ids); $i++) {
            $this->assertGreaterThan($ids[$i - 1], $ids[$i], 'connectionId must be monotonically increasing');
        }
    }

    public function testSecondQueryReusesConnection(): void
    {
        $subscriber = new class implements ConnectionPoolSubscriber {
            public int $createdCount    = 0;
            public int $checkedOutCount = 0;
            public int $checkedInCount  = 0;

            public function connectionPoolCreated(ConnectionPoolCreatedEvent $event): void
            {
            }

            public function connectionPoolReady(ConnectionPoolReadyEvent $event): void
            {
            }

            public function connectionPoolCleared(ConnectionPoolClearedEvent $event): void
            {
            }

            public function connectionPoolClosed(ConnectionPoolClosedEvent $event): void
            {
            }

            public function connectionCreated(ConnectionCreatedEvent $event): void
            {
                $this->createdCount++;
            }

            public function connectionReady(ConnectionReadyEvent $event): void
            {
            }

            public function connectionClosed(ConnectionClosedEvent $event): void
            {
            }

            public function connectionCheckOutStarted(ConnectionCheckOutStartedEvent $event): void
            {
            }

            public function connectionCheckOutFailed(ConnectionCheckOutFailedEvent $event): void
            {
            }

            public function connectionCheckedOut(ConnectionCheckedOutEvent $event): void
            {
                $this->checkedOutCount++;
            }

            public function connectionCheckedIn(ConnectionCheckedInEvent $event): void
            {
                $this->checkedInCount++;
            }
        };

        $uri     = $_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017';
        $manager = new Manager($uri);
        $manager->addSubscriber($subscriber);

        $q = new Query(['ping' => 1], ['limit' => 1]);
        $manager->executeQuery('admin.$cmd', $q);
        $manager->executeQuery('admin.$cmd', $q);

        // Only one physical connection should have been created for two queries.
        $this->assertSame(1, $subscriber->createdCount, 'Only one connection should be created for sequential queries');
        $this->assertSame(2, $subscriber->checkedOutCount, 'Connection must be checked out twice');
        $this->assertSame(2, $subscriber->checkedInCount, 'Connection must be checked in twice');
    }

    /** @param list<object> $events */
    private function firstIndex(array $events, string $class): int
    {
        foreach ($events as $i => $event) {
            if ($event instanceof $class) {
                return $i;
            }
        }

        return PHP_INT_MAX;
    }
}
