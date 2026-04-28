<?php

declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

/**
 * Subscriber interface for Connection Monitoring and Pooling (CMAP) events.
 *
 * Implement this interface and register the instance via
 * {@see \MongoDB\Driver\Monitoring\addSubscriber()} or
 * {@see \MongoDB\Driver\Manager::addSubscriber()} to receive notifications
 * about connection pool lifecycle events.
 */
interface ConnectionPoolSubscriber extends Subscriber
{
    public function connectionPoolCreated(ConnectionPoolCreatedEvent $event): void;

    public function connectionPoolReady(ConnectionPoolReadyEvent $event): void;

    public function connectionPoolCleared(ConnectionPoolClearedEvent $event): void;

    public function connectionPoolClosed(ConnectionPoolClosedEvent $event): void;

    public function connectionCreated(ConnectionCreatedEvent $event): void;

    public function connectionReady(ConnectionReadyEvent $event): void;

    public function connectionClosed(ConnectionClosedEvent $event): void;

    public function connectionCheckOutStarted(ConnectionCheckOutStartedEvent $event): void;

    public function connectionCheckOutFailed(ConnectionCheckOutFailedEvent $event): void;

    public function connectionCheckedOut(ConnectionCheckedOutEvent $event): void;

    public function connectionCheckedIn(ConnectionCheckedInEvent $event): void;
}
