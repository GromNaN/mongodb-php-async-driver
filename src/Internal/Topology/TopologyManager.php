<?php

declare(strict_types=1);

namespace MongoDB\Internal\Topology;

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\RuntimeException as DriverRuntimeException;
use MongoDB\Driver\Monitoring\SDAMSubscriber;
use MongoDB\Internal\Monitoring\GlobalSubscriberRegistry;
use MongoDB\Driver\Monitoring\ServerChangedEvent;
use MongoDB\Driver\Monitoring\ServerClosedEvent;
use MongoDB\Driver\Monitoring\ServerOpeningEvent;
use MongoDB\Driver\Monitoring\Subscriber;
use MongoDB\Driver\Monitoring\TopologyChangedEvent;
use MongoDB\Driver\Monitoring\TopologyClosedEvent;
use MongoDB\Driver\Monitoring\TopologyOpeningEvent;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\ServerDescription;
use MongoDB\Internal\Uri\UriOptions;
use Throwable;

use function Amp\delay;
use function array_filter;
use function array_keys;
use function array_merge;
use function array_rand;
use function array_values;
use function count;
use function implode;
use function method_exists;
use function microtime;
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
        $this->fireSdamEvent('topologyOpening', new TopologyOpeningEvent($this->topologyId));

        foreach ($this->seeds as $seed) {
            $host    = $seed['host'];
            $port    = (int) ($seed['port'] ?? 27017);
            $address = $host . ':' . $port;

            // Register an Unknown placeholder so selectServer can tell the server exists.
            $this->servers[$address] = new InternalServerDescription(
                host: $host,
                port: $port,
                type: InternalServerDescription::TYPE_UNKNOWN,
            );

            $this->fireSdamEvent('serverOpening', new ServerOpeningEvent($host, $port, $this->topologyId));

            $monitor = new ServerMonitor(
                host:                    $host,
                port:                    $port,
                onUpdate:                fn (InternalServerDescription $sd) => $this->onServerUpdate($sd),
                heartbeatFrequencyMs:    $this->options->heartbeatFrequencyMS,
                minHeartbeatFrequencyMs: $this->options->minHeartbeatFrequencyMS,
                onHeartbeat:             fn (string $method, object $event) => $this->fireSdamEvent($method, $event),
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
            $this->fireSdamEvent('serverClosed', new ServerClosedEvent(
                $sd?->host ?? '',
                $sd?->port ?? 0,
                $this->topologyId,
            ));
        }

        $this->monitors = [];
        $this->fireSdamEvent('topologyClosed', new TopologyClosedEvent($this->topologyId));
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
            $this->fireSdamEvent('serverChanged', new ServerChangedEvent(
                host:                $sd->host,
                port:                $sd->port,
                topologyId:          $this->topologyId,
                previousDescription: $this->buildPublicServerDescription($previousSd),
                newDescription:      $this->buildPublicServerDescription($this->servers[$address] ?? $sd),
            ));
        }

        // Fire topologyChanged event if the topology type changed.
        if ($previousType !== $this->topologyType) {
            $this->fireSdamEvent('topologyChanged', new TopologyChangedEvent(
                topologyId:          $this->topologyId,
                previousTopologyType: $previousType->value,
                newTopologyType:      $this->topologyType->value,
            ));
        }

        // Ensure monitors exist for any newly-discovered servers (e.g. RS members).
        foreach ($this->servers as $addr => $knownSd) {
            if (isset($this->monitors[$addr])) {
                continue;
            }

            $this->fireSdamEvent('serverOpening', new ServerOpeningEvent(
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
                onHeartbeat:             fn (string $method, object $event) => $this->fireSdamEvent($method, $event),
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
    }

    /**
     * Poll until a suitable server is found or the timeout expires.
     *
     * @throws DriverRuntimeException on timeout.
     */
    private function waitForServer(ReadPreference $rp, int $timeoutMs): InternalServerDescription
    {
        $deadline = microtime(true) + $timeoutMs / 1_000.0;

        while (microtime(true) < $deadline) {
            delay(0.5); // wait 500 ms before re-checking

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

        throw new DriverRuntimeException(
            sprintf(
                'No suitable servers found for read preference "%s" within %d ms. '
                . 'Topology type: %s, servers: [%s]',
                $rp->getModeString(),
                $timeoutMs,
                $this->topologyType->value,
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
     * @param string $method Method name on SDAMSubscriber (e.g. 'serverChanged').
     * @param object $event  The event object.
     */
    private function fireSdamEvent(string $method, object $event): void
    {
        $allSubscribers = array_merge($this->subscribers, GlobalSubscriberRegistry::getAll());
        foreach ($allSubscribers as $subscriber) {
            if (! ($subscriber instanceof SDAMSubscriber) || ! method_exists($subscriber, $method)) {
                continue;
            }

            try {
                $subscriber->{$method}($event);
            } catch (Throwable) {
                // Subscribers must not interfere with topology management.
            }
        }
    }
}
