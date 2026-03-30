<?php declare(strict_types=1);

namespace MongoDB\Driver;

use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Driver\Monitoring\Subscriber;
use MongoDB\Internal\Connection\ConnectionPool;
use MongoDB\Internal\Connection\SyncRunner;
use MongoDB\Internal\Operation\OperationExecutor;
use MongoDB\Internal\Topology\TopologyManager;
use MongoDB\Internal\Uri\ConnectionString;
use MongoDB\Internal\Uri\UriOptions;

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
        } catch (\InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }

        // Merge URI options from connection string with overrides
        $mergedOptions = array_merge(
            $this->connectionString->getOptions(),
            $uriOptions ?? [],
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
        SyncRunner::run(function () {
            $this->topologyManager->start();
        });
    }

    public function addSubscriber(Subscriber $subscriber): void
    {
        if (!in_array($subscriber, $this->subscribers, true)) {
            $this->subscribers[] = $subscriber;
            $this->executor->addSubscriber($subscriber);
        }
    }

    public function removeSubscriber(Subscriber $subscriber): void
    {
        $key = array_search($subscriber, $this->subscribers, true);
        if ($key !== false) {
            unset($this->subscribers[$key]);
            $this->subscribers = array_values($this->subscribers);
            $this->executor->removeSubscriber($subscriber);
        }
    }

    public function executeBulkWrite(
        string $namespace,
        BulkWrite $bulk,
        array|null $options = null,
    ): WriteResult {
        $writeConcern = $this->extractWriteConcern($options) ?? $this->writeConcern;
        $session = $this->extractSession($options);

        return SyncRunner::run(function () use ($namespace, $bulk, $writeConcern, $session) {
            return $this->executor->executeBulkWrite($namespace, $bulk, $writeConcern, $session);
        });
    }

    public function executeCommand(
        string $db,
        Command $command,
        array|null $options = null,
    ): CursorInterface {
        $readPreference = $this->extractReadPreference($options);
        $session = $this->extractSession($options);

        return SyncRunner::run(function () use ($db, $command, $readPreference, $session) {
            return $this->executor->executeCommand($db, $command, $readPreference, $session);
        });
    }

    public function executeQuery(
        string $namespace,
        Query $query,
        array|null $options = null,
    ): CursorInterface {
        $readPreference = $this->extractReadPreference($options) ?? $this->readPreference;
        $session = $this->extractSession($options);

        return SyncRunner::run(function () use ($namespace, $query, $readPreference, $session) {
            return $this->executor->executeQuery($namespace, $query, $readPreference, $session);
        });
    }

    public function executeReadCommand(
        string $db,
        Command $command,
        ?array $options = null,
    ): CursorInterface {
        if (!isset($options['readPreference'])) {
            $options['readPreference'] = $this->readPreference;
        }
        return $this->executeCommand($db, $command, $options);
    }

    public function executeWriteCommand(
        string $db,
        Command $command,
        ?array $options = null,
    ): CursorInterface {
        if (!isset($options['writeConcern'])) {
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
                $servers[] = Server::_createFromDescription($sd, $this->executor);
            }
            return $servers;
        });
    }

    public function selectServer(?ReadPreference $readPreference = null): Server
    {
        $rp = $readPreference ?? $this->readPreference;
        return SyncRunner::run(function () use ($rp) {
            $sd = $this->topologyManager->selectServer($rp);
            return Server::_createFromDescription($sd, $this->executor);
        });
    }

    public function startSession(?array $options = null): Session
    {
        return Session::_createFromManager($this, $options ?? []);
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
        if (!isset($options['w']) && !isset($options['wTimeoutMS']) && !isset($options['journal'])) {
            return new WriteConcern(1); // default: w=1
        }

        $w = $options['w'] ?? 1;
        $wtimeout = isset($options['wTimeoutMS']) ? (int)$options['wTimeoutMS'] : 0;
        $journal = $options['journal'] ?? null;

        return new WriteConcern($w, $wtimeout, $journal);
    }

    private function buildReadConcern(array $options): ReadConcern
    {
        $level = $options['readConcernLevel'] ?? null;
        return new ReadConcern($level);
    }

    private function extractReadPreference(?array $options): ?ReadPreference
    {
        if ($options === null || !isset($options['readPreference'])) {
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
        if ($options === null || !isset($options['writeConcern'])) {
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
}
