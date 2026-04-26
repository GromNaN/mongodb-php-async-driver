<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use Exception;
use InvalidArgumentException as PhpInvalidArgumentException;
use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Monitoring\LogSubscriber;
use MongoDB\Driver\Monitoring\Subscriber;
use MongoDB\Internal\Connection\SyncRunner;
use MongoDB\Internal\Monitoring\Dispatcher;
use MongoDB\Internal\Operation\OperationExecutor;
use MongoDB\Internal\Session\SessionPool;
use MongoDB\Internal\Topology\TopologyManager;
use MongoDB\Internal\Uri\ConnectionString;
use MongoDB\Internal\Uri\UriOptions;

use function array_key_exists;
use function array_merge;
use function count;
use function get_debug_type;
use function is_bool;
use function is_int;
use function is_string;
use function MongoDB\Driver\Monitoring\mongoc_log;
use function sprintf;
use function str_ends_with;
use function strlen;
use function strtolower;

final class Manager
{
    private string $uri;
    private ConnectionString $connectionString;
    private UriOptions $uriOptions;
    private TopologyManager $topologyManager;
    private OperationExecutor $executor;
    private ReadPreference $readPreference;
    private WriteConcern $writeConcern;
    private ReadConcern $readConcern;
    private Dispatcher $dispatcher;
    private SessionPool $sessionPool;

    public function __construct(
        ?string $uri = null,
        ?array $uriOptions = null,
        ?array $driverOptions = null,
    ) {
        $uri ??= 'mongodb://127.0.0.1:27017';
        $this->uri = $uri;

        try {
            $this->connectionString = new ConnectionString($uri);
        } catch (PhpInvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }

        // Validate serverApi driver option
        if (isset($driverOptions['serverApi']) && ! ($driverOptions['serverApi'] instanceof ServerApi)) {
            throw InvalidArgumentException::invalidDriverOptionType('serverApi', $driverOptions['serverApi'], ServerApi::class);
        }

        // Validate driver driverOption: name, version, platform must be strings
        if (isset($driverOptions['driver'])) {
            foreach (['name', 'version', 'platform'] as $field) {
                if (isset($driverOptions['driver'][$field]) && ! is_string($driverOptions['driver'][$field])) {
                    throw new InvalidArgumentException(
                        sprintf('Expected "%s" in "driver" driver option to be string, %s given', $field, get_debug_type($driverOptions['driver'][$field])),
                    );
                }
            }
        }

        // Normalize user-provided URI option keys to camelCase before merging.
        // Handle deprecated 'safe' option: convert to 'w' only if 'w' is not explicitly set.
        $normalizedUriOptions = [];
        $safeValue            = null;
        foreach ($uriOptions ?? [] as $key => $value) {
            $normalizedKey = ConnectionString::normalizeOptionKey((string) $key);
            if ($normalizedKey === 'safe') {
                if (! is_bool($value)) {
                    throw new InvalidArgumentException(
                        sprintf('Expected boolean for "safe" URI option, %s given', get_debug_type($value)),
                    );
                }

                $safeValue = $value;
            } else {
                $normalizedUriOptions[$normalizedKey] = $value;
            }
        }

        if ($safeValue !== null && ! array_key_exists('w', $normalizedUriOptions)) {
            $normalizedUriOptions['w'] = $safeValue ? 1 : 0;
        }

        // Merge URI options from connection string with overrides
        $mergedOptions = array_merge(
            $this->connectionString->getOptions(),
            $normalizedUriOptions,
        );

        // Validate constraints that depend on both URI structure and merged options
        $this->validateMergedOptions($mergedOptions);

        $this->uriOptions = UriOptions::fromArray($mergedOptions);

        // Build default read/write/read concern from URI options
        $this->readPreference = $this->buildReadPreference($mergedOptions);
        $this->writeConcern   = $this->buildWriteConcern($mergedOptions);
        $this->readConcern    = $this->buildReadConcern($mergedOptions);

        // Single subscriber registry shared by topology and executor.
        $this->dispatcher = new Dispatcher();

        // Initialize topology manager
        $this->topologyManager = new TopologyManager(
            $this->connectionString->getHosts(),
            $this->uriOptions,
            $this->dispatcher,
        );

        $this->sessionPool = new SessionPool();

        // Create the operation executor
        $this->executor = new OperationExecutor(
            $this->topologyManager,
            $this->uriOptions,
            $this->sessionPool,
            $this->dispatcher,
            $driverOptions['serverApi'] ?? null,
        );

        // Topology start is deferred until first operation so that subscribers
        // registered via addSubscriber() receive the initial SDAM events.

        $this->warnNonGenuineHosts($this->connectionString->getHosts());
    }

    public function __sleep(): array
    {
        throw new Exception("Serialization of 'MongoDB\\Driver\\Manager' is not allowed");
    }

    public function __wakeup(): void
    {
        throw new Exception("Unserialization of 'MongoDB\\Driver\\Manager' is not allowed");
    }

    public function __destruct()
    {
        if (! $this->topologyManager->isStarted()) {
            return;
        }

        $this->topologyManager->stop();
        $this->executor->close();
    }

    public function addSubscriber(Subscriber $subscriber): void
    {
        if ($subscriber instanceof LogSubscriber) {
            throw new InvalidArgumentException('LogSubscriber instances cannot be registered with a Manager');
        }

        $this->dispatcher->addSubscriber($subscriber);
    }

    public function removeSubscriber(Subscriber $subscriber): void
    {
        $this->dispatcher->removeSubscriber($subscriber);
    }

    public function executeBulkWrite(
        string $namespace,
        BulkWrite $bulk,
        array|null $options = null,
    ): WriteResult {
        $explicitWriteConcern = $this->extractWriteConcern($options);
        $writeConcern = $explicitWriteConcern ?? $this->writeConcern;
        $session = $this->extractSession($options);

        return SyncRunner::run(fn () => $this->executor->executeBulkWrite($namespace, $bulk, $writeConcern, $session, $explicitWriteConcern !== null));
    }

    public function executeBulkWriteCommand(
        BulkWriteCommand $bulkWriteCommand,
        ?array $options = null,
    ): BulkWriteCommandResult {
        $writeConcern = $this->extractWriteConcern($options) ?? $this->writeConcern;
        $session      = $this->extractSession($options);

        $result = SyncRunner::run(
            fn () => $this->executor->executeBulkWriteCommand($bulkWriteCommand, $writeConcern, $session),
        );

        $bulkWriteCommand->setSession($session);

        return $result;
    }

    public function executeCommand(
        string $db,
        Command $command,
        array|null $options = null,
    ): CursorInterface {
        $readPreference = $this->extractReadPreference($options);
        $session        = $this->extractSession($options);

        return SyncRunner::run(fn () => $this->executor->executeCommand($db, $command, $readPreference, $session));
    }

    public function executeQuery(
        string $namespace,
        Query $query,
        array|null $options = null,
    ): CursorInterface {
        $readPreference = $this->extractReadPreference($options) ?? $this->readPreference;
        $session        = $this->extractSession($options);

        return SyncRunner::run(fn () => $this->executor->executeQuery($namespace, $query, $readPreference, $session));
    }

    public function executeReadCommand(
        string $db,
        Command $command,
        ?array $options = null,
    ): CursorInterface {
        $readPreference = $this->extractReadPreference($options) ?? $this->readPreference;
        $readConcern    = $this->extractReadConcern($options);
        $session        = $this->extractSession($options);

        return SyncRunner::run(fn () => $this->executor->executeCommand($db, $command, $readPreference, $session, $readConcern));
    }

    public function executeWriteCommand(
        string $db,
        Command $command,
        ?array $options = null,
    ): CursorInterface {
        $writeConcern = $this->extractWriteConcern($options) ?? $this->writeConcern;
        $session      = $this->extractSession($options);

        return SyncRunner::run(fn () => $this->executor->executeCommand($db, $command, null, $session, null, $writeConcern));
    }

    public function executeReadWriteCommand(
        string $db,
        Command $command,
        ?array $options = null,
    ): CursorInterface {
        $readPreference = $this->extractReadPreference($options);
        $readConcern    = $this->extractReadConcern($options);
        $writeConcern   = $this->extractWriteConcern($options) ?? $this->writeConcern;
        $session        = $this->extractSession($options);

        return SyncRunner::run(fn () => $this->executor->executeCommand($db, $command, $readPreference, $session, $readConcern, $writeConcern));
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
                info:              $sd->helloResponse,
                tags:              $sd->tags,
                executor:          $this->executor,
                writeConcern:      $this->writeConcern->isDefault() ? null : $this->writeConcern,
            );
        });
    }

    public function startSession(?array $options = null): Session
    {
        $lsid = $this->sessionPool->acquire();

        return Session::createFromInternal($lsid);
    }

    public function getEncryptedFieldsMap(): array|object|null
    {
        // Not implemented for this driver version
        return null;
    }

    public function createClientEncryption(array $options): ClientEncryption
    {
        return ClientEncryption::create();
    }

    public function __debugInfo(): array
    {
        $cluster = [];

        foreach ($this->topologyManager->getServers() as $sd) {
            $isPrimary   = $sd->type === 'RSPrimary';
            $isSecondary = $sd->type === 'RSSecondary';
            $isArbiter   = $sd->type === 'RSArbiter';
            $isHidden    = (bool) ($sd->helloResponse['hidden'] ?? false);
            $isPassive   = (bool) ($sd->helloResponse['passive'] ?? false);

            $cluster[] = [
                'host'                => $sd->host,
                'port'                => $sd->port,
                'type'                => self::mapInternalServerType($sd->type),
                'is_primary'          => $isPrimary,
                'is_secondary'        => $isSecondary,
                'is_arbiter'          => $isArbiter,
                'is_hidden'           => $isHidden,
                'is_passive'          => $isPassive,
                'last_hello_response' => $sd->helloResponse,
                'round_trip_time'     => $sd->roundTripTimeMs,
            ];
        }

        return [
            'uri'                => $this->uri,
            'cluster'            => $cluster,
            'cryptSharedVersion' => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function validateMergedOptions(array $options): void
    {
        $hostCount        = count($this->connectionString->getHosts());
        $isSrv            = $this->connectionString->isSrv();
        $directConnection = $options['directConnection'] ?? false;
        $loadBalanced     = $options['loadBalanced'] ?? false;

        if ($directConnection) {
            if ($hostCount > 1) {
                throw new InvalidArgumentException(
                    'Failed to parse URI options: Multiple seeds not allowed with directConnection option',
                );
            }

            if ($isSrv) {
                throw new InvalidArgumentException(
                    'Failed to parse URI options: SRV URI not allowed with directConnection option',
                );
            }
        }

        if ($loadBalanced) {
            if ($hostCount > 1) {
                throw new InvalidArgumentException(
                    'Failed to parse URI options: URI with "loadbalanced" enabled must not contain more than one host',
                );
            }

            if (isset($options['replicaSet'])) {
                throw new InvalidArgumentException(
                    'Failed to parse URI options: URI with "loadbalanced" enabled must not contain option "replicaset"',
                );
            }

            if ($directConnection) {
                throw new InvalidArgumentException(
                    'Failed to parse URI options: URI with "loadbalanced" enabled must not contain option "directconnection" enabled',
                );
            }
        }

        // authSource may not be empty
        if (array_key_exists('authSource', $options) && $options['authSource'] === '') {
            throw new InvalidArgumentException(
                'Failed to parse URI options: authSource may not be specified as an empty string',
            );
        }

        // appname max length is 128 bytes
        if (isset($options['appname']) && strlen((string) $options['appname']) > 128) {
            throw new InvalidArgumentException(
                sprintf("Invalid appname value: '%s'", $options['appname']),
            );
        }

        // srvMaxHosts validation
        if (isset($options['srvMaxHosts'])) {
            if (! $isSrv) {
                throw new InvalidArgumentException(
                    'Failed to parse URI options: srvmaxhosts must not be specified with a non-SRV URI',
                );
            }

            if (isset($options['replicaSet'])) {
                throw new InvalidArgumentException(
                    'Failed to parse URI options: srvmaxhosts must not be specified with replicaset',
                );
            }

            if ($loadBalanced) {
                throw new InvalidArgumentException(
                    'Failed to parse URI options: srvmaxhosts must not be specified with loadbalanced=true',
                );
            }
        }

        // srvServiceName validation
        if (isset($options['srvServiceName']) && ! $isSrv) {
            throw new InvalidArgumentException(
                'Failed to parse URI options: srvservicename must not be specified with a non-SRV URI',
            );
        }

        // TLS conflict: tlsInsecure cannot be combined with other TLS options
        if (array_key_exists('tlsInsecure', $options)) {
            $tlsConflicts = ['tlsAllowInvalidCertificates', 'tlsAllowInvalidHostnames', 'tlsDisableOCSPEndpointCheck', 'tlsDisableCertificateRevocationCheck'];
            foreach ($tlsConflicts as $conflictKey) {
                if (array_key_exists($conflictKey, $options)) {
                    throw new InvalidArgumentException(
                        'Failed to parse URI options: tlsinsecure may not be specified with tlsallowinvalidcertificates, tlsallowinvalidhostnames, tlsdisableocspendpointcheck, or tlsdisablecertificaterevocationcheck',
                    );
                }
            }
        }

        // TLS conflict: tlsAllowInvalidCertificates cannot be combined with OCSP/revocation options
        if (! array_key_exists('tlsAllowInvalidCertificates', $options)) {
            return;
        }

        $tlsConflicts = ['tlsDisableOCSPEndpointCheck', 'tlsDisableCertificateRevocationCheck'];
        foreach ($tlsConflicts as $conflictKey) {
            if (array_key_exists($conflictKey, $options)) {
                throw new InvalidArgumentException(
                    'Failed to parse URI options: tlsallowinvalidcertificates may not be specified with tlsdisableocspendpointcheck or tlsdisablecertificaterevocationcheck',
                );
            }
        }
    }

    private function buildReadPreference(array $options): ReadPreference
    {
        $mode = $options['readPreference'] ?? ReadPreference::PRIMARY;
        $tagSets = $options['readPreferenceTags'] ?? null;
        $maxStaleness = $options['maxStalenessSeconds'] ?? null;

        if ($maxStaleness !== null && ! is_int($maxStaleness)) {
            throw new InvalidArgumentException(
                sprintf('Expected integer for "maxStalenessSeconds" URI option, %s given', get_debug_type($maxStaleness)),
            );
        }

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

        // Handle deprecated 'safe' URI option: only if 'w' is not already set
        if ($w === null && isset($options['safe'])) {
            $w = $options['safe'] ? 1 : 0;
        }

        // Empty string w or no w/wtimeout/journal at all → driver default
        if ($w === '' || ($w === null && $wtimeout === null && $journal === null)) {
            return WriteConcern::createDefault();
        }

        return new WriteConcern($w ?? -2, $wtimeout ?? 0, $journal);
    }

    private function buildReadConcern(array $options): ReadConcern
    {
        $level = $options['readConcernLevel'] ?? null;

        if ($level !== null && ! is_string($level)) {
            $typeName = is_int($level) ? '32-bit integer' : get_debug_type($level);

            throw new InvalidArgumentException(
                sprintf('Expected string for "readConcernLevel" URI option, %s given', $typeName),
            );
        }

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

        throw InvalidArgumentException::invalidOptionType('readPreference', $rp, ReadPreference::class);
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

        throw InvalidArgumentException::invalidOptionType('writeConcern', $wc, WriteConcern::class);
    }

    private function extractReadConcern(?array $options): ?ReadConcern
    {
        if ($options === null || ! isset($options['readConcern'])) {
            return null;
        }

        $rc = $options['readConcern'];
        if ($rc instanceof ReadConcern) {
            return $rc;
        }

        throw InvalidArgumentException::invalidOptionType('readConcern', $rc, ReadConcern::class);
    }

    private function extractSession(?array $options): ?Session
    {
        if ($options === null || ! isset($options['session'])) {
            return null;
        }

        $session = $options['session'];
        if ($session instanceof Session) {
            return $session;
        }

        throw InvalidArgumentException::invalidOptionType('session', $session, Session::class);
    }

    /**
     * Emit a log warning if any host in the URI looks like a non-genuine MongoDB provider
     * (CosmosDB or DocumentDB), mirroring the behaviour of libmongoc.
     *
     * @param array<array{host: string, port: int}> $hosts
     */
    private function warnNonGenuineHosts(array $hosts): void
    {
        $cosmosDbSuffix   = '.mongo.cosmos.azure.com';
        $documentDbSuffix = '.docdb.amazonaws.com';
        $documentDbElasticSuffix = '.docdb-elastic.amazonaws.com';

        foreach ($hosts as $hostInfo) {
            $host = strtolower($hostInfo['host']);

            if (str_ends_with($host, $cosmosDbSuffix)) {
                mongoc_log(
                    LogSubscriber::LEVEL_INFO,
                    'mongoc',
                    'You appear to be connected to a CosmosDB cluster. For more information regarding feature compatibility and support please visit https://www.mongodb.com/supportability/cosmosdb',
                );

                return;
            }

            if (str_ends_with($host, $documentDbSuffix) || str_ends_with($host, $documentDbElasticSuffix)) {
                mongoc_log(
                    LogSubscriber::LEVEL_INFO,
                    'mongoc',
                    'You appear to be connected to a DocumentDB cluster. For more information regarding feature compatibility and support please visit https://www.mongodb.com/supportability/documentdb',
                );

                return;
            }
        }
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
