<?php

declare(strict_types=1);

namespace MongoDB\Internal\Operation;

use MongoDB\BSON\Document;
use MongoDB\BSON\Int64;
use MongoDB\BSON\Timestamp;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\BulkWriteCommand;
use MongoDB\Driver\BulkWriteCommandResult;
use MongoDB\Driver\Command;
use MongoDB\Driver\Cursor;
use MongoDB\Driver\CursorInterface;
use MongoDB\Driver\Exception\BulkWriteCommandException;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\CommandException;
use MongoDB\Driver\Exception\InvalidArgumentException as DriverInvalidArgumentException;
use MongoDB\Driver\Exception\RuntimeException as DriverRuntimeException;
use MongoDB\Driver\Exception\ServerException;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use MongoDB\Driver\Monitoring\Subscriber;
use MongoDB\Driver\Query;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\Server;
use MongoDB\Driver\ServerDescription;
use MongoDB\Driver\Session;
use MongoDB\Driver\WriteConcern;
use MongoDB\Driver\WriteConcernError;
use MongoDB\Driver\WriteError;
use MongoDB\Driver\WriteResult;
use MongoDB\Internal\Connection\ConnectionPool;
use MongoDB\Internal\Connection\SyncRunner;
use MongoDB\Internal\Monitoring\GlobalSubscriberRegistry;
use MongoDB\Internal\Protocol\OpMsgDecoder;
use MongoDB\Internal\Protocol\OpMsgEncoder;
use MongoDB\Internal\Protocol\RequestIdGenerator;
use MongoDB\Internal\Topology\InternalServerDescription;
use MongoDB\Internal\Topology\TopologyManager;
use MongoDB\Internal\Uri\UriOptions;
use stdClass;
use Throwable;

use function array_map;
use function array_merge;
use function array_search;
use function array_values;
use function assert;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_object;
use function iterator_to_array;
use function microtime;
use function reset;
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

    public function addSubscriber(Subscriber $subscriber): void
    {
        if (in_array($subscriber, $this->subscribers, true)) {
            return;
        }

        $this->subscribers[] = $subscriber;
    }

    public function removeSubscriber(Subscriber $subscriber): void
    {
        $key = array_search($subscriber, $this->subscribers, true);
        if ($key === false) {
            return;
        }

        unset($this->subscribers[$key]);
        $this->subscribers = array_values($this->subscribers);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Start the topology if it has not been started yet.
     */
    private function ensureStarted(): void
    {
        if ($this->topology->isStarted()) {
            return;
        }

        SyncRunner::run(function (): void {
            $this->topology->start();
        });
    }

    /**
     * Execute a command and return a cursor over the result set.
     */
    public function executeCommand(
        string $db,
        Command $command,
        ?ReadPreference $readPreference = null,
        ?Session $session = null,
        ?ReadConcern $readConcern = null,
        ?WriteConcern $writeConcern = null,
    ): CursorInterface {
        $this->ensureStarted();

        $readPreference ??= new ReadPreference(ReadPreference::PRIMARY);

        $server = $this->topology->selectServer($readPreference);
        $pool   = $this->getOrCreatePool($server->host, $server->port);

        $rawCmd  = $command->getDocument();
        $cmdName = CommandHelper::getCommandName($rawCmd);

        // Standalone servers ignore read preference; do not inject it into the command.
        $effectiveReadPreference = $server->type === InternalServerDescription::TYPE_STANDALONE
            ? null
            : $readPreference;

        $prepared = CommandHelper::prepareCommand(
            command:        $rawCmd,
            db:             $db,
            readPreference: $effectiveReadPreference,
            readConcern:    $readConcern,
            writeConcern:   $writeConcern,
            session:        $session,
        );

        return $this->sendCommand($pool, $db, $cmdName, $prepared, $server, 0, $command, $session);
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
        $this->ensureStarted();

        [$db, $collection] = $this->splitNamespace($namespace);

        $readPreference ??= new ReadPreference(ReadPreference::PRIMARY);

        // Build a find command from the Query object.
        // Ensure filter is always a document (object), never a BSON array.
        $filter  = $query->getFilter();
        $findCmd = ['find' => $collection, 'filter' => is_array($filter) ? (object) $filter : $filter];

        $opts = $query->getOptions();

        foreach (
            [
                'sort', 'projection', 'skip', 'limit', 'batchSize',
                'singleBatch', 'comment', 'maxTimeMS', 'hint', 'let',
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

        $maxAwaitTimeMS = isset($opts['maxAwaitTimeMS']) ? (int) $opts['maxAwaitTimeMS'] : 0;

        return $this->sendCommand($pool, $db, 'find', $prepared, $server, $maxAwaitTimeMS, null, $session);
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
        $this->ensureStarted();

        if ($bulk->count() === 0) {
            throw new DriverInvalidArgumentException('Cannot do an empty bulk write');
        }

        if ($bulk->isExecuted()) {
            throw new DriverInvalidArgumentException(
                'BulkWrite objects may only be executed once and this instance has already been executed',
            );
        }

        [$db, $collection] = $this->splitNamespace($namespace);

        $server = $this->topology->selectServer(new ReadPreference(ReadPreference::PRIMARY));
        $pool   = $this->getOrCreatePool($server->host, $server->port);

        $ordered     = (bool) ($bulk->getOptions()['ordered'] ?? true);
        $bulkOptions = $bulk->getOptions();
        $operations  = $bulk->getOperations();

        // Accumulators for the aggregate WriteResult.
        $totalInserted  = 0;
        $totalMatched   = 0;
        $totalModified  = 0;
        $totalDeleted   = 0;
        $totalUpserted  = 0;
        $upsertedIds    = [];
        $writeErrors    = [];
        $wcError        = null;
        $acknowledged   = $writeConcern === null || $writeConcern->getW() !== 0;

        // Build consecutive batches of same-type operations to minimise round-trips
        // while preserving original operation order (required for ordered bulk writes).
        $batches      = [];
        $prevType     = null;
        $currentBatch = null;

        foreach ($operations as $globalIdx => $op) {
            $type = $op[0];

            if ($type !== $prevType) {
                if ($currentBatch !== null) {
                    $batches[] = $currentBatch;
                }

                $currentBatch = ['type' => $type, 'ops' => [], 'globalIndices' => []];
                $prevType     = $type;
            }

            $currentBatch['ops'][]           = $op;
            $currentBatch['globalIndices'][] = $globalIdx;
        }

        if ($currentBatch !== null) {
            $batches[] = $currentBatch;
        }

        foreach ($batches as $batch) {
            $batchType          = $batch['type'];
            $batchOps           = $batch['ops'];
            $batchGlobalIndices = $batch['globalIndices'];

            if ($batchType === 'insert') {
                $docs = array_map(static fn ($op) => $op[1], $batchOps);

                $insertBase = ['insert' => $collection, 'documents' => $docs, 'ordered' => $ordered];
                if (isset($bulkOptions['comment'])) {
                    $insertBase['comment'] = $bulkOptions['comment'];
                }

                $insertCmd = CommandHelper::prepareCommand(
                    command:      $insertBase,
                    db:           $db,
                    writeConcern: $writeConcern,
                    session:      $session,
                );

                try {
                    $cursor = $this->sendCommand($pool, $db, 'insert', $insertCmd, $server, 0, null, $session);
                    $result = (array) (iterator_to_array($cursor)[0] ?? []);

                    $totalInserted += (int) ($result['n'] ?? count($docs));
                    $acknowledged   = ! ($result['acknowledged'] ?? true) ? false : $acknowledged;

                    foreach ((array) ($result['writeErrors'] ?? []) as $e) {
                        $localIdx  = (int) ($e->index ?? 0);
                        $globalIdx = $batchGlobalIndices[$localIdx] ?? $localIdx;
                        $writeErrors[] = new WriteError(
                            code:    (int) ($e->code    ?? 0),
                            index:   $globalIdx,
                            message: (string) ($e->errmsg ?? ''),
                            info:    $e->errInfo ?? null,
                        );
                    }

                    if (isset($result['writeConcernError'])) {
                        $wce     = (array) $result['writeConcernError'];
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
            } elseif ($batchType === 'update') {
                $updateSpecs = array_map(static function ($op): array {
                    [, $filter, $newObj, $opts] = $op;
                    $spec = ['q' => is_array($filter) ? (object) $filter : $filter, 'u' => $newObj];

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
                }, $batchOps);

                $updateBase = ['update' => $collection, 'updates' => $updateSpecs, 'ordered' => $ordered];
                if (isset($bulkOptions['comment'])) {
                    $updateBase['comment'] = $bulkOptions['comment'];
                }

                if (isset($bulkOptions['let'])) {
                    $updateBase['let'] = $bulkOptions['let'];
                }

                $updateCmd = CommandHelper::prepareCommand(
                    command:      $updateBase,
                    db:           $db,
                    writeConcern: $writeConcern,
                    session:      $session,
                );

                try {
                    $cursor = $this->sendCommand($pool, $db, 'update', $updateCmd, $server, 0, null, $session);
                    $result = (array) (iterator_to_array($cursor)[0] ?? []);

                    $upsertedInBatch = (array) ($result['upserted'] ?? []);
                    $totalMatched  += (int) ($result['n'] ?? 0) - count($upsertedInBatch);
                    $totalModified += (int) ($result['nModified'] ?? 0);

                    foreach ($upsertedInBatch as $upserted) {
                        $upserted  = (array) $upserted;
                        $localIdx  = (int) $upserted['index'];
                        $globalIdx = $batchGlobalIndices[$localIdx] ?? $localIdx;
                        $upsertedIds[$globalIdx] = $upserted['_id'];
                        ++$totalUpserted;
                    }

                    foreach ((array) ($result['writeErrors'] ?? []) as $e) {
                        $localIdx  = (int) ($e->index ?? 0);
                        $globalIdx = $batchGlobalIndices[$localIdx] ?? $localIdx;
                        $writeErrors[] = new WriteError(
                            code:    (int) ($e->code    ?? 0),
                            index:   $globalIdx,
                            message: (string) ($e->errmsg ?? ''),
                            info:    $e->errInfo ?? null,
                        );
                    }

                    if (isset($result['writeConcernError']) && $wcError === null) {
                        $wce     = (array) $result['writeConcernError'];
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
            } elseif ($batchType === 'delete') {
                $deleteSpecs = array_map(static function ($op): array {
                    [, $filter, $opts] = $op;
                    $limit = $opts['limit'] ?? 1 ? 1 : 0;
                    $spec  = ['q' => is_array($filter) ? (object) $filter : $filter, 'limit' => $limit];

                    if (isset($opts['collation'])) {
                        $spec['collation'] = $opts['collation'];
                    }

                    if (isset($opts['hint'])) {
                        $spec['hint'] = $opts['hint'];
                    }

                    return $spec;
                }, $batchOps);

                $deleteBase = ['delete' => $collection, 'deletes' => $deleteSpecs, 'ordered' => $ordered];
                if (isset($bulkOptions['comment'])) {
                    $deleteBase['comment'] = $bulkOptions['comment'];
                }

                if (isset($bulkOptions['let'])) {
                    $deleteBase['let'] = $bulkOptions['let'];
                }

                $deleteCmd = CommandHelper::prepareCommand(
                    command:      $deleteBase,
                    db:           $db,
                    writeConcern: $writeConcern,
                    session:      $session,
                );

                try {
                    $cursor = $this->sendCommand($pool, $db, 'delete', $deleteCmd, $server, 0, null, $session);
                    $result = (array) (iterator_to_array($cursor)[0] ?? []);

                    $totalDeleted += (int) ($result['n'] ?? 0);

                    foreach ((array) ($result['writeErrors'] ?? []) as $e) {
                        $localIdx  = (int) ($e->index ?? 0);
                        $globalIdx = $batchGlobalIndices[$localIdx] ?? $localIdx;
                        $writeErrors[] = new WriteError(
                            code:    (int) ($e->code    ?? 0),
                            index:   $globalIdx,
                            message: (string) ($e->errmsg ?? ''),
                            info:    $e->errInfo ?? null,
                        );
                    }

                    if (isset($result['writeConcernError']) && $wcError === null) {
                        $wce     = (array) $result['writeConcernError'];
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
        }

        // Mark BulkWrite as executed.
        $bulk->markExecuted($db, $collection, 1, $writeConcern);

        // Build the public Server object for WriteResult.
        $publicServer = $this->buildPublicServer($server);

        $writeResult = WriteResult::createFromInternal(
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
            writeConcern:    $writeConcern,
        );

        if ($writeErrors !== []) {
            $firstError = $writeErrors[0];

            throw new BulkWriteException(
                message:        $firstError->getMessage(),
                code:           $firstError->getCode(),
                writeResult:    $writeResult,
            );
        }

        return $writeResult;
    }

    /**
     * Execute a bulkWrite command (MongoDB 8.0+) and return an aggregated BulkWriteCommandResult.
     *
     * The `bulkWrite` command is sent to the "admin" database.  Unlike the legacy per-collection
     * bulk write, operations may span multiple namespaces.
     *
     * @throws BulkWriteCommandException when individual write errors or write concern errors occur.
     */
    public function executeBulkWriteCommand(
        BulkWriteCommand $bulk,
        ?WriteConcern $writeConcern = null,
        ?Session $session = null,
    ): BulkWriteCommandResult {
        $this->ensureStarted();

        if ($bulk->count() === 0) {
            throw new DriverRuntimeException('BulkWriteCommand cannot be empty');
        }

        $server = $this->topology->selectServer(new ReadPreference(ReadPreference::PRIMARY));
        $pool   = $this->getOrCreatePool($server->host, $server->port);

        $options        = $bulk->getOptions();
        $ordered        = (bool) ($options['ordered'] ?? true);
        $verboseResults = (bool) ($options['verboseResults'] ?? false);

        $command = [
            'bulkWrite' => 1,
            'ops'       => $bulk->getOps(),
            'nsInfo'    => $bulk->getNsInfo(),
            'ordered'   => $ordered,
            'errorsOnly' => ! $verboseResults,
        ];

        foreach (['bypassDocumentValidation', 'comment', 'let'] as $opt) {
            if (! isset($options[$opt])) {
                continue;
            }

            $command[$opt] = $options[$opt];
        }

        $prepared = CommandHelper::prepareCommand(
            command:      $command,
            db:           'admin',
            writeConcern: $writeConcern,
            session:      $session,
        );

        $acknowledged = $writeConcern === null || $writeConcern->getW() !== 0;

        // Send command and capture raw body for the summary counts.
        try {
            $body = $this->doSendCommand($pool, 'admin', 'bulkWrite', $prepared, $server);
        } catch (CommandException $e) {
            throw BulkWriteCommandException::create(
                message:      $e->getMessage(),
                code:         $e->getCode(),
                resultDocument: $e->getResultDocument(),
                errorReply:   Document::fromPHP($e->getResultDocument()),
            );
        }

        // Summary counts live in the top-level response body.
        $nInserted = (int) ($body['nInserted'] ?? 0);
        $nUpserted = (int) ($body['nUpserted'] ?? 0);
        $nMatched  = (int) ($body['nMatched']  ?? 0);
        $nModified = (int) ($body['nModified'] ?? 0);
        $nDeleted  = (int) ($body['nDeleted']  ?? 0);

        // Per-operation results / errors come from the results cursor.
        $resultsCursor = $this->buildCursor($body, 'admin', 'bulkWrite', $pool, $server);

        $ops         = $bulk->getOps();
        $insertedIds = $bulk->getInsertedIds();

        $writeErrors        = [];
        $writeConcernErrors = [];
        $insertResultsMap   = [];
        $updateResultsMap   = [];
        $deleteResultsMap   = [];

        foreach ($resultsCursor as $doc) {
            $doc = is_array($doc) ? $doc : (array) $doc;
            $ok  = (int) ($doc['ok'] ?? 1);
            $idx = (int) ($doc['idx'] ?? 0);

            if ($ok === 0) {
                $writeErrors[$idx] = new WriteError(
                    code:    (int) ($doc['code']   ?? 0),
                    index:   $idx,
                    message: (string) ($doc['errmsg'] ?? ''),
                    info:    isset($doc['errInfo']) ? (object) $doc['errInfo'] : null,
                );
                continue;
            }

            if (! $verboseResults) {
                continue;
            }

            $op = $ops[$idx] ?? [];

            if (isset($op['insert'])) {
                if (isset($insertedIds[$idx])) {
                    $insertResultsMap[(string) $idx] = (object) ['insertedId' => $insertedIds[$idx]];
                }
            } elseif (isset($op['update'])) {
                $res = (object) [
                    'matchedCount'  => (int) ($doc['n'] ?? 0),
                    'modifiedCount' => (int) ($doc['nModified'] ?? 0),
                ];
                if (isset($doc['upserted']['_id'])) {
                    $res->upsertedId = $doc['upserted']['_id'];
                }

                $updateResultsMap[(string) $idx] = $res;
            } elseif (isset($op['delete'])) {
                $deleteResultsMap[(string) $idx] = (object) ['deletedCount' => (int) ($doc['n'] ?? 0)];
            }
        }

        // Write concern error from top-level response body.
        if (isset($body['writeConcernError'])) {
            $wce = (array) $body['writeConcernError'];
            $writeConcernErrors[] = new WriteConcernError(
                code:    (int) ($wce['code']   ?? 0),
                message: (string) ($wce['errmsg'] ?? ''),
            );
        }

        $insertResultsDoc = $verboseResults && $insertResultsMap !== []
            ? Document::fromPHP((object) $insertResultsMap) : null;
        $updateResultsDoc = $verboseResults && $updateResultsMap !== []
            ? Document::fromPHP((object) $updateResultsMap) : null;
        $deleteResultsDoc = $verboseResults && $deleteResultsMap !== []
            ? Document::fromPHP((object) $deleteResultsMap) : null;

        $result = BulkWriteCommandResult::createFromInternal(
            insertedCount: $nInserted,
            matchedCount:  $nMatched,
            modifiedCount: $nModified,
            upsertedCount: $nUpserted,
            deletedCount:  $nDeleted,
            acknowledged:  $acknowledged,
            insertResults: $insertResultsDoc,
            updateResults: $updateResultsDoc,
            deleteResults: $deleteResultsDoc,
        );

        if ($writeErrors !== [] || $writeConcernErrors !== []) {
            $firstError = reset($writeErrors);

            throw BulkWriteCommandException::create(
                message:            $firstError !== false ? $firstError->getMessage() : 'Write concern error occurred',
                code:               $firstError !== false ? $firstError->getCode()    : 0,
                partialResult:      $result,
                writeErrors:        array_values($writeErrors),
                writeConcernErrors: $writeConcernErrors,
            );
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Private — core send/receive
    // -------------------------------------------------------------------------

    /**
     * Send a prepared command and return the decoded response body.
     *
     * Fires CommandStarted / CommandSucceeded / CommandFailed events.
     * Throws CommandException when the server returns ok:0.
     *
     * @param array $prepared Fully-decorated command array (output of CommandHelper::prepareCommand).
     *
     * @return array Decoded response body.
     */
    private function doSendCommand(
        ConnectionPool $pool,
        string $db,
        string $cmdName,
        array $prepared,
        InternalServerDescription $server,
    ): array {
        $conn      = $pool->acquire();
        $requestId = RequestIdGenerator::next();
        $startUs   = (int) (microtime(true) * 1_000_000);

        // Use the server's hello-response connectionId as the serverConnectionId for monitoring events.
        $serverConnId = isset($server->helloResponse['connectionId'])
            ? (int) $server->helloResponse['connectionId']
            : null;

        // Decode with root/document='object' to suppress Persistable detection:
        // CommandStartedEvent.getCommand() returns a plain stdClass view of the raw
        // command as sent to MongoDB, not Persistable-reconstructed objects.
        $commandDoc = Document::fromPHP($prepared)->toPHP(['root' => 'object', 'document' => 'object']);
        assert($commandDoc instanceof stdClass);
        $this->fireCommandStarted($cmdName, $commandDoc, $db, $requestId, $server->host, $server->port, $serverConnId);

        try {
            [$bytes] = OpMsgEncoder::encodeWithRequestId($prepared);

            $responseBytes = $conn->sendMessage($bytes);
            $durationUs    = (int) (microtime(true) * 1_000_000) - $startUs;

            // Decode without automatic error checking so we can capture the
            // reply body for CommandFailedEvent even when ok != 1.
            $decoded = OpMsgDecoder::decode($responseBytes);
            $rawBody = $decoded['body'];

            // Normalise body to array for uniform handling.
            $body = is_array($rawBody) ? $rawBody : (array) $rawBody;
            $ok   = (int) ($body['ok'] ?? 0);

            if ($ok !== 1) {
                $errmsg = (string) ($body['errmsg'] ?? 'Unknown error');
                $code   = (int) ($body['code']   ?? 0);

                // Per PHPC-1990: CommandFailedEvent stores a ServerException (not CommandException).
                $eventErr = new ServerException($errmsg, $code, (object) $body);
                $this->fireCommandFailed($cmdName, $eventErr, $db, $requestId, $durationUs, $server->host, $server->port, (object) $body, $serverConnId);

                $pool->release($conn);

                throw new CommandException($errmsg, $code, (object) $body);
            }

            $this->fireCommandSucceeded($cmdName, (object) $body, $db, $requestId, $durationUs, $server->host, $server->port, $serverConnId);

            $pool->release($conn);

            return $body;
        } catch (CommandException $e) {
            throw $e;
        } catch (Throwable $e) {
            $durationUs = (int) (microtime(true) * 1_000_000) - $startUs;
            $this->fireCommandFailed($cmdName, $e, $db, $requestId, $durationUs, $server->host, $server->port, null, $serverConnId);

            $pool->release($conn);

            throw $e;
        }
    }

    /**
     * Send a prepared command and return a CursorInterface over the results.
     */
    private function sendCommand(
        ConnectionPool $pool,
        string $db,
        string $cmdName,
        array $prepared,
        InternalServerDescription $server,
        int $maxAwaitTimeMS = 0,
        ?Command $debugCommand = null,
        ?Session $session = null,
    ): CursorInterface {
        $body = $this->doSendCommand($pool, $db, $cmdName, $prepared, $server);

        $this->advanceSessionFromResponse($session, $body);

        return $this->buildCursor($body, $db, $cmdName, $pool, $server, $maxAwaitTimeMS, $debugCommand, $session);
    }

    /**
     * Update session cluster time and operation time from a server response body.
     */
    private function advanceSessionFromResponse(?Session $session, array $body): void
    {
        if ($session === null) {
            return;
        }

        $clusterTime = $body['$clusterTime'] ?? null;
        if ($clusterTime !== null) {
            $session->advanceClusterTime(is_array($clusterTime) ? (object) $clusterTime : $clusterTime);
        }

        $operationTime = $body['operationTime'] ?? null;
        if (! ($operationTime instanceof Timestamp)) {
            return;
        }

        $session->advanceOperationTime($operationTime);
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
        int $maxAwaitTimeMS = 0,
        ?Command $debugCommand = null,
        ?Session $session = null,
    ): CursorInterface {
        $publicServer = $this->buildPublicServer($server);

        // Commands that return a cursor sub-document (find, aggregate, …).
        $rawCursor = $body['cursor'] ?? null;
        if ($rawCursor !== null && (is_array($rawCursor) || is_object($rawCursor))) {
            $cursorDoc  = (array) $rawCursor;
            $cursorIdRaw = $cursorDoc['id'] ?? 0;
            $cursorId   = $cursorIdRaw instanceof Int64 ? (int) (string) $cursorIdRaw : (int) $cursorIdRaw;
            $ns         = (string) ($cursorDoc['ns'] ?? $db);
            $firstBatch = (array) ($cursorDoc['firstBatch'] ?? []);

            $getMoreFn = function (int $cursorId, string $ns) use ($pool, $db, $maxAwaitTimeMS, $server, $session): array {
                $getMoreCmd = [
                    'getMore'    => new Int64($cursorId),
                    'collection' => explode('.', $ns, 2)[1] ?? $ns,
                ];

                if ($maxAwaitTimeMS > 0) {
                    $getMoreCmd['maxTimeMS'] = $maxAwaitTimeMS;
                }

                $prepared  = CommandHelper::prepareCommand(command: $getMoreCmd, db: $db, session: $session);
                $body      = $this->doSendCommand($pool, $db, 'getMore', $prepared, $server);
                $cursorDoc = (array) ($body['cursor'] ?? []);
                $cursorIdRaw = $cursorDoc['id'] ?? 0;
                $newCursorId = $cursorIdRaw instanceof Int64 ? (int) (string) $cursorIdRaw : (int) $cursorIdRaw;
                $nextBatch   = (array) ($cursorDoc['nextBatch'] ?? []);

                return [$nextBatch, $newCursorId];
            };

            return Cursor::createFromCommandResult(
                items:      $firstBatch,
                cursorId:   $cursorId,
                namespace:  $ns,
                server:     $publicServer,
                typeMap:    [],
                getMoreFn:  $getMoreFn,
                database:   $db,
                command:    $debugCommand,
            );
        }

        // Non-cursor commands: return the single response document as a one-element cursor.
        return Cursor::createFromArray([(object) $body], $publicServer, $db, $debugCommand);
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
        string $host = '',
        int $port = 27017,
        ?int $serverConnectionId = null,
    ): void {
        $event = new CommandStartedEvent(
            commandName:         $cmdName,
            command:             $cmd,
            databaseName:        $db,
            requestId:           $requestId,
            operationId:         $requestId,
            host:                $host,
            port:                $port,
            serverConnectionId:  $serverConnectionId,
        );

        $allSubscribers = array_merge($this->subscribers, GlobalSubscriberRegistry::getAll());
        foreach ($allSubscribers as $subscriber) {
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
        string $host = '',
        int $port = 27017,
        ?int $serverConnectionId = null,
    ): void {
        $event = new CommandSucceededEvent(
            commandName:         $cmdName,
            reply:               $reply,
            databaseName:        $db,
            requestId:           $requestId,
            operationId:         $requestId,
            durationMicros:      $durationMicros,
            host:                $host,
            port:                $port,
            serverConnectionId:  $serverConnectionId,
        );

        $allSubscribers = array_merge($this->subscribers, GlobalSubscriberRegistry::getAll());
        foreach ($allSubscribers as $subscriber) {
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
        string $host = '',
        int $port = 27017,
        ?object $reply = null,
        ?int $serverConnectionId = null,
    ): void {
        $event = new CommandFailedEvent(
            commandName:         $cmdName,
            databaseName:        $db,
            error:               $e,
            requestId:           $requestId,
            operationId:         $requestId,
            durationMicros:      $durationMicros,
            host:                $host,
            port:                $port,
            serverConnectionId:  $serverConnectionId,
            reply:               $reply,
        );

        $allSubscribers = array_merge($this->subscribers, GlobalSubscriberRegistry::getAll());
        foreach ($allSubscribers as $subscriber) {
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
            throw new DriverInvalidArgumentException('Invalid namespace provided: ' . $namespace);
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
            info:              $sd->helloResponse,
            tags:              $sd->tags,
            executor:          $this,
        );
    }

    /**
     * Recursively convert an array to stdClass, turning associative arrays into objects.
     * List arrays remain as PHP arrays (since they represent BSON arrays).
     */
}
