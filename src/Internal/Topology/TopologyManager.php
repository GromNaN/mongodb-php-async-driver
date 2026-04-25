<?php

declare(strict_types=1);

namespace MongoDB\Internal\Topology;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Closure;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\ConnectionTimeoutException as DriverConnectionTimeoutException;
use MongoDB\Driver\Exception\RuntimeException as DriverRuntimeException;
use MongoDB\Driver\Monitoring\SDAMSubscriber;
use MongoDB\Driver\Monitoring\ServerChangedEvent;
use MongoDB\Driver\Monitoring\ServerClosedEvent;
use MongoDB\Driver\Monitoring\ServerOpeningEvent;
use MongoDB\Driver\Monitoring\Subscriber;
use MongoDB\Driver\Monitoring\TopologyChangedEvent;
use MongoDB\Driver\Monitoring\TopologyClosedEvent;
use MongoDB\Driver\Monitoring\TopologyOpeningEvent;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\ServerDescription;
use MongoDB\Internal\Monitoring\GlobalSubscriberRegistry;
use MongoDB\Internal\Uri\UriOptions;

use function array_filter;
use function array_keys;
use function array_map;
use function array_rand;
use function array_values;
use function count;
use function hrtime;
use function implode;
use function sprintf;

/**
 * Orchestrates multiple {@see ServerMonitor} instances and maintains the
 * current aggregate view of the MongoDB topology.
 *
 * This class is the single source of truth for topology state.  All mutations
 * go through {@see self::onServerUpdate()}, which applies SDAM transitions via
 * {@see SdamStateMachine} and fires SDAM monitoring events.
 *
 * @internal
 */
final class TopologyManager
{
    private TopologyType $topologyType;

    /** @var array<string, InternalServerDescription> */
    private array $servers = [];

    /** @var array<string, ServerMonitor> */
    private array $monitors = [];

    private ?string $setName;

    /** Unique identifier for this topology instance (for monitoring events). */
    private ObjectId $topologyId;

    /** @var list<Subscriber> */
    private array $subscribers = [];

    private bool $started = false;

    /**
     * Fulfilled by onServerUpdate() to wake a fiber blocked in waitForServer().
     * Mirrors the condition variable used in libmongoc (mongoc_cond_broadcast).
     */
    private ?DeferredFuture $selectionWaiter = null;

    /**
     * @param array<array{host: string, port: int}> $seeds   Seed server list.
     * @param UriOptions                            $options Parsed URI options.
     */
    public function __construct(
        private array $seeds,
        private UriOptions $options,
    ) {
        $this->topologyType = TopologyType::Unknown;
        $this->setName      = $options->replicaSet ?? null;
        $this->topologyId   = new ObjectId();
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Spawn a {@see ServerMonitor} for every seed address and start them.
     */
    public function isStarted(): bool
    {
        return $this->started;
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->fireSdamEvent('topologyOpening', fn () => TopologyOpeningEvent::create($this->topologyId));

        // Register all seed servers as Unknown placeholders so the initial
        // topologyChanged event can include them in newServers.
        foreach ($this->seeds as $seed) {
            $host    = $seed['host'];
            $port    = (int) ($seed['port'] ?? 27017);
            $address = $host . ':' . $port;

            $this->servers[$address] = new InternalServerDescription(
                host: $host,
                port: $port,
                type: InternalServerDescription::TYPE_UNKNOWN,
            );
        }

        // Fire the initial topologyChanged event (empty → seeds known, type stays Unknown).
        // ext-mongodb fires this synchronously after topologyOpening, before serverOpening.
        $initialServers = array_values(array_map(
            fn (InternalServerDescription $s) => $this->buildPublicServerDescription($s),
            $this->servers,
        ));
        $this->fireSdamEvent('topologyChanged', fn () => TopologyChangedEvent::create(
            topologyId:           $this->topologyId,
            previousTopologyType: TopologyType::Unknown->value,
            newTopologyType:      $this->topologyType->value,
            newServers:           $initialServers,
        ));

        // Now fire serverOpening for each seed and start its monitor.
        foreach ($this->seeds as $seed) {
            $host    = $seed['host'];
            $port    = (int) ($seed['port'] ?? 27017);
            $address = $host . ':' . $port;

            $this->fireSdamEvent('serverOpening', fn () => ServerOpeningEvent::create(
                $host,
                $port,
                $this->topologyId,
            ));

            $monitor = new ServerMonitor(
                host:                    $host,
                port:                    $port,
                onUpdate:                fn (InternalServerDescription $sd) => $this->onServerUpdate($sd),
                heartbeatFrequencyMs:    $this->options->heartbeatFrequencyMS,
                minHeartbeatFrequencyMs: $this->options->minHeartbeatFrequencyMS,
                onHeartbeat:             fn (string $method, object $event) => $this->fireSdamEvent($method, static fn () => $event),
                options:                 $this->options,
            );

            $this->monitors[$address] = $monitor;
            $monitor->start();
        }
    }

    /**
     * Stop all monitors and fire the topologyClosed event.
     */
    public function stop(): void
    {
        foreach ($this->monitors as $address => $monitor) {
            $monitor->stop();

            $sd = $this->servers[$address] ?? null;
            $this->fireSdamEvent('serverClosed', fn () => ServerClosedEvent::create(
                $sd?->host ?? '',
                $sd?->port ?? 0,
                $this->topologyId,
            ));
        }

        $this->monitors = [];
        $this->fireSdamEvent('topologyClosed', fn () => TopologyClosedEvent::create($this->topologyId));
    }

    // -------------------------------------------------------------------------
    // Server selection
    // -------------------------------------------------------------------------

    /**
     * Select a server matching the given read preference.
     *
     * If no suitable server is immediately available the method polls every
     * 500 ms until serverSelectionTimeoutMS expires, then throws.
     *
     * @throws DriverRuntimeException when selection times out.
     */
    public function selectServer(
        ReadPreference $readPreference,
        ?int $timeoutMs = null,
    ): InternalServerDescription {
        $this->start();

        $timeoutMs ??= $this->options->serverSelectionTimeoutMS;

        // Fast path: a suitable server is already known.
        $candidates = ServerSelector::select(
            $this->servers,
            $this->topologyType,
            $readPreference,
            $this->options->localThresholdMS,
        );

        if ($candidates !== []) {
            return $this->pickServer($candidates, $readPreference);
        }

        // Slow path: wait for monitors to update the topology.
        return $this->waitForServer($readPreference, $timeoutMs);
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /** @return array<string, InternalServerDescription> */
    public function getServers(): array
    {
        return $this->servers;
    }

    public function getTopologyType(): TopologyType
    {
        return $this->topologyType;
    }

    // -------------------------------------------------------------------------
    // Subscriber management
    // -------------------------------------------------------------------------

    public function addSubscriber(Subscriber $subscriber): void
    {
        $this->subscribers[] = $subscriber;
    }

    public function removeSubscriber(Subscriber $subscriber): void
    {
        $this->subscribers = array_values(
            array_filter($this->subscribers, static fn ($s) => $s !== $subscriber),
        );
    }

    // -------------------------------------------------------------------------
    // Private — SDAM update handling
    // -------------------------------------------------------------------------

    /**
     * Called by each {@see ServerMonitor} after every hello attempt.
     *
     * Applies the SDAM state machine, fires monitoring events, and wakes any
     * fibers that are waiting for a suitable server.
     */
    private function onServerUpdate(InternalServerDescription $sd): void
    {
        $address     = $sd->getAddress();
        $previousSd  = $this->servers[$address] ?? new InternalServerDescription(
            host: $sd->host,
            port: $sd->port,
        );
        $previousType = $this->topologyType;

        // Apply SDAM transition.
        $result = SdamStateMachine::applyServerDescription(
            topologyType:   $this->topologyType,
            servers:        $this->servers,
            newSd:          $sd,
            replicaSetName: $this->setName,
        );

        $this->topologyType = $result['type'];
        $this->servers      = $result['servers'];
        $this->setName      = $result['setName'];

        // Fire serverChanged event if the server description actually changed.
        if (
            $previousSd->type !== ($this->servers[$address]->type ?? InternalServerDescription::TYPE_UNKNOWN)
            || $previousSd->roundTripTimeMs !== ($this->servers[$address]->roundTripTimeMs ?? null)
        ) {
            $this->fireSdamEvent('serverChanged', fn () => ServerChangedEvent::create(
                host:                $sd->host,
                port:                $sd->port,
                topologyId:          $this->topologyId,
                previousDescription: $this->buildPublicServerDescription($previousSd),
                newDescription:      $this->buildPublicServerDescription($this->servers[$address] ?? $sd),
            ));
        }

        // Fire topologyChanged event if the topology type changed.
        if ($previousType !== $this->topologyType) {
            $newServers = array_values(array_map(
                fn (InternalServerDescription $s) => $this->buildPublicServerDescription($s),
                $this->servers,
            ));

            $this->fireSdamEvent('topologyChanged', fn () => TopologyChangedEvent::create(
                topologyId:           $this->topologyId,
                previousTopologyType: $previousType->value,
                newTopologyType:      $this->topologyType->value,
                previousServers:      [],
                newServers:           $newServers,
            ));
        }

        // Ensure monitors exist for any newly-discovered servers (e.g. RS members).
        foreach ($this->servers as $addr => $knownSd) {
            if (isset($this->monitors[$addr])) {
                continue;
            }

            $this->fireSdamEvent('serverOpening', fn () => ServerOpeningEvent::create(
                $knownSd->host,
                $knownSd->port,
                $this->topologyId,
            ));

            $monitor = new ServerMonitor(
                host:                    $knownSd->host,
                port:                    $knownSd->port,
                onUpdate:                fn (InternalServerDescription $s) => $this->onServerUpdate($s),
                heartbeatFrequencyMs:    $this->options->heartbeatFrequencyMS,
                minHeartbeatFrequencyMs: $this->options->minHeartbeatFrequencyMS,
                onHeartbeat:             fn (string $method, object $event) => $this->fireSdamEvent($method, static fn () => $event),
                options:                 $this->options,
            );

            $this->monitors[$addr] = $monitor;
            $monitor->start();
        }

        // Stop monitors for servers that the topology has dropped.
        foreach (array_keys($this->monitors) as $addr) {
            if (isset($this->servers[$addr])) {
                continue;
            }

            $this->monitors[$addr]->stop();
            unset($this->monitors[$addr]);
        }

        // Wake any fiber blocked in waitForServer() — mirrors mongoc_cond_broadcast().
        if ($this->selectionWaiter === null) {
            return;
        }

        $waiter                = $this->selectionWaiter;
        $this->selectionWaiter = null;
        $waiter->complete();
    }

    /**
     * Block until a suitable server is available or the timeout expires.
     *
     * Mirrors the libmongoc approach: instead of polling, we register a
     * DeferredFuture as a "condition variable".  onServerUpdate() completes it
     * (mongoc_cond_broadcast) whenever a topology change arrives, waking this
     * fiber immediately.  We also request an immediate check from every monitor
     * so that one sleeping between heartbeats is woken up at once.
     *
     * @throws DriverRuntimeException on timeout.
     */
    private function waitForServer(ReadPreference $rp, int $timeoutMs): InternalServerDescription
    {
        $deadlineNs = hrtime(true) + $timeoutMs * 1_000_000;

        // Request an immediate check from all monitors (they may be sleeping
        // between heartbeats) — mirrors _mongoc_topology_request_scan().
        foreach ($this->monitors as $monitor) {
            $monitor->requestImmediateCheck();
        }

        while (true) {
            $remainingNs = $deadlineNs - hrtime(true);

            if ($remainingNs <= 0) {
                break;
            }

            $remaining = $remainingNs / 1_000_000_000.0;

            // Register the condition variable and await the next topology update.
            $this->selectionWaiter = new DeferredFuture();

            try {
                $this->selectionWaiter->getFuture()->await(new TimeoutCancellation($remaining));
            } catch (CancelledException) {
                $this->selectionWaiter = null;
                break;
            }

            $candidates = ServerSelector::select(
                $this->servers,
                $this->topologyType,
                $rp,
                $this->options->localThresholdMS,
            );

            if ($candidates !== []) {
                return $this->pickServer($candidates, $rp);
            }
        }

        throw new DriverConnectionTimeoutException(
            sprintf(
                'No suitable servers found (`serverSelectionTryOnce` set): [%s]',
                implode(', ', array_keys($this->servers)),
            ),
        );
    }

    /**
     * Pick one server from a non-empty candidate list.
     *
     * For PRIMARY mode, return the only primary.
     * For all other modes (where multiple servers may qualify), return a random
     * one to distribute load.
     *
     * @param list<InternalServerDescription> $candidates
     */
    private function pickServer(array $candidates, ReadPreference $rp): InternalServerDescription
    {
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        if ($rp->getModeString() === ReadPreference::PRIMARY) {
            return $candidates[0];
        }

        // Random selection among eligible secondaries / nearest servers.
        return $candidates[array_rand($candidates)];
    }

    // -------------------------------------------------------------------------
    // Private — monitoring event helpers
    // -------------------------------------------------------------------------

    /**
     * Build a public {@see \MongoDB\Driver\ServerDescription} from an internal one.
     */
    private function buildPublicServerDescription(InternalServerDescription $sd): ServerDescription
    {
        return ServerDescription::createFromInternal(
            host:           $sd->host,
            port:           $sd->port,
            type:           $sd->type,
            roundTripTime:  $sd->roundTripTimeMs,
            helloResponse:  $sd->helloResponse,
            lastUpdateTime: $sd->lastUpdateTime,
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
    private function fireSdamEvent(string $method, Closure $eventFactory): void
    {
        $event = null;
        GlobalSubscriberRegistry::dispatch(
            $this->subscribers,
            SDAMSubscriber::class,
            static function (SDAMSubscriber $subscriber) use ($method, &$event, $eventFactory): void {
                $subscriber->{$method}($event ??= $eventFactory());
            },
        );
    }
}
