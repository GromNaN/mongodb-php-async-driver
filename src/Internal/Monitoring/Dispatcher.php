<?php

declare(strict_types=1);

namespace MongoDB\Internal\Monitoring;

use Closure;
use Exception;
use MongoDB\BSON\Document;
use MongoDB\BSON\PackedArray;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use MongoDB\Driver\Monitoring\ConnectionCheckedInEvent;
use MongoDB\Driver\Monitoring\ConnectionCheckedOutEvent;
use MongoDB\Driver\Monitoring\ConnectionCheckOutFailedEvent;
use MongoDB\Driver\Monitoring\ConnectionCheckOutStartedEvent;
use MongoDB\Driver\Monitoring\ConnectionClosedEvent;
use MongoDB\Driver\Monitoring\ConnectionCreatedEvent;
use MongoDB\Driver\Monitoring\ConnectionPoolClosedEvent;
use MongoDB\Driver\Monitoring\ConnectionPoolCreatedEvent;
use MongoDB\Driver\Monitoring\ConnectionPoolReadyEvent;
use MongoDB\Driver\Monitoring\ConnectionPoolSubscriber;
use MongoDB\Driver\Monitoring\ConnectionReadyEvent;
use MongoDB\Driver\Monitoring\SDAMSubscriber;
use MongoDB\Driver\Monitoring\Subscriber;
use RuntimeException;
use stdClass;
use Throwable;

use function array_is_list;
use function array_map;
use function in_array;
use function is_array;
use function spl_object_id;
use function strtolower;

/**
 * Single per-manager subscriber registry, also holding the process-wide global
 * subscriber list (previously in {@see GlobalDispatcher}).
 *
 * A single instance is shared between {@see \MongoDB\Internal\Operation\OperationExecutor}
 * and {@see \MongoDB\Internal\Topology\TopologyManager}: calling {@see addSubscriber()}
 * once on this object is enough for both to see the new subscriber.
 *
 * Global subscribers (registered via {@see \MongoDB\Driver\Monitoring\addSubscriber()})
 * are stored in the static {@see $globalSubscribers} array and notified by every
 * {@see dispatch()} call, regardless of which manager-scoped registry fires the event.
 *
 * @internal
 */
final class Dispatcher
{
    /** @var Subscriber[] Process-wide subscribers (replaces GlobalDispatcher::$subscribers). */
    private static array $globalSubscribers = [];

    /** @var array<int, Subscriber> Per-manager subscribers keyed by spl_object_id(). */
    private array $subscribers = [];

    public static function addGlobalSubscriber(Subscriber $subscriber): void
    {
        self::$globalSubscribers[spl_object_id($subscriber)] = $subscriber;
    }

    public static function removeGlobalSubscriber(Subscriber $subscriber): void
    {
        unset(self::$globalSubscribers[spl_object_id($subscriber)]);
    }

    public function addSubscriber(Subscriber $subscriber): void
    {
        $this->subscribers[spl_object_id($subscriber)] = $subscriber;
    }

    public function removeSubscriber(Subscriber $subscriber): void
    {
        unset($this->subscribers[spl_object_id($subscriber)]);
    }

    public function dispatchCommandStarted(
        string $cmdName,
        object $cmd,
        string $db,
        int $requestId,
        string $host,
        int $port,
        ?int $serverConnectionId,
        int $operationId,
    ): void {
        if ($this->isSensitiveCommand($cmdName, $cmd)) {
            $cmd = new stdClass();
        }

        self::dispatch(
            CommandSubscriber::class,
            static fn (CommandSubscriber $subscriber, ?object &$event) => $subscriber->commandStarted(
                $event ??= CommandStartedEvent::create(
                    commandName:        $cmdName,
                    command:            $cmd,
                    databaseName:       $db,
                    requestId:          $requestId,
                    operationId:        $operationId ?: $requestId,
                    host:               $host,
                    port:               $port,
                    serverConnectionId: $serverConnectionId,
                ),
            ),
        );
    }

    public function dispatchCommandSucceeded(
        string $cmdName,
        object $reply,
        string $db,
        int $requestId,
        int $durationMicros,
        string $host,
        int $port,
        ?int $serverConnectionId,
        int $operationId,
    ): void {
        if ($this->isSensitiveCommand($cmdName, $reply)) {
            $reply = new stdClass();
        }

        self::dispatch(
            CommandSubscriber::class,
            static fn (CommandSubscriber $subscriber, ?object &$event) => $subscriber->commandSucceeded(
                $event ??= CommandSucceededEvent::create(
                    commandName:        $cmdName,
                    reply:              $reply,
                    databaseName:       $db,
                    requestId:          $requestId,
                    operationId:        $operationId ?: $requestId,
                    durationMicros:     $durationMicros,
                    host:               $host,
                    port:               $port,
                    serverConnectionId: $serverConnectionId,
                ),
            ),
        );
    }

    public function dispatchCommandFailed(
        string $cmdName,
        Throwable $exception,
        string $db,
        int $requestId,
        int $durationMicros,
        string $host,
        int $port,
        ?object $reply,
        ?int $serverConnectionId,
        int $operationId = 0,
    ): void {
        if ($reply !== null && $this->isSensitiveCommand($cmdName, $reply)) {
            $reply = new stdClass();
        }

        self::dispatch(
            CommandSubscriber::class,
            static fn (CommandSubscriber $subscriber, ?object &$event) => $subscriber->commandFailed(
                $event ??= CommandFailedEvent::create(
                    commandName:        $cmdName,
                    databaseName:       $db,
                    error:              $exception instanceof Exception ? $exception : new RuntimeException($exception->getMessage(), $exception->getCode(), $exception),
                    requestId:          $requestId,
                    operationId:        $operationId ?: $requestId,
                    durationMicros:     $durationMicros,
                    host:               $host,
                    port:               $port,
                    serverConnectionId: $serverConnectionId,
                    reply:              $reply,
                ),
            ),
        );
    }

    // -------------------------------------------------------------------------
    // CMAP (Connection Monitoring and Pooling) typed dispatch methods
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $options */
    public function dispatchConnectionPoolCreated(string $host, int $port, array $options): void
    {
        self::dispatch(
            ConnectionPoolSubscriber::class,
            static fn (ConnectionPoolSubscriber $s, ?object &$e) => $s->connectionPoolCreated($e ??= new ConnectionPoolCreatedEvent($host, $port, $options)),
        );
    }

    public function dispatchConnectionPoolReady(string $host, int $port): void
    {
        self::dispatch(
            ConnectionPoolSubscriber::class,
            static fn (ConnectionPoolSubscriber $s, ?object &$e) => $s->connectionPoolReady($e ??= new ConnectionPoolReadyEvent($host, $port)),
        );
    }

    public function dispatchConnectionPoolClosed(string $host, int $port): void
    {
        self::dispatch(
            ConnectionPoolSubscriber::class,
            static fn (ConnectionPoolSubscriber $s, ?object &$e) => $s->connectionPoolClosed($e ??= new ConnectionPoolClosedEvent($host, $port)),
        );
    }

    public function dispatchConnectionCreated(string $host, int $port, int $connectionId): void
    {
        self::dispatch(
            ConnectionPoolSubscriber::class,
            static fn (ConnectionPoolSubscriber $s, ?object &$e) => $s->connectionCreated($e ??= new ConnectionCreatedEvent($connectionId, $host, $port)),
        );
    }

    public function dispatchConnectionReady(string $host, int $port, int $connectionId, int $durationMicros): void
    {
        self::dispatch(
            ConnectionPoolSubscriber::class,
            static fn (ConnectionPoolSubscriber $s, ?object &$e) => $s->connectionReady($e ??= new ConnectionReadyEvent($connectionId, $host, $port, $durationMicros)),
        );
    }

    /** @param ConnectionClosedEvent::REASON_* $reason */
    public function dispatchConnectionClosed(string $host, int $port, int $connectionId, string $reason): void
    {
        self::dispatch(
            ConnectionPoolSubscriber::class,
            static fn (ConnectionPoolSubscriber $s, ?object &$e) => $s->connectionClosed($e ??= new ConnectionClosedEvent($connectionId, $host, $port, $reason)),
        );
    }

    public function dispatchConnectionCheckOutStarted(string $host, int $port): void
    {
        self::dispatch(
            ConnectionPoolSubscriber::class,
            static fn (ConnectionPoolSubscriber $s, ?object &$e) => $s->connectionCheckOutStarted($e ??= new ConnectionCheckOutStartedEvent($host, $port)),
        );
    }

    /** @param ConnectionCheckOutFailedEvent::REASON_* $reason */
    public function dispatchConnectionCheckOutFailed(string $host, int $port, string $reason, int $durationMicros): void
    {
        self::dispatch(
            ConnectionPoolSubscriber::class,
            static fn (ConnectionPoolSubscriber $s, ?object &$e) => $s->connectionCheckOutFailed($e ??= new ConnectionCheckOutFailedEvent($host, $port, $reason, $durationMicros)),
        );
    }

    public function dispatchConnectionCheckedOut(string $host, int $port, int $connectionId, int $durationMicros): void
    {
        self::dispatch(
            ConnectionPoolSubscriber::class,
            static fn (ConnectionPoolSubscriber $s, ?object &$e) => $s->connectionCheckedOut($e ??= new ConnectionCheckedOutEvent($connectionId, $host, $port, $durationMicros)),
        );
    }

    public function dispatchConnectionCheckedIn(string $host, int $port, int $connectionId): void
    {
        self::dispatch(
            ConnectionPoolSubscriber::class,
            static fn (ConnectionPoolSubscriber $s, ?object &$e) => $s->connectionCheckedIn($e ??= new ConnectionCheckedInEvent($connectionId, $host, $port)),
        );
    }

    /**
     * Dispatch a SDAM monitoring event to all registered subscribers that
     * implement {@see SDAMSubscriber}.
     *
     * The event is created lazily: $factory is only called when at least one
     * SDAMSubscriber is registered, avoiding object allocation in the common
     * case where no monitoring is active.
     *
     * @param string  $method  Method name on SDAMSubscriber (e.g. 'serverChanged').
     * @param Closure $factory Returns the event object when invoked.
     */
    public function dispatchSdamEvent(string $method, Closure $factory): void
    {
        self::dispatch(
            SDAMSubscriber::class,
            static fn (SDAMSubscriber $subscriber, ?object &$event) => $subscriber->{$method}($event ??= $factory()),
        );
    }

    /**
     * Dispatch an event to all subscribers of a given class.
     *
     * Manager-scoped subscribers are notified first; global subscribers follow,
     * skipping any that are already in the manager list to avoid double-notification.
     *
     * The $event parameter passed to the callback is created lazily: the first
     * time a subscriber of the correct class is found, $callback is invoked to
     * create the event object, which is then passed by reference to all
     * subsequent subscribers.  This avoids unnecessary object allocation when
     * no subscribers are registered for the event type.
     *
     * @param class-string<TSubscriber>          $subscriberClass
     * @param callable(TSubscriber, object|null) $callback
     *
     * @template TSubscriber = object
     */
    public function dispatch(string $subscriberClass, Closure $callback): void
    {
        $event = null;
        foreach ($this->subscribers as $subscriber) {
            if (! $subscriber instanceof $subscriberClass) {
                continue;
            }

            try {
                $callback($subscriber, $event);
            } catch (Throwable) {
                // Subscribers must not interfere with operation execution.
            }
        }

        foreach (self::$globalSubscribers as $subscriber) {
            if (! $subscriber instanceof $subscriberClass) {
                continue;
            }

            // Skip if already notified via the manager subscriber list.
            if (in_array($subscriber, $this->subscribers, true)) {
                continue;
            }

            try {
                $callback($subscriber, $event);
            } catch (Throwable) {
                // Subscribers must not interfere with operation execution.
            }
        }
    }

    /**
     * Return true when this command must have its body and reply redacted in APM events.
     *
     * Per the Command Monitoring specification, the following commands are sensitive:
     * authenticate, saslStart, saslContinue, getnonce, createUser, updateUser,
     * copydbgetnonce, copydbsaslstart, copydb, and hello / isMaster when they contain
     * speculativeAuthenticate.
     */
    private function isSensitiveCommand(string $cmdName, object $cmd): bool
    {
        return match (strtolower($cmdName)) {
            'authenticate',
            'saslstart',
            'saslcontinue',
            'getnonce',
            'createuser',
            'updateuser',
            'copydbgetnonce',
            'copydbsaslstart',
            'copydb' => true,

            'hello',
            'ismaster',
            'isMaster' => isset($cmd->speculativeAuthenticate),

            default => false,
        };
    }

    /**
     * Normalise doc-sequence items for APM without a full BSON round-trip.
     *
     * The PackedArray::fromPHP()->toPHP() pattern allocates the entire BSON blob
     * for all items at once, which causes OOM for large batches (100 000+ ops).
     * This method processes each item individually: Document/PackedArray values are
     * decoded via their own toPHP(), PHP arrays with string keys become stdClass,
     * and everything else is returned as-is.  The result matches what the round-trip
     * produces but uses only the memory needed for one decoded item at a time.
     *
     * @param list<mixed> $items
     *
     * @return list<mixed>
     */
    public static function normalizeDocSeqForApm(array $items): array
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if ($value instanceof Document) {
                $arr = $value->toPHP(['root' => 'array', 'document' => 'array']);

                return (object) array_map($normalize, $arr);
            }

            if ($value instanceof PackedArray) {
                return array_map(
                    $normalize,
                    $value->toPHP(['root' => 'array', 'document' => 'array']),
                );
            }

            if (is_array($value)) {
                $normalized = array_map($normalize, $value);

                return array_is_list($value) ? $normalized : (object) $normalized;
            }

            return $value;
        };

        return array_map($normalize, $items);
    }
}
