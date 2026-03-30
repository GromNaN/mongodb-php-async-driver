<?php

declare(strict_types=1);

namespace MongoDB\Internal\Operation;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\CursorInterface;
use MongoDB\Driver\Exception\RuntimeException as DriverRuntimeException;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use MongoDB\Driver\Monitoring\Subscriber;
use MongoDB\Driver\Query;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\Server;
use MongoDB\Driver\ServerDescription;
use MongoDB\Driver\Session;
use MongoDB\Driver\WriteConcern;
use MongoDB\Driver\WriteConcernError;
use MongoDB\Driver\WriteError;
use MongoDB\Driver\WriteResult;
use MongoDB\Internal\Connection\ConnectionPool;
use MongoDB\Internal\Protocol\OpMsgDecoder;
use MongoDB\Internal\Protocol\OpMsgEncoder;
use MongoDB\Internal\Protocol\RequestIdGenerator;
use MongoDB\Internal\Topology\InternalServerDescription;
use MongoDB\Internal\Topology\TopologyManager;
use MongoDB\Internal\Uri\UriOptions;
use Throwable;

use function array_map;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function is_array;
use function iterator_to_array;
use function microtime;
use function strpos;
use function substr;

/**
 * Central execution layer for all MongoDB operations.
 *
 * Orchestrates:
 *   - Server selection via {@see TopologyManager}
 *   - Connection acquisition via per-server {@see ConnectionPool} instances
 *   - Command preparation via {@see CommandHelper}
 *   - Wire-protocol encoding / decoding
 *   - Command monitoring (CommandStarted / CommandSucceeded / CommandFailed)
 *   - Result materialisation (Cursor, WriteResult)
 *
 * @internal
 */
final class OperationExecutor
{
    /** @var array<string, ConnectionPool> Keyed by "host:port". */
    private array $pools = [];

    /**
     * @param TopologyManager  $topology    Live topology manager.
     * @param UriOptions       $options     Parsed URI options.
     * @param list<Subscriber> $subscribers Monitoring subscribers.
     */
    public function __construct(
        private TopologyManager $topology,
        private UriOptions $options,
        private array $subscribers = [],
    ) {
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Execute a command and return a cursor over the result set.
     */
    public function executeCommand(
        string $db,
        Command $command,
        ?ReadPreference $readPreference = null,
        ?Session $session = null,
    ): CursorInterface {
        $readPreference ??= new ReadPreference(ReadPreference::PRIMARY);

        $server = $this->topology->selectServer($readPreference);
        $pool   = $this->getOrCreatePool($server->host, $server->port);

        $rawCmd  = $command->getDocument();
        $cmdName = CommandHelper::getCommandName($rawCmd);

        $prepared = CommandHelper::prepareCommand(
            command:        $rawCmd,
            db:             $db,
            readPreference: $readPreference,
            session:        $session,
        );

        return $this->sendCommand($pool, $db, $cmdName, $prepared, $server);
    }

    /**
     * Execute a query and return a cursor over the matched documents.
     */
    public function executeQuery(
        string $namespace,
        Query $query,
        ?ReadPreference $readPreference = null,
        ?Session $session = null,
    ): CursorInterface {
        [$db, $collection] = $this->splitNamespace($namespace);

        $readPreference ??= new ReadPreference(ReadPreference::PRIMARY);

        // Build a find command from the Query object.
        $findCmd = ['find' => $collection, 'filter' => $query->getFilter()];

        $opts = $query->getOptions();

        foreach (
            [
                'sort', 'projection', 'skip', 'limit', 'batchSize',
                'singleBatch', 'comment', 'maxTimeMS', 'hint',
                'allowPartialResults', 'noCursorTimeout', 'tailable',
                'awaitData', 'oplogReplay', 'returnKey', 'showRecordId',
                'snapshot', 'min', 'max',
            ] as $optKey
        ) {
            if (! isset($opts[$optKey])) {
                continue;
            }

            $findCmd[$optKey] = $opts[$optKey];
        }

        $server = $this->topology->selectServer($readPreference);
        $pool   = $this->getOrCreatePool($server->host, $server->port);

        $prepared = CommandHelper::prepareCommand(
            command:        $findCmd,
            db:             $db,
            readPreference: $readPreference,
            session:        $session,
        );

        return $this->sendCommand($pool, $db, 'find', $prepared, $server);
    }

    /**
     * Execute a bulk write and return an aggregated WriteResult.
     */
    public function executeBulkWrite(
        string $namespace,
        BulkWrite $bulk,
        ?WriteConcern $writeConcern = null,
        ?Session $session = null,
    ): WriteResult {
        [$db, $collection] = $this->splitNamespace($namespace);

        $server = $this->topology->selectServer(new ReadPreference(ReadPreference::PRIMARY));
        $pool   = $this->getOrCreatePool($server->host, $server->port);

        $ordered    = (bool) ($bulk->getOptions()['ordered'] ?? true);
        $operations = $bulk->getOperations();

        // Accumulators for the aggregate WriteResult.
        $totalInserted  = 0;
        $totalMatched   = 0;
        $totalModified  = 0;
        $totalDeleted   = 0;
        $totalUpserted  = 0;
        $upsertedIds    = [];
        $writeErrors    = [];
        $wcError        = null;
        $acknowledged   = true;

        // Group operations into insert / update / delete batches to minimise
        // round-trips.  For ordered bulk writes we send each type sequentially
        // to preserve ordering semantics; for unordered we collect into three
        // separate commands.
        $inserts = [];
        $updates = [];
        $deletes = [];

        foreach ($operations as $op) {
            [$type] = $op;

            if ($type === 'insert') {
                $inserts[] = $op;
            } elseif ($type === 'update') {
                $updates[] = $op;
            } elseif ($type === 'delete') {
                $deletes[] = $op;
            }
        }

        // --- INSERT ---
        if ($inserts !== []) {
            $docs = array_map(static fn ($op) => $op[1], $inserts);

            $insertCmd = CommandHelper::prepareCommand(
                command:      ['insert' => $collection, 'documents' => $docs, 'ordered' => $ordered],
                db:           $db,
                writeConcern: $writeConcern,
                session:      $session,
            );

            try {
                $cursor = $this->sendCommand($pool, $db, 'insert', $insertCmd, $server);
                $result = iterator_to_array($cursor)[0] ?? [];

                $totalInserted += (int) ($result['n'] ?? count($docs));
                $acknowledged   = ! ($result['acknowledged'] ?? true) ? false : $acknowledged;

                foreach ((array) ($result['writeErrors'] ?? []) as $e) {
                    $writeErrors[] = new WriteError(
                        code:    (int) ($e['code']    ?? 0),
                        index:   (int) ($e['index']   ?? 0),
                        message: (string) ($e['errmsg']  ?? ''),
                    );
                }

                if (isset($result['writeConcernError'])) {
                    $wce     = $result['writeConcernError'];
                    $wcError = new WriteConcernError(
                        code:    (int) ($wce['code']   ?? 0),
                        message: (string) ($wce['errmsg'] ?? ''),
                    );
                }
            } catch (Throwable $e) {
                if ($ordered) {
                    throw $e;
                }
            }
        }

        // --- UPDATE ---
        if ($updates !== []) {
            $updateSpecs = array_map(static function ($op): array {
                [, $filter, $newObj, $opts] = $op;
                $spec = ['q' => $filter, 'u' => $newObj];

                if ($opts['multi']  ?? false) {
                    $spec['multi']  = true;
                }

                if ($opts['upsert'] ?? false) {
                    $spec['upsert'] = true;
                }

                if (isset($opts['arrayFilters'])) {
                    $spec['arrayFilters'] = $opts['arrayFilters'];
                }

                if (isset($opts['hint'])) {
                    $spec['hint'] = $opts['hint'];
                }

                if (isset($opts['collation'])) {
                    $spec['collation'] = $opts['collation'];
                }

                return $spec;
            }, $updates);

            $updateCmd = CommandHelper::prepareCommand(
                command:      ['update' => $collection, 'updates' => $updateSpecs, 'ordered' => $ordered],
                db:           $db,
                writeConcern: $writeConcern,
                session:      $session,
            );

            try {
                $cursor = $this->sendCommand($pool, $db, 'update', $updateCmd, $server);
                $result = iterator_to_array($cursor)[0] ?? [];

                $totalMatched  += (int) ($result['n']        ?? 0);
                $totalModified += (int) ($result['nModified'] ?? 0);

                foreach ((array) ($result['upserted'] ?? []) as $upserted) {
                    $idx                = (int) $upserted['index'];
                    $upsertedIds[$idx]  = $upserted['_id'];
                    ++$totalUpserted;
                }

                foreach ((array) ($result['writeErrors'] ?? []) as $e) {
                    $writeErrors[] = new WriteError(
                        code:    (int) ($e['code']   ?? 0),
                        index:   (int) ($e['index']  ?? 0),
                        message: (string) ($e['errmsg'] ?? ''),
                    );
                }

                if (isset($result['writeConcernError']) && $wcError === null) {
                    $wce     = $result['writeConcernError'];
                    $wcError = new WriteConcernError(
                        code:    (int) ($wce['code']   ?? 0),
                        message: (string) ($wce['errmsg'] ?? ''),
                    );
                }
            } catch (Throwable $e) {
                if ($ordered) {
                    throw $e;
                }
            }
        }

        // --- DELETE ---
        if ($deletes !== []) {
            $deleteSpecs = array_map(static function ($op): array {
                [, $filter, $opts] = $op;
                $limit = $opts['limit'] ?? 1 ? 1 : 0;
                $spec  = ['q' => $filter, 'limit' => $limit];

                if (isset($opts['collation'])) {
                    $spec['collation'] = $opts['collation'];
                }

                if (isset($opts['hint'])) {
                    $spec['hint'] = $opts['hint'];
                }

                return $spec;
            }, $deletes);

            $deleteCmd = CommandHelper::prepareCommand(
                command:      ['delete' => $collection, 'deletes' => $deleteSpecs, 'ordered' => $ordered],
                db:           $db,
                writeConcern: $writeConcern,
                session:      $session,
            );

            try {
                $cursor = $this->sendCommand($pool, $db, 'delete', $deleteCmd, $server);
                $result = iterator_to_array($cursor)[0] ?? [];

                $totalDeleted += (int) ($result['n'] ?? 0);

                foreach ((array) ($result['writeErrors'] ?? []) as $e) {
                    $writeErrors[] = new WriteError(
                        code:    (int) ($e['code']   ?? 0),
                        index:   (int) ($e['index']  ?? 0),
                        message: (string) ($e['errmsg'] ?? ''),
                    );
                }

                if (isset($result['writeConcernError']) && $wcError === null) {
                    $wce     = $result['writeConcernError'];
                    $wcError = new WriteConcernError(
                        code:    (int) ($wce['code']   ?? 0),
                        message: (string) ($wce['errmsg'] ?? ''),
                    );
                }
            } catch (Throwable $e) {
                if ($ordered) {
                    throw $e;
                }
            }
        }

        // Build the public Server object for WriteResult.
        $publicServer = $this->buildPublicServer($server);

        return WriteResult::createFromInternal(
            insertedCount:   $totalInserted,
            matchedCount:    $totalMatched,
            modifiedCount:   $totalModified,
            deletedCount:    $totalDeleted,
            upsertedCount:   $totalUpserted,
            upsertedIds:     $upsertedIds,
            server:          $publicServer,
            writeConcernError: $wcError,
            writeErrors:     $writeErrors,
            acknowledged:    $acknowledged,
        );
    }

    // -------------------------------------------------------------------------
    // Private — core send/receive
    // -------------------------------------------------------------------------

    /**
     * Send a prepared command document over a connection from $pool and return
     * a CursorInterface over the decoded results.
     *
     * Fires CommandStarted / CommandSucceeded / CommandFailed events.
     *
     * @param array $prepared Fully-decorated command array (output of CommandHelper::prepareCommand).
     */
    private function sendCommand(
        ConnectionPool $pool,
        string $db,
        string $cmdName,
        array $prepared,
        InternalServerDescription $server,
    ): CursorInterface {
        $conn      = $pool->acquire();
        $requestId = RequestIdGenerator::next();
        $startUs   = (int) (microtime(true) * 1_000_000);

        $this->fireCommandStarted($cmdName, (object) $prepared, $db, $requestId);

        try {
            [$bytes] = OpMsgEncoder::encodeWithRequestId($prepared);

            $responseBytes = $conn->sendMessage($bytes);
            $durationUs    = (int) (microtime(true) * 1_000_000) - $startUs;

            $decoded = OpMsgDecoder::decodeAndCheck($responseBytes);

            // Normalise body to array for uniform handling.
            $body = is_array($decoded) ? $decoded : (array) $decoded;

            $this->fireCommandSucceeded($cmdName, (object) $body, $db, $requestId, $durationUs);

            $pool->release($conn);

            return $this->buildCursor($body, $db, $cmdName, $pool, $server);
        } catch (Throwable $e) {
            $durationUs = (int) (microtime(true) * 1_000_000) - $startUs;
            $this->fireCommandFailed($cmdName, $e, $db, $requestId, $durationUs);

            $pool->release($conn);

            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Private — cursor construction
    // -------------------------------------------------------------------------

    /**
     * Build a CursorInterface from a decoded server response.
     *
     * For commands that return a cursor sub-document (find, aggregate, …) we
     * transparently issue getMore commands as the caller iterates.
     * For other commands (insert, update, delete, …) we wrap the single
     * response document in a one-element array cursor.
     *
     * @param array $body Decoded command response body.
     */
    private function buildCursor(
        array $body,
        string $db,
        string $cmdName,
        ConnectionPool $pool,
        InternalServerDescription $server,
    ): CursorInterface {
        // Commands that return a cursor sub-document.
        if (isset($body['cursor']) && is_array($body['cursor'])) {
            $cursorDoc = $body['cursor'];
            $cursorId  = $cursorDoc['id'] ?? 0;
            $ns        = $cursorDoc['ns'] ?? $db;
            $firstBatch = $cursorDoc['firstBatch'] ?? [];

            return new class (
                $firstBatch,
                (int) $cursorId,
                (string) $ns,
                $db,
                $pool,
                $server,
            ) implements CursorInterface {
                /** @var list<array|object> */
                private array $buffer;
                private int $position = 0;

                public function __construct(
                    array $firstBatch,
                    private int $cursorId,
                    private string $ns,
                    private string $db,
                    private ConnectionPool $pool,
                    private InternalServerDescription $server,
                ) {
                    $this->buffer = $firstBatch;
                }

                public function current(): mixed
                {
                    return $this->buffer[$this->position];
                }

                public function key(): int
                {
                    return $this->position;
                }

                public function next(): void
                {
                    ++$this->position;

                    // Fetch next batch when the local buffer is exhausted.
                    if ($this->position < count($this->buffer) || $this->cursorId === 0) {
                        return;
                    }

                    $this->fetchNextBatch();
                }

                public function rewind(): void
                {
                    $this->position = 0;
                }

                public function valid(): bool
                {
                    return isset($this->buffer[$this->position]);
                }

                private function fetchNextBatch(): void
                {
                    $conn = $this->pool->acquire();

                    try {
                        $getMoreCmd = [
                            'getMore'    => $this->cursorId,
                            'collection' => explode('.', $this->ns, 2)[1] ?? $this->ns,
                            '$db'        => $this->db,
                        ];

                        [$bytes] = OpMsgEncoder::encodeWithRequestId($getMoreCmd);
                        $responseBytes = $conn->sendMessage($bytes);
                        $decoded = OpMsgDecoder::decodeAndCheck($responseBytes);
                        $body    = is_array($decoded) ? $decoded : (array) $decoded;

                        $this->cursorId = (int) ($body['cursor']['id'] ?? 0);
                        $nextBatch      = $body['cursor']['nextBatch'] ?? [];

                        // Append new batch, drop already-iterated documents.
                        $this->buffer   = array_values(array_slice($this->buffer, $this->position));
                        foreach ($nextBatch as $doc) {
                            $this->buffer[] = $doc;
                        }

                        $this->position = 0;
                    } finally {
                        $this->pool->release($conn);
                    }
                }
            };
        }

        // Non-cursor commands: return the single response document as a one-element cursor.
        return new class ([$body]) implements CursorInterface {
            private int $position = 0;

            public function __construct(private readonly array $items)
            {
            }

            public function current(): mixed
            {
                return $this->items[$this->position];
            }

            public function key(): int
            {
                return $this->position;
            }

            public function next(): void
            {
                ++$this->position;
            }

            public function rewind(): void
            {
                $this->position = 0;
            }

            public function valid(): bool
            {
                return isset($this->items[$this->position]);
            }
        };
    }

    // -------------------------------------------------------------------------
    // Private — pool management
    // -------------------------------------------------------------------------

    /**
     * Return (or lazily create) the ConnectionPool for a given server address.
     */
    private function getOrCreatePool(string $host, int $port): ConnectionPool
    {
        $address = $host . ':' . $port;

        if (! isset($this->pools[$address])) {
            $this->pools[$address] = new ConnectionPool(
                host:               $host,
                port:               $port,
                maxPoolSize:        $this->options->maxPoolSize,
                minPoolSize:        $this->options->minPoolSize,
                waitQueueTimeoutMS: $this->options->waitQueueTimeoutMS,
                options:            $this->options,
            );
        }

        return $this->pools[$address];
    }

    // -------------------------------------------------------------------------
    // Private — monitoring helpers
    // -------------------------------------------------------------------------

    private function fireCommandStarted(
        string $cmdName,
        object $cmd,
        string $db,
        int $requestId,
    ): void {
        $event = new CommandStartedEvent(
            commandName: $cmdName,
            command:     $cmd,
            databaseName: $db,
            requestId:   $requestId,
            operationId: $requestId,
        );

        foreach ($this->subscribers as $subscriber) {
            if (! ($subscriber instanceof CommandSubscriber)) {
                continue;
            }

            try {
                $subscriber->commandStarted($event);
            } catch (Throwable) {
                // Subscribers must not interfere with operation execution.
            }
        }
    }

    private function fireCommandSucceeded(
        string $cmdName,
        object $reply,
        string $db,
        int $requestId,
        int $durationMicros,
    ): void {
        $event = new CommandSucceededEvent(
            commandName:  $cmdName,
            reply:        $reply,
            databaseName: $db,
            requestId:    $requestId,
            operationId:  $requestId,
            durationMicros: $durationMicros,
        );

        foreach ($this->subscribers as $subscriber) {
            if (! ($subscriber instanceof CommandSubscriber)) {
                continue;
            }

            try {
                $subscriber->commandSucceeded($event);
            } catch (Throwable) {
                // Subscribers must not interfere with operation execution.
            }
        }
    }

    private function fireCommandFailed(
        string $cmdName,
        Throwable $e,
        string $db,
        int $requestId,
        int $durationMicros,
    ): void {
        $event = new CommandFailedEvent(
            commandName:  $cmdName,
            databaseName: $db,
            error:        $e,
            requestId:    $requestId,
            operationId:  $requestId,
            durationMicros: $durationMicros,
        );

        foreach ($this->subscribers as $subscriber) {
            if (! ($subscriber instanceof CommandSubscriber)) {
                continue;
            }

            try {
                $subscriber->commandFailed($event);
            } catch (Throwable) {
                // Subscribers must not interfere with operation execution.
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private — misc helpers
    // -------------------------------------------------------------------------

    /**
     * Split "db.collection" into ['db', 'collection'].
     *
     * @return array{string, string}
     */
    private function splitNamespace(string $namespace): array
    {
        $pos = strpos($namespace, '.');
        if ($pos === false) {
            throw new DriverRuntimeException(
                'Invalid namespace "' . $namespace . '": missing dot separator',
            );
        }

        return [substr($namespace, 0, $pos), substr($namespace, $pos + 1)];
    }

    /**
     * Build a public {@see \MongoDB\Driver\Server} from an internal server description.
     */
    private function buildPublicServer(InternalServerDescription $sd): Server
    {
        // Map the internal type string to the public integer constant.
        $typeMap = [
            InternalServerDescription::TYPE_STANDALONE   => Server::TYPE_STANDALONE,
            InternalServerDescription::TYPE_MONGOS        => Server::TYPE_MONGOS,
            InternalServerDescription::TYPE_RS_PRIMARY    => Server::TYPE_RS_PRIMARY,
            InternalServerDescription::TYPE_RS_SECONDARY  => Server::TYPE_RS_SECONDARY,
            InternalServerDescription::TYPE_RS_ARBITER    => Server::TYPE_RS_ARBITER,
            InternalServerDescription::TYPE_RS_OTHER      => Server::TYPE_RS_OTHER,
            InternalServerDescription::TYPE_RS_GHOST      => Server::TYPE_RS_GHOST,
            InternalServerDescription::TYPE_LOAD_BALANCER => Server::TYPE_LOAD_BALANCER,
            InternalServerDescription::TYPE_UNKNOWN       => Server::TYPE_UNKNOWN,
        ];

        $publicType = $typeMap[$sd->type] ?? Server::TYPE_UNKNOWN;

        $serverDescription = ServerDescription::createFromInternal(
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
            type:              $publicType,
            latency:           $sd->roundTripTimeMs,
            serverDescription: $serverDescription,
            tags:              $sd->tags,
        );
    }
}
