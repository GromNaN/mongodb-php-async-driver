<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use InvalidArgumentException as PhpInvalidArgumentException;
use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Driver\Monitoring\Subscriber;
use MongoDB\Internal\Connection\ConnectionPool;
use MongoDB\Internal\Connection\SyncRunner;
use MongoDB\Internal\Operation\OperationExecutor;
use MongoDB\Internal\Topology\TopologyManager;
use MongoDB\Internal\Uri\ConnectionString;
use MongoDB\Internal\Uri\UriOptions;

use function array_merge;
use function array_search;
use function array_values;
use function in_array;
use function is_string;

final class Manager
{
    private ConnectionString $connectionString;
    private UriOptions $uriOptions;
    private TopologyManager $topologyManager;
    private OperationExecutor $executor;
    private ReadPreference $readPreference;
    private WriteConcern $writeConcern;
    private ReadConcern $readConcern;
    /** @var list<ConnectionPool> keyed by "host:port" */
    private array $pools = [];
    /** @var list<Subscriber> */
    private array $subscribers = [];

    public function __construct(
        ?string $uri = null,
        ?array $uriOptions = null,
        ?array $driverOptions = null,
    ) {
        $uri ??= 'mongodb://127.0.0.1:27017';

        try {
            $this->connectionString = new ConnectionString($uri);
        } catch (PhpInvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }

        // Normalize user-provided URI option keys to camelCase before merging
        $normalizedUriOptions = [];
        foreach ($uriOptions ?? [] as $key => $value) {
            $normalizedUriOptions[ConnectionString::normalizeOptionKey((string) $key)] = $value;
        }

        // Merge URI options from connection string with overrides
        $mergedOptions = array_merge(
            $this->connectionString->getOptions(),
            $normalizedUriOptions,
        );
        $this->uriOptions = UriOptions::fromArray($mergedOptions);

        // Build default read/write/read concern from URI options
        $this->readPreference = $this->buildReadPreference($mergedOptions);
        $this->writeConcern   = $this->buildWriteConcern($mergedOptions);
        $this->readConcern    = $this->buildReadConcern($mergedOptions);

        // Initialize topology manager
        $this->topologyManager = new TopologyManager(
            $this->connectionString->getHosts(),
            $this->uriOptions,
        );

        // Create the operation executor
        $this->executor = new OperationExecutor(
            $this->topologyManager,
            $this->uriOptions,
            $this->subscribers,
        );

        // Start server monitoring
        SyncRunner::run(function (): void {
            $this->topologyManager->start();
        });
    }

    public function addSubscriber(Subscriber $subscriber): void
    {
        if (in_array($subscriber, $this->subscribers, true)) {
            return;
        }

        $this->subscribers[] = $subscriber;
        $this->executor->addSubscriber($subscriber);
    }

    public function removeSubscriber(Subscriber $subscriber): void
    {
        $key = array_search($subscriber, $this->subscribers, true);
        if ($key === false) {
            return;
        }

        unset($this->subscribers[$key]);
        $this->subscribers = array_values($this->subscribers);
        $this->executor->removeSubscriber($subscriber);
    }

    public function executeBulkWrite(
        string $namespace,
        BulkWrite $bulk,
        array|null $options = null,
    ): WriteResult {
        $writeConcern = $this->extractWriteConcern($options) ?? $this->writeConcern;
        $session = $this->extractSession($options);

        return SyncRunner::run(fn () => $this->executor->executeBulkWrite($namespace, $bulk, $writeConcern, $session));
    }

    public function executeCommand(
        string $db,
        Command $command,
        array|null $options = null,
    ): CursorInterface {
        $readPreference = $this->extractReadPreference($options);
        $session = $this->extractSession($options);

        return SyncRunner::run(fn () => $this->executor->executeCommand($db, $command, $readPreference, $session));
    }

    public function executeQuery(
        string $namespace,
        Query $query,
        array|null $options = null,
    ): CursorInterface {
        $readPreference = $this->extractReadPreference($options) ?? $this->readPreference;
        $session = $this->extractSession($options);

        return SyncRunner::run(fn () => $this->executor->executeQuery($namespace, $query, $readPreference, $session));
    }

    public function executeReadCommand(
        string $db,
        Command $command,
        ?array $options = null,
    ): CursorInterface {
        if (! isset($options['readPreference'])) {
            $options['readPreference'] = $this->readPreference;
        }

        return $this->executeCommand($db, $command, $options);
    }

    public function executeWriteCommand(
        string $db,
        Command $command,
        ?array $options = null,
    ): CursorInterface {
        if (! isset($options['writeConcern'])) {
            $options['writeConcern'] = $this->writeConcern;
        }

        return $this->executeCommand($db, $command, $options);
    }

    public function executeReadWriteCommand(
        string $db,
        Command $command,
        ?array $options = null,
    ): CursorInterface {
        return $this->executeCommand($db, $command, $options);
    }

    public function getReadConcern(): ReadConcern
    {
        return $this->readConcern;
    }

    public function getReadPreference(): ReadPreference
    {
        return $this->readPreference;
    }

    public function getWriteConcern(): WriteConcern
    {
        return $this->writeConcern;
    }

    public function getServers(): array
    {
        return SyncRunner::run(function () {
            $servers = [];
            foreach ($this->topologyManager->getServers() as $sd) {
                $serverDesc = ServerDescription::createFromInternal(
                    host:           $sd->host,
                    port:           $sd->port,
                    type:           $sd->type,
                    roundTripTime:  $sd->roundTripTimeMs,
                    helloResponse:  $sd->helloResponse,
                    lastUpdateTime: $sd->lastUpdateTime,
                );
                $servers[] = Server::createFromInternal(
                    host:              $sd->host,
                    port:              $sd->port,
                    type:              self::mapInternalServerType($sd->type),
                    latency:           $sd->roundTripTimeMs,
                    serverDescription: $serverDesc,
                    tags:              $sd->tags,
                    executor:          $this->executor,
                );
            }

            return $servers;
        });
    }

    public function selectServer(?ReadPreference $readPreference = null): Server
    {
        $rp = $readPreference ?? $this->readPreference;

        return SyncRunner::run(function () use ($rp) {
            $sd = $this->topologyManager->selectServer($rp);

            $serverDesc = ServerDescription::createFromInternal(
                host:           $sd->host,
                port:           $sd->port,
                type:           $sd->type,
                roundTripTime:  $sd->roundTripTimeMs,
                helloResponse:  $sd->helloResponse,
                lastUpdateTime: $sd->lastUpdateTime,
            );

            return Server::createFromInternal(
                host:              $sd->host,
                port:              $sd->port,
                type:              self::mapInternalServerType($sd->type),
                latency:           $sd->roundTripTimeMs,
                serverDescription: $serverDesc,
                tags:              $sd->tags,
                executor:          $this->executor,
            );
        });
    }

    public function startSession(?array $options = null): Session
    {
        return Session::createFromManager($this, $options ?? []);
    }

    public function getEncryptedFieldsMap(): array|object|null
    {
        // Not implemented for this driver version
        return null;
    }

    public function createClientEncryption(array $options): ClientEncryption
    {
        throw new RuntimeException('Client-side encryption is not supported in this driver');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildReadPreference(array $options): ReadPreference
    {
        $mode = $options['readPreference'] ?? ReadPreference::PRIMARY;
        $tagSets = $options['readPreferenceTags'] ?? null;
        $maxStaleness = $options['maxStalenessSeconds'] ?? null;

        $rpOptions = [];
        if ($maxStaleness !== null) {
            $rpOptions['maxStalenessSeconds'] = $maxStaleness;
        }

        return new ReadPreference($mode, $tagSets, $rpOptions ?: null);
    }

    private function buildWriteConcern(array $options): WriteConcern
    {
        $w        = $options['w'] ?? null;
        $wtimeout = isset($options['wTimeoutMS']) ? (int) $options['wTimeoutMS'] : null;
        $journal  = $options['journal'] ?? null;

        // Empty string w or no w/wtimeout/journal at all → driver default
        if ($w === '' || ($w === null && $wtimeout === null && $journal === null)) {
            return WriteConcern::createDefault();
        }

        return new WriteConcern($w ?? -2, $wtimeout ?? 0, $journal);
    }

    private function buildReadConcern(array $options): ReadConcern
    {
        $level = $options['readConcernLevel'] ?? null;

        return new ReadConcern($level);
    }

    private function extractReadPreference(?array $options): ?ReadPreference
    {
        if ($options === null || ! isset($options['readPreference'])) {
            return null;
        }

        $rp = $options['readPreference'];
        if ($rp instanceof ReadPreference) {
            return $rp;
        }

        if (is_string($rp)) {
            return new ReadPreference($rp);
        }

        return null;
    }

    private function extractWriteConcern(?array $options): ?WriteConcern
    {
        if ($options === null || ! isset($options['writeConcern'])) {
            return null;
        }

        $wc = $options['writeConcern'];
        if ($wc instanceof WriteConcern) {
            return $wc;
        }

        return null;
    }

    private function extractSession(?array $options): ?Session
    {
        return $options['session'] ?? null;
    }

    private static function mapInternalServerType(string $type): int
    {
        return match ($type) {
            'Standalone'      => Server::TYPE_STANDALONE,
            'Mongos'          => Server::TYPE_MONGOS,
            'PossiblePrimary' => Server::TYPE_POSSIBLE_PRIMARY,
            'RSPrimary'       => Server::TYPE_RS_PRIMARY,
            'RSSecondary'     => Server::TYPE_RS_SECONDARY,
            'RSArbiter'       => Server::TYPE_RS_ARBITER,
            'RSOther'         => Server::TYPE_RS_OTHER,
            'RSGhost'         => Server::TYPE_RS_GHOST,
            'LoadBalancer'    => Server::TYPE_LOAD_BALANCER,
            default           => Server::TYPE_UNKNOWN,
        };
    }
}
