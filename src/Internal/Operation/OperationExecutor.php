<?php

declare(strict_types=1);

namespace MongoDB\Internal\Operation;

use MongoDB\BSON\Document;
use MongoDB\BSON\Int64;
use MongoDB\BSON\PackedArray;
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
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use MongoDB\Driver\Exception\ExecutionTimeoutException;
use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Driver\Exception\ServerException;
use MongoDB\Driver\Query;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\Server;
use MongoDB\Driver\ServerApi;
use MongoDB\Driver\ServerDescription;
use MongoDB\Driver\Session;
use MongoDB\Driver\WriteConcern;
use MongoDB\Driver\WriteConcernError;
use MongoDB\Driver\WriteError;
use MongoDB\Driver\WriteResult;
use MongoDB\Internal\Connection\ConnectionPool;
use MongoDB\Internal\Monitoring\Dispatcher;
use MongoDB\Internal\Protocol\OpMsgDecoder;
use MongoDB\Internal\Protocol\OpMsgEncoder;
use MongoDB\Internal\Protocol\RequestIdGenerator;
use MongoDB\Internal\Session\SessionPool;
use MongoDB\Internal\SyncRunner;
use MongoDB\Internal\Topology\InternalServerDescription;
use MongoDB\Internal\Topology\TopologyManager;
use MongoDB\Internal\Uri\UriOptions;
use stdClass;
use Throwable;

use function array_is_list;
use function array_map;
use function array_values;
use function assert;
use function bin2hex;
use function count;
use function explode;
use function get_object_vars;
use function hrtime;
use function implode;
use function intdiv;
use function is_array;
use function is_object;
use function is_string;
use function iterator_to_array;
use function max;
use function round;
use function sprintf;
use function strlen;
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

    /** @var array<string, int> txnNumber counter per lsid id (hex), for retryable writes. */
    private array $txnNumbers = [];

    /**
     * @param TopologyManager $topology    Live topology manager.
     * @param UriOptions      $options     Parsed URI options.
     * @param SessionPool     $sessionPool Session pool for implicit sessions.
     * @param Dispatcher      $dispatcher  Shared subscriber registry (owned by Manager).
     */
    public function __construct(
        private TopologyManager $topology,
        private UriOptions $options,
        private SessionPool $sessionPool,
        private Dispatcher $dispatcher,
        private ?ServerApi $serverApi = null,
    ) {
    }

    /**
     * Close all connection pools. Called from Manager::__destruct() to ensure
     * immediate socket cleanup regardless of GC timing.
     */
    public function close(): void
    {
        foreach ($this->pools as $pool) {
            $pool->close();
        }

        $this->pools = [];
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
        ?Server $callingServer = null,
        bool $retryRead = false,
    ): CursorInterface {
        $this->ensureStarted();

        $deadlineNs     = $this->computeDeadlineNs();
        $readPreference ??= new ReadPreference(ReadPreference::PRIMARY);

        $server = $this->topology->selectServer($readPreference, $this->remainingSelectionMs($deadlineNs));
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
            serverApi:      $this->serverApi,
        );

        $maxAwaitTimeMS = (int) ($command->getOptions()['maxAwaitTimeMS'] ?? 0);

        $canRetry = $retryRead
            && $this->options->retryReads
            && ($session === null || ! $session->isInTransaction())
            && $this->serverSupportsRetry($server);

        if (! $canRetry) {
            return $this->sendCommand($pool, $db, $cmdName, $prepared, $server, $maxAwaitTimeMS, $command, $session, $callingServer, deadlineNs: $deadlineNs);
        }

        try {
            return $this->sendCommand($pool, $db, $cmdName, $prepared, $server, $maxAwaitTimeMS, $command, $session, $callingServer, deadlineNs: $deadlineNs);
        } catch (Throwable $e) {
            if (! RetryableError::isRetryable($e)) {
                throw $e;
            }
        }

        // One retry with a freshly selected server.
        $server = $this->topology->selectServer($readPreference, $this->remainingSelectionMs($deadlineNs));
        $pool   = $this->getOrCreatePool($server->host, $server->port);

        return $this->sendCommand($pool, $db, $cmdName, $prepared, $server, $maxAwaitTimeMS, $command, $session, $callingServer, deadlineNs: $deadlineNs);
    }

    /**
     * Execute a query and return a cursor over the matched documents.
     */
    public function executeQuery(
        string $namespace,
        Query $query,
        ?ReadPreference $readPreference = null,
        ?Session $session = null,
        ?Server $callingServer = null,
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
                'singleBatch', 'comment', 'maxTimeMS', 'hint', 'let', 'allowDiskUse',
                'allowPartialResults', 'noCursorTimeout', 'tailable',
                'awaitData', 'oplogReplay', 'returnKey', 'showRecordId',
                'snapshot', 'min', 'max', 'rawData',
            ] as $optKey
        ) {
            if (! isset($opts[$optKey])) {
                continue;
            }

            $findCmd[$optKey] = $opts[$optKey];
        }

        // Spec: if batchSize equals limit, increase batchSize by one to avoid a superfluous getMore.
        if (isset($findCmd['batchSize'], $findCmd['limit']) && $findCmd['batchSize'] === $findCmd['limit']) {
            $findCmd['batchSize'] = $findCmd['limit'] + 1;
        }

        $deadlineNs = $this->computeDeadlineNs();
        $server     = $this->topology->selectServer($readPreference, $this->remainingSelectionMs($deadlineNs));
        $pool       = $this->getOrCreatePool($server->host, $server->port);

        $prepared = CommandHelper::prepareCommand(
            command:        $findCmd,
            db:             $db,
            readPreference: $readPreference,
            session:        $session,
            serverApi:      $this->serverApi,
        );

        $maxAwaitTimeMS = isset($opts['maxAwaitTimeMS']) ? (int) $opts['maxAwaitTimeMS'] : 0;

        $canRetry = $this->options->retryReads
            && ($session === null || ! $session->isInTransaction())
            && $this->serverSupportsRetry($server);

        if (! $canRetry) {
            return $this->sendCommand($pool, $db, 'find', $prepared, $server, $maxAwaitTimeMS, null, $session, $callingServer, $query, $deadlineNs);
        }

        try {
            return $this->sendCommand($pool, $db, 'find', $prepared, $server, $maxAwaitTimeMS, null, $session, $callingServer, $query, $deadlineNs);
        } catch (Throwable $e) {
            if (! RetryableError::isRetryable($e)) {
                throw $e;
            }
        }

        // One retry with a freshly selected server.
        $server = $this->topology->selectServer($readPreference, $this->remainingSelectionMs($deadlineNs));
        $pool   = $this->getOrCreatePool($server->host, $server->port);

        return $this->sendCommand($pool, $db, 'find', $prepared, $server, $maxAwaitTimeMS, null, $session, $callingServer, $query, $deadlineNs);
    }

    /**
     * Execute a bulk write and return an aggregated WriteResult.
     */
    public function executeBulkWrite(
        string $namespace,
        BulkWrite $bulk,
        ?WriteConcern $writeConcern = null,
        ?Session $session = null,
        bool $writeConcernExplicit = false,
    ): WriteResult {
        $this->ensureStarted();

        if ($bulk->count() === 0) {
            throw new InvalidArgumentException('Cannot do an empty bulk write');
        }

        if ($bulk->isExecuted()) {
            throw new InvalidArgumentException(
                'BulkWrite objects may only be executed once and this instance has already been executed',
            );
        }

        [$db, $collection] = $this->splitNamespace($namespace);

        $deadlineNs = $this->computeDeadlineNs();
        $server     = $this->topology->selectServer(new ReadPreference(ReadPreference::PRIMARY), $this->remainingSelectionMs($deadlineNs));
        $pool       = $this->getOrCreatePool($server->host, $server->port);

        $canRetryWrites = $this->options->retryWrites
            && $session === null
            && ($writeConcern === null || $writeConcern->getW() !== 0)
            && $this->serverSupportsRetryableWrites($server);

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
        $errorReplies   = [];
        $wcError        = null;
        // Exception that interrupted an ordered bulk write mid-stream.
        $batchException   = null;
        $acknowledged   = $writeConcern === null || $writeConcern->getW() !== 0;
        // When no explicit WriteConcern was provided, libmongoc sets NULL on the
        // bulk operation, and mongoc_write_concern_is_acknowledged(NULL) returns
        // true — so counts are included in the reply as int(0) even for w=0 URIs.
        // Only when an explicit WriteConcern(0) is set are counts omitted (NULL).
        $countsAvailable = ! $writeConcernExplicit || $acknowledged;

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

                if ($bulkOptions['bypassDocumentValidation'] ?? false) {
                    $insertBase['bypassDocumentValidation'] = true;
                }

                if (isset($bulkOptions['rawData'])) {
                    $insertBase['rawData'] = $bulkOptions['rawData'];
                }

                $insertCmd = CommandHelper::prepareCommand(
                    command:      $insertBase,
                    db:           $db,
                    writeConcern: $writeConcern,
                    session:      $session,
                    serverApi:    $this->serverApi,
                );

                $batchIsRetryable = $canRetryWrites && $this->isBatchRetryableForWrite($batch);
                $retryLsid        = null;

                if ($batchIsRetryable) {
                    $retryLsid                = $this->sessionPool->acquire();
                    $txnNum                   = $this->nextTxnNumber($retryLsid);
                    $insertCmd['lsid']        = $retryLsid;
                    $insertCmd['txnNumber']   = new Int64($txnNum);
                }

                try {
                    $result = $this->executeBatchWithRetry($pool, $server, $db, 'insert', $insertCmd, $session, $batchIsRetryable, $deadlineNs);

                    $totalInserted += (int) ($result['n'] ?? count($docs));
                    $acknowledged   = ! ($result['acknowledged'] ?? true) ? false : $acknowledged;

                    foreach ((array) ($result['writeErrors'] ?? []) as $e) {
                        $localIdx  = (int) ($e->index ?? 0);
                        $globalIdx = $batchGlobalIndices[$localIdx] ?? $localIdx;
                        $writeErrors[] = WriteError::create(
                            code:    (int) ($e->code    ?? 0),
                            index:   $globalIdx,
                            message: (string) ($e->errmsg ?? ''),
                            info:    $e->errInfo ?? null,
                        );
                    }

                    if (isset($result['writeConcernError'])) {
                        $wce     = (array) $result['writeConcernError'];
                        $wcError = WriteConcernError::create(
                            code:    (int) ($wce['code']   ?? 0),
                            message: (string) ($wce['errmsg'] ?? ''),
                        );
                    }
                } catch (CommandException $e) {
                    $errorReplies[] = $e->getResultDocument();
                    if ($ordered) {
                        break;
                    }
                } catch (Throwable $e) {
                    if ($ordered) {
                        $batchException = $e;
                        break;
                    }
                } finally {
                    if ($retryLsid !== null) {
                        $this->sessionPool->release($retryLsid);
                        $retryLsid = null;
                    }
                }
            } elseif ($batchType === 'update') {
                $updateSpecs = array_map(static function ($op): array {
                    [, $filter, $newObj, $opts] = $op;
                    $spec = ['q' => is_array($filter) ? (object) $filter : $filter, 'u' => self::normalizeUpdateDocument($newObj)];

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

                    if (isset($opts['sort'])) {
                        $spec['sort'] = $opts['sort'];
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

                if (isset($bulkOptions['bypassDocumentValidation']) && $bulkOptions['bypassDocumentValidation']) {
                    $updateBase['bypassDocumentValidation'] = true;
                }

                if (isset($bulkOptions['rawData'])) {
                    $updateBase['rawData'] = $bulkOptions['rawData'];
                }

                $updateCmd = CommandHelper::prepareCommand(
                    command:      $updateBase,
                    db:           $db,
                    writeConcern: $writeConcern,
                    session:      $session,
                    serverApi:    $this->serverApi,
                );

                $batchIsRetryable = $canRetryWrites && $this->isBatchRetryableForWrite($batch);
                $retryLsid        = null;

                if ($batchIsRetryable) {
                    $retryLsid                = $this->sessionPool->acquire();
                    $txnNum                   = $this->nextTxnNumber($retryLsid);
                    $updateCmd['lsid']        = $retryLsid;
                    $updateCmd['txnNumber']   = new Int64($txnNum);
                }

                try {
                    $result = $this->executeBatchWithRetry($pool, $server, $db, 'update', $updateCmd, $session, $batchIsRetryable, $deadlineNs);

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
                        $writeErrors[] = WriteError::create(
                            code:    (int) ($e->code    ?? 0),
                            index:   $globalIdx,
                            message: (string) ($e->errmsg ?? ''),
                            info:    $e->errInfo ?? null,
                        );
                    }

                    if (isset($result['writeConcernError']) && $wcError === null) {
                        $wce     = (array) $result['writeConcernError'];
                        $wcError = WriteConcernError::create(
                            code:    (int) ($wce['code']   ?? 0),
                            message: (string) ($wce['errmsg'] ?? ''),
                        );
                    }
                } catch (CommandException $e) {
                    $errorReplies[] = $e->getResultDocument();
                    if ($ordered) {
                        break;
                    }
                } catch (Throwable $e) {
                    if ($ordered) {
                        $batchException = $e;
                        break;
                    }
                } finally {
                    if ($retryLsid !== null) {
                        $this->sessionPool->release($retryLsid);
                        $retryLsid = null;
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

                if (isset($bulkOptions['rawData'])) {
                    $deleteBase['rawData'] = $bulkOptions['rawData'];
                }

                $deleteCmd = CommandHelper::prepareCommand(
                    command:      $deleteBase,
                    db:           $db,
                    writeConcern: $writeConcern,
                    session:      $session,
                    serverApi:    $this->serverApi,
                );

                $batchIsRetryable = $canRetryWrites && $this->isBatchRetryableForWrite($batch);
                $retryLsid        = null;

                if ($batchIsRetryable) {
                    $retryLsid                = $this->sessionPool->acquire();
                    $txnNum                   = $this->nextTxnNumber($retryLsid);
                    $deleteCmd['lsid']        = $retryLsid;
                    $deleteCmd['txnNumber']   = new Int64($txnNum);
                }

                try {
                    $result = $this->executeBatchWithRetry($pool, $server, $db, 'delete', $deleteCmd, $session, $batchIsRetryable, $deadlineNs);

                    $totalDeleted += (int) ($result['n'] ?? 0);

                    foreach ((array) ($result['writeErrors'] ?? []) as $e) {
                        $localIdx  = (int) ($e->index ?? 0);
                        $globalIdx = $batchGlobalIndices[$localIdx] ?? $localIdx;
                        $writeErrors[] = WriteError::create(
                            code:    (int) ($e->code    ?? 0),
                            index:   $globalIdx,
                            message: (string) ($e->errmsg ?? ''),
                            info:    $e->errInfo ?? null,
                        );
                    }

                    if (isset($result['writeConcernError']) && $wcError === null) {
                        $wce     = (array) $result['writeConcernError'];
                        $wcError = WriteConcernError::create(
                            code:    (int) ($wce['code']   ?? 0),
                            message: (string) ($wce['errmsg'] ?? ''),
                        );
                    }
                } catch (CommandException $e) {
                    $errorReplies[] = $e->getResultDocument();
                    if ($ordered) {
                        break;
                    }
                } catch (Throwable $e) {
                    if ($ordered) {
                        $batchException = $e;
                        break;
                    }
                } finally {
                    if ($retryLsid !== null) {
                        $this->sessionPool->release($retryLsid);
                        $retryLsid = null;
                    }
                }
            }
        }

        // Mark BulkWrite as executed.
        $bulk->markExecuted($db, $collection, 1, $writeConcern);

        // Build the public Server object for WriteResult.
        $publicServer = $this->buildPublicServer($server);

        $writeResult = WriteResult::createFromInternal(
            insertedCount:   $countsAvailable ? $totalInserted : null,
            matchedCount:    $countsAvailable ? $totalMatched : null,
            modifiedCount:   $countsAvailable ? $totalModified : null,
            deletedCount:    $countsAvailable ? $totalDeleted : null,
            upsertedCount:   $countsAvailable ? $totalUpserted : null,
            upsertedIds:     $upsertedIds,
            server:          $publicServer,
            writeConcernError: $wcError,
            writeErrors:     $writeErrors,
            acknowledged:    $acknowledged,
            writeConcern:    $writeConcern,
            errorReplies:    $errorReplies,
        );

        if ($batchException !== null) {
            throw new BulkWriteException(
                message:     sprintf('Bulk write failed due to previous %s: %s', $batchException::class, $batchException->getMessage()),
                code:        0,
                writeResult: $writeResult,
                previous:    $batchException,
            );
        }

        if ($errorReplies !== []) {
            $reply   = (array) $errorReplies[0];
            $replyObj = is_object($errorReplies[0]) ? $errorReplies[0] : (object) $reply;

            throw new BulkWriteException(
                message:        (string) ($reply['errmsg'] ?? ''),
                code:           (int) ($reply['code'] ?? 0),
                resultDocument: $replyObj,
                writeResult:    $writeResult,
            );
        }

        if ($writeErrors !== []) {
            $firstError = $writeErrors[0];

            if (count($writeErrors) === 1) {
                $message = $firstError->getMessage();
            } else {
                $quoted = array_map(
                    static fn (WriteError $e) => sprintf('"%s"', $e->getMessage()),
                    $writeErrors,
                );
                $message = sprintf('Multiple write errors: %s', implode(', ', $quoted));
            }

            throw new BulkWriteException(
                message:        $message,
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
            throw new RuntimeException('BulkWriteCommand cannot be empty');
        }

        if ($session !== null && $writeConcern !== null && $writeConcern->getW() === 0) {
            throw new InvalidArgumentException(
                'Cannot combine "session" option with an unacknowledged write concern',
            );
        }

        $deadlineNs = $this->computeDeadlineNs();
        $server     = $this->topology->selectServer(new ReadPreference(ReadPreference::PRIMARY), $this->remainingSelectionMs($deadlineNs));
        $pool       = $this->getOrCreatePool($server->host, $server->port);

        $options        = $bulk->getOptions();
        $ordered        = (bool) ($options['ordered'] ?? true);
        $verboseResults = (bool) ($options['verboseResults'] ?? false);
        $acknowledged   = $writeConcern === null || $writeConcern->getW() !== 0;

        $allOps      = $bulk->getOps();
        $allNsInfo   = $bulk->getNsInfo();
        $insertedIds = $bulk->getInsertedIds();

        // Limits from the server hello response.
        $maxMessageSizeBytes = (int) ($server->helloResponse['maxMessageSizeBytes'] ?? 48_000_000);
        $maxWriteBatchSize   = (int) ($server->helloResponse['maxWriteBatchSize']   ?? 100_000);

        // Check individual document/namespace sizes BEFORE batching to avoid OOM.
        foreach ($allNsInfo as $nsEntry) {
            if (strlen((string) ($nsEntry['ns'] ?? '')) >= $maxMessageSizeBytes) {
                throw new InvalidArgumentException('unable to send document: namespace is too large');
            }
        }

        foreach ($allOps as $op) {
            $docToCheck = $op['document'] ?? null;
            if ($docToCheck !== null && self::estimateBsonSize($docToCheck) >= $maxMessageSizeBytes) {
                throw new InvalidArgumentException('unable to send document: document is too large');
            }
        }

        // A single operationId is shared across all batches for APM correlation.
        $operationId = RequestIdGenerator::next();

        $totalOps   = count($allOps);
        $batchStart = 0;

        // Accumulated results across all batches.
        $nInserted          = 0;
        $nUpserted          = 0;
        $nMatched           = 0;
        $nModified          = 0;
        $nDeleted           = 0;
        $writeErrors        = [];
        $writeConcernErrors = [];
        $insertResultsMap   = [];
        $updateResultsMap   = [];
        $deleteResultsMap   = [];

        while ($batchStart < $totalOps) {
            // Build the ops slice for this batch, respecting maxWriteBatchSize and maxMessageSizeBytes.
            // Ops are sent as an OP_MSG kind-1 document sequence; nsInfo stays in the kind-0 body.
            // Overhead: OP_MSG header (16) + flagBits (4) + kind-0 marker (1) + kind-1 header (9) +
            // fixed command fields (bulkWrite, ordered, errorsOnly, lsid, $db) + BSON encoding
            // overhead for the ops array structure ≈ 900 bytes total (calibrated to match MongoDB's
            // maxMessageSizeBytes budget so that batch splitting occurs at the right boundary).
            $fixedOverhead   = 900;
            $batchNsIndexMap = [];   // old global ns index → new batch-local index
            $batchNsInfo     = [];
            $batchOps        = [];
            $estimatedNsInfo = 0;
            $estimatedOps    = 0;

            for ($i = $batchStart; $i < $totalOps; $i++) {
                if (count($batchOps) >= $maxWriteBatchSize) {
                    break;
                }

                $op    = $allOps[$i];
                $nsKey = $op['insert'] ?? $op['update'] ?? $op['delete'] ?? 0;
                $oldNs = (int) $nsKey;

                $isNewNs     = ! isset($batchNsIndexMap[$oldNs]);
                $nsAddition  = $isNewNs ? self::estimateBsonSize($allNsInfo[$oldNs]) : 0;
                $opSize      = self::estimateBsonSize($op);
                $newTotal    = $fixedOverhead + $estimatedNsInfo + $nsAddition + $estimatedOps + $opSize;

                if (count($batchOps) > 0 && $newTotal > $maxMessageSizeBytes) {
                    break;
                }

                if ($isNewNs) {
                    $batchNsIndexMap[$oldNs] = count($batchNsInfo);
                    $batchNsInfo[]           = $allNsInfo[$oldNs];
                    $estimatedNsInfo        += $nsAddition;
                }

                $batchOps[]   = $op;
                $estimatedOps += $opSize;
            }

            $batchCount = count($batchOps);

            // Remap ns indices in ops to point to the filtered batch nsInfo.
            $remappedOps = [];
            foreach ($batchOps as $op) {
                $remapped = $op;
                foreach (['insert', 'update', 'delete'] as $opType) {
                    if (! isset($remapped[$opType])) {
                        continue;
                    }

                    $remapped[$opType] = $batchNsIndexMap[(int) $op[$opType]];
                }

                $remappedOps[] = $remapped;
            }

            // Command body (kind 0): ops are NOT included here; they are sent as a kind-1
            // document sequence so the kind-0 body stays well below maxBsonObjectSize.
            // The APM event reconstructs ops from the doc sequence via normalizeDocSeqForApm().
            $command = [
                'bulkWrite'  => 1,
                'nsInfo'     => $batchNsInfo,
                'ordered'    => $ordered,
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
                serverApi:    $this->serverApi,
            );

            // Send this batch using 'ops' as a kind-1 OP_MSG document sequence so that
            // the kind-0 body stays well below maxBsonObjectSize.
            try {
                $body = $this->doSendCommand(
                    $pool,
                    'admin',
                    'bulkWrite',
                    $prepared,
                    $server,
                    $operationId,
                    [['id' => 'ops', 'docs' => $remappedOps]],
                    $deadlineNs,
                );
            } catch (CommandException $e) {
                throw BulkWriteCommandException::create(
                    message:        $e->getMessage(),
                    code:           $e->getCode(),
                    resultDocument: $e->getResultDocument(),
                    errorReply:     Document::fromPHP($e->getResultDocument()),
                );
            }

            // Accumulate summary counts.
            $nInserted += (int) ($body['nInserted'] ?? 0);
            $nUpserted += (int) ($body['nUpserted'] ?? 0);
            $nMatched  += (int) ($body['nMatched']  ?? 0);
            $nModified += (int) ($body['nModified'] ?? 0);
            $nDeleted  += (int) ($body['nDeleted']  ?? 0);

            // Per-operation results / errors from cursor (idx is relative to this batch).
            $resultsCursor = $this->buildCursor($body, 'admin', 'bulkWrite', $pool, $server);

            foreach ($resultsCursor as $doc) {
                $doc       = is_array($doc) ? $doc : (array) $doc;
                $ok        = (int) ($doc['ok'] ?? 1);
                $globalIdx = $batchStart + (int) ($doc['idx'] ?? 0);

                if ($ok === 0) {
                    $writeErrors[$globalIdx] = WriteError::create(
                        code:    (int) ($doc['code']   ?? 0),
                        index:   $globalIdx,
                        message: (string) ($doc['errmsg'] ?? ''),
                        info:    isset($doc['errInfo']) ? (object) $doc['errInfo'] : new stdClass(),
                    );
                    continue;
                }

                if (! $verboseResults) {
                    continue;
                }

                $op = $allOps[$globalIdx] ?? [];

                if (isset($op['insert'])) {
                    if (isset($insertedIds[$globalIdx])) {
                        $insertResultsMap[(string) $globalIdx] = (object) ['insertedId' => $insertedIds[$globalIdx]];
                    }
                } elseif (isset($op['update'])) {
                    $res = (object) [
                        'matchedCount'  => new Int64($doc['n'] ?? 0),
                        'modifiedCount' => new Int64($doc['nModified'] ?? 0),
                    ];
                    $upserted = $doc['upserted'] ?? null;
                    if ($upserted !== null) {
                        $upsertedArr     = is_array($upserted) ? $upserted : (array) $upserted;
                        $res->upsertedId = $upsertedArr['_id'] ?? null;
                    }

                    $updateResultsMap[(string) $globalIdx] = $res;
                } elseif (isset($op['delete'])) {
                    $deleteResultsMap[(string) $globalIdx] = (object) ['deletedCount' => new Int64($doc['n'] ?? 0)];
                }
            }

            // Write concern error from top-level response body.
            if (isset($body['writeConcernError'])) {
                $wce = (array) $body['writeConcernError'];
                $writeConcernErrors[] = WriteConcernError::create(
                    code:    (int) ($wce['code']   ?? 0),
                    message: (string) ($wce['errmsg'] ?? ''),
                );
            }

            $batchStart += $batchCount;

            // For ordered writes, stop sending further batches if any write errors occurred.
            if ($ordered && $writeErrors !== []) {
                break;
            }
        }

        $insertResultsDoc = $verboseResults && $insertResultsMap !== []
            ? Document::fromPHP((object) $insertResultsMap) : null;
        $updateResultsDoc = $verboseResults && $updateResultsMap !== []
            ? Document::fromPHP((object) $updateResultsMap) : null;
        $deleteResultsDoc = $verboseResults && $deleteResultsMap !== []
            ? Document::fromPHP((object) $deleteResultsMap) : null;

        $result = BulkWriteCommandResult::createFromInternal(
            insertedCount: $acknowledged ? $nInserted : 0,
            matchedCount:  $acknowledged ? $nMatched : 0,
            modifiedCount: $acknowledged ? $nModified : 0,
            upsertedCount: $acknowledged ? $nUpserted : 0,
            deletedCount:  $acknowledged ? $nDeleted : 0,
            acknowledged:  $acknowledged,
            insertResults: $insertResultsDoc,
            updateResults: $updateResultsDoc,
            deleteResults: $deleteResultsDoc,
        );

        if ($writeErrors !== [] || $writeConcernErrors !== []) {
            throw BulkWriteCommandException::create(
                message:            'Bulk write failed',
                code:               0,
                partialResult:      $result,
                writeErrors:        $writeErrors,
                writeConcernErrors: $writeConcernErrors,
            );
        }

        return $result;
    }

    /**
     * Send a `commitTransaction` command for the given session.
     *
     * Called from {@see Session::commitTransaction()} after state validation.
     * The caller is responsible for transitioning the session state.
     */
    public function commitTransaction(Session $session): void
    {
        $this->ensureStarted();

        $server = $this->topology->selectServer(new ReadPreference(ReadPreference::PRIMARY));
        $pool   = $this->getOrCreatePool($server->host, $server->port);

        $prepared = CommandHelper::prepareCommand(
            command:   ['commitTransaction' => 1],
            db:        'admin',
            session:   $session,
            serverApi: $this->serverApi,
        );

        $this->doSendCommand($pool, 'admin', 'commitTransaction', $prepared, $server);
    }

    /**
     * Send an `abortTransaction` command for the given session.
     *
     * Called from {@see Session::abortTransaction()} after state validation.
     * Errors are silently swallowed by the caller per the transactions spec.
     */
    public function abortTransaction(Session $session): void
    {
        $this->ensureStarted();

        $server = $this->topology->selectServer(new ReadPreference(ReadPreference::PRIMARY));
        $pool   = $this->getOrCreatePool($server->host, $server->port);

        $prepared = CommandHelper::prepareCommand(
            command:   ['abortTransaction' => 1],
            db:        'admin',
            session:   $session,
            serverApi: $this->serverApi,
        );

        $this->doSendCommand($pool, 'admin', 'abortTransaction', $prepared, $server);
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
        int $operationId = 0,
        array $docSequences = [],
        ?int $deadlineNs = null,
    ): array {
        // CSOT: check whether the deadline has already elapsed before sending.
        $socketTimeoutSecs = null;
        if ($deadlineNs !== null) {
            $remainingNs = $deadlineNs - hrtime(true);
            if ($remainingNs <= 0) {
                throw new ExecutionTimeoutException('Operation exceeded timeoutMS', 50);
            }

            // Inject maxTimeMS (server-side timeout) when not already set by the caller.
            // The spec requires: maxTimeMS = remaining ms, minimum 1.
            if (! isset($prepared['maxTimeMS'])) {
                $prepared['maxTimeMS'] = max(1, intdiv($remainingNs, 1_000_000));
            }

            // Use remaining time as the socket read timeout.
            $socketTimeoutSecs = $remainingNs / 1e9;
        }

        // Detect unacknowledged writes (writeConcern w=0). Per the Command Monitoring spec,
        // APM events for unacknowledged writes must use a synthetic {ok: 1} reply.
        // Per PHPC-1163, unacknowledged writes must also omit the implicit session lsid.
        $wc = $prepared['writeConcern'] ?? null;
        $isUnacknowledged = is_array($wc) && ($wc['w'] ?? 1) === 0;

        // Inject an implicit session lsid if the command doesn't already have one,
        // unless this is an unacknowledged write (sessions are incompatible with w=0).
        $implicitLsid = null;
        if (! isset($prepared['lsid']) && ! $isUnacknowledged) {
            $implicitLsid      = $this->sessionPool->acquire();
            $prepared['lsid']  = $implicitLsid;
        }

        $conn      = $pool->acquire();
        $requestId = RequestIdGenerator::next();
        $startNs = hrtime(true);

        // Use the server's hello-response connectionId as the serverConnectionId for monitoring events.
        $serverConnId = isset($server->helloResponse['connectionId'])
            ? (int) $server->helloResponse['connectionId']
            : null;

        // Remove fields that are sent as OP_MSG kind-1 document sequences so they do not
        // appear twice (once in kind-0 body and once in kind-1 section).
        $bodyForEncoding = $prepared;
        foreach ($docSequences as $seq) {
            unset($bodyForEncoding[$seq['id']]);
        }

        $this->dispatcher->dispatchCommandStarted(
            $cmdName,
            static function () use ($bodyForEncoding, $docSequences, $prepared): object {
                // Build APM command doc from the (small) body only.
                // Re-add doc-sequence fields item-by-item using normalizeDocSeqForApm() instead of the
                // PackedArray::fromPHP()->toPHP() round-trip, which builds the entire BSON blob in one
                // allocation before decoding it and causes OOM for large batches (e.g. 100 000 ops).
                $commandDoc = Document::fromPHP($bodyForEncoding)->toPHP(['root' => 'object', 'document' => 'object']);
                assert($commandDoc instanceof stdClass);
                foreach ($docSequences as $seq) {
                    // Fall back to the doc sequence's own docs when the field is absent from the
                    // prepared command (e.g. bulkWrite ops are no longer stored in the command body).
                    $items = $prepared[$seq['id']] ?? $seq['docs'];
                    $commandDoc->{$seq['id']} = self::normalizeDocSeqForApm($items);
                }

                return $commandDoc;
            },
            $db,
            $requestId,
            $server->host,
            $server->port,
            $serverConnId,
            $operationId ?: $requestId,
        );

        try {
            [$bytes] = OpMsgEncoder::encodeWithRequestId($bodyForEncoding, $docSequences);

            $responseBytes = $conn->sendMessage($bytes, $socketTimeoutSecs);
            $durationUs    = intdiv(hrtime(true) - $startNs, 1_000);

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
                $this->dispatcher->dispatchCommandFailed($cmdName, $eventErr, $db, $requestId, $durationUs, $server->host, $server->port, (object) $body, $serverConnId);

                $pool->release($conn);

                if ($code === 50) {
                    throw new ExecutionTimeoutException($errmsg, $code, (object) $body);
                }

                throw new CommandException($errmsg, $code, (object) $body);
            }

            // Check for write concern errors on ok:1 responses (e.g. findAndModify with unsatisfiable w).
            $wce = $body['writeConcernError'] ?? null;
            if ($wce !== null) {
                $wceArr  = is_array($wce) ? $wce : (array) $wce;
                $wceMsg  = (string) ($wceArr['errmsg'] ?? 'Write Concern error');
                $wceCode = (int) ($wceArr['code'] ?? 0);
                $eventErr = new ServerException($wceMsg, $wceCode, (object) $body);
                $this->dispatcher->dispatchCommandFailed($cmdName, $eventErr, $db, $requestId, $durationUs, $server->host, $server->port, (object) $body, $serverConnId, $operationId ?: $requestId);
                $pool->release($conn);

                throw new CommandException(sprintf('Write Concern error: %s', $wceMsg), $wceCode, (object) $body);
            }

            // Unacknowledged writes must report {ok: 1} in APM (no result fields).
            $apmReply = $isUnacknowledged ? (object) ['ok' => 1] : (object) $body;

            $this->dispatcher->dispatchCommandSucceeded($cmdName, $apmReply, $db, $requestId, $durationUs, $server->host, $server->port, $serverConnId, $operationId ?: $requestId);

            $pool->release($conn);

            return $body;
        } catch (CommandException $e) {
            throw $e;
        } catch (ConnectionTimeoutException $e) {
            $durationUs = intdiv(hrtime(true) - $startNs, 1_000);
            $wrapped    = new ConnectionTimeoutException(
                sprintf('Failed to send "%s" command with database "%s": %s', $cmdName, $db, $e->getMessage()),
                $e->getCode(),
                $e,
            );
            $this->dispatcher->dispatchCommandFailed($cmdName, $wrapped, $db, $requestId, $durationUs, $server->host, $server->port, null, $serverConnId, $operationId ?: $requestId);
            $conn->close();
            $pool->release($conn);

            throw $wrapped;
        } catch (ConnectionException $e) {
            $durationUs = intdiv(hrtime(true) - $startNs, 1_000);
            $wrapped    = new ConnectionException(
                sprintf('Failed to send "%s" command with database "%s": %s', $cmdName, $db, $e->getMessage()),
                $e->getCode(),
                $e,
            );
            $this->dispatcher->dispatchCommandFailed($cmdName, $wrapped, $db, $requestId, $durationUs, $server->host, $server->port, null, $serverConnId, $operationId ?: $requestId);
            $conn->close();
            $pool->release($conn);

            throw $wrapped;
        } catch (Throwable $e) {
            $durationUs = intdiv(hrtime(true) - $startNs, 1_000);
            $this->dispatcher->dispatchCommandFailed($cmdName, $e, $db, $requestId, $durationUs, $server->host, $server->port, null, $serverConnId, $operationId ?: $requestId);

            $pool->release($conn);

            throw $e;
        } finally {
            if ($implicitLsid !== null) {
                $this->sessionPool->release($implicitLsid);
            }
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
        ?Server $callingServer = null,
        ?Query $debugQuery = null,
        ?int $deadlineNs = null,
    ): CursorInterface {
        $body = $this->doSendCommand($pool, $db, $cmdName, $prepared, $server, deadlineNs: $deadlineNs);

        $this->advanceSessionFromResponse($session, $body);

        // Pass batchSize and comment through to getMore commands (spec requirement).
        // find uses top-level batchSize; aggregate uses cursor.batchSize (array or stdClass).
        $cursorOpt = $prepared['cursor'] ?? null;
        $batchSize = isset($prepared['batchSize'])
            ? (int) $prepared['batchSize']
            : (int) (is_array($cursorOpt) ? ($cursorOpt['batchSize'] ?? 0) : ($cursorOpt->batchSize ?? 0));
        $comment = $prepared['comment'] ?? null;

        return $this->buildCursor($body, $db, $cmdName, $pool, $server, $maxAwaitTimeMS, $debugCommand, $session, $batchSize, $comment, $callingServer, $debugQuery);
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
        int $batchSize = 0,
        mixed $comment = null,
        ?Server $callingServer = null,
        ?Query $debugQuery = null,
    ): CursorInterface {
        $publicServer = $callingServer ?? $this->buildPublicServer($server);

        // Commands that return a cursor sub-document (find, aggregate, …).
        $rawCursor = $body['cursor'] ?? null;
        if ($rawCursor !== null && (is_array($rawCursor) || is_object($rawCursor))) {
            $cursorIdRaw = is_array($rawCursor) ? ($rawCursor['id'] ?? 0) : ($rawCursor->id ?? 0);
            $cursorId   = $cursorIdRaw instanceof Int64 ? (int) (string) $cursorIdRaw : (int) $cursorIdRaw;
            $nsRaw      = is_array($rawCursor) ? ($rawCursor['ns'] ?? null) : ($rawCursor->ns ?? null);
            $ns         = (string) ($nsRaw ?? $db);
            $batchRaw   = is_array($rawCursor) ? ($rawCursor['firstBatch'] ?? []) : ($rawCursor->firstBatch ?? []);
            $firstBatch = (array) $batchRaw;

            $getMoreFn = function (int $cursorId, string $ns) use ($pool, $db, $maxAwaitTimeMS, $batchSize, $comment, $server, $session): array {
                $getMoreCmd = [
                    'getMore'    => new Int64($cursorId),
                    'collection' => explode('.', $ns, 2)[1] ?? $ns,
                ];

                if ($batchSize > 0) {
                    $getMoreCmd['batchSize'] = $batchSize;
                }

                if ($comment !== null) {
                    $getMoreCmd['comment'] = $comment;
                }

                if ($maxAwaitTimeMS > 0) {
                    $getMoreCmd['maxTimeMS'] = $maxAwaitTimeMS;
                }

                $prepared  = CommandHelper::prepareCommand(command: $getMoreCmd, db: $db, session: $session, serverApi: $this->serverApi);
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
                query:      $debugQuery,
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
                maxConnecting:      $this->options->maxConnecting,
                waitQueueTimeoutMS: $this->options->waitQueueTimeoutMS,
                options:            $this->options,
                dispatcher:         $this->dispatcher,
            );
        }

        return $this->pools[$address];
    }

    // -------------------------------------------------------------------------
    // Private — retry helpers
    // -------------------------------------------------------------------------

    /**
     * True when the server supports retryable reads/writes (wire version >= 6).
     */
    private function serverSupportsRetry(InternalServerDescription $server): bool
    {
        return ($server->helloResponse['maxWireVersion'] ?? 0) >= 6;
    }

    /**
     * True when the server supports retryable writes:
     * wire version >= 6, logicalSessionTimeoutMinutes present, not standalone.
     */
    private function serverSupportsRetryableWrites(InternalServerDescription $server): bool
    {
        return ($server->helloResponse['maxWireVersion'] ?? 0) >= 6
            && isset($server->helloResponse['logicalSessionTimeoutMinutes'])
            && $server->type !== InternalServerDescription::TYPE_STANDALONE;
    }

    /**
     * Increment and return the txnNumber for the given server session lsid.
     * The lsid's id is a BSON Binary; its raw bytes are used as a pool key.
     */
    private function nextTxnNumber(object $lsid): int
    {
        $key = bin2hex($lsid->id->getData());

        return $this->txnNumbers[$key] = ($this->txnNumbers[$key] ?? 0) + 1;
    }

    /**
     * True when a bulk-write batch is eligible for retryable writes.
     * Batches with multi-document update (multi:true) or multi-document delete
     * (limit:0) are not retryable per the spec.
     */
    private function isBatchRetryableForWrite(array $batch): bool
    {
        $type = $batch['type'];

        if ($type === 'insert') {
            return true;
        }

        if ($type === 'update') {
            foreach ($batch['ops'] as $op) {
                [, , , $opts] = $op;
                if ($opts['multi'] ?? false) {
                    return false;
                }
            }

            return true;
        }

        if ($type === 'delete') {
            foreach ($batch['ops'] as $op) {
                [, , $opts] = $op;
                if (($opts['limit'] ?? 1) === 0) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Execute a bulk-write batch command, retrying once on a retryable error when
     * $retryable is true.  The caller must have already injected lsid / txnNumber
     * into $prepared if retryable writes are active.
     *
     * Returns the decoded first response document as an array.
     *
     * @throws CommandException|ConnectionException on non-retryable or second-attempt failure.
     */
    private function executeBatchWithRetry(
        ConnectionPool $pool,
        InternalServerDescription $server,
        string $db,
        string $cmdName,
        array $prepared,
        ?Session $session,
        bool $retryable,
        ?int $deadlineNs = null,
    ): array {
        try {
            $cursor = $this->sendCommand($pool, $db, $cmdName, $prepared, $server, 0, null, $session, deadlineNs: $deadlineNs);

            return (array) (iterator_to_array($cursor)[0] ?? []);
        } catch (Throwable $e) {
            if (! $retryable || ! RetryableError::isRetryable($e)) {
                throw $e;
            }
        }

        // One retry: select a fresh server and pool.
        $retryServer = $this->topology->selectServer(new ReadPreference(ReadPreference::PRIMARY), $this->remainingSelectionMs($deadlineNs));
        $retryPool   = $this->getOrCreatePool($retryServer->host, $retryServer->port);

        $cursor = $this->sendCommand($retryPool, $db, $cmdName, $prepared, $retryServer, 0, null, $session, deadlineNs: $deadlineNs);

        return (array) (iterator_to_array($cursor)[0] ?? []);
    }

    // -------------------------------------------------------------------------
    // Private — misc helpers
    // -------------------------------------------------------------------------

    /**
     * Normalize an update document for wire encoding.
     *
     * - Empty PHP array [] → stdClass {} (replacement document, not a pipeline)
     * - PHP object with consecutive integer keys '0','1',… → PHP list array
     *   (encodes as BSON array = update pipeline; replicates libmongoc CDRIVER-4658)
     * - Everything else → unchanged.
     */
    private static function normalizeUpdateDocument(array|object $doc): array|object
    {
        if (is_array($doc)) {
            // Empty PHP array must encode as {} (replacement), not [] (pipeline).
            return count($doc) === 0 ? (object) $doc : $doc;
        }

        // Only apply the sequential-int-key → pipeline heuristic for plain stdClass
        // objects.  Rich objects (BSONDocument, Document, PackedArray, …) are
        // encoded correctly by the BSON encoder directly.
        if (! ($doc instanceof stdClass)) {
            return $doc;
        }

        $vars = get_object_vars($doc);
        if (count($vars) === 0) {
            return $doc; // empty stdClass stays as {} replacement document
        }

        $expectedKey = 0;
        foreach ($vars as $key => $value) {
            if ((string) $key !== (string) $expectedKey) {
                return $doc;
            }

            ++$expectedKey;
        }

        // All keys are consecutive integers → encode as BSON array (pipeline).
        return array_values($vars);
    }

    private function splitNamespace(string $namespace): array
    {
        $pos = strpos($namespace, '.');
        if ($pos === false) {
            throw new InvalidArgumentException(sprintf('Invalid namespace provided: %s', $namespace));
        }

        return [substr($namespace, 0, $pos), substr($namespace, $pos + 1)];
    }

    /**
     * Build a public {@see \MongoDB\Driver\Server} from an internal server description.
     */
    private function buildPublicServer(InternalServerDescription $sd): Server
    {
        // Map the internal type string to the public integer constant.
        $publicType = match ($sd->type) {
            InternalServerDescription::TYPE_STANDALONE    => Server::TYPE_STANDALONE,
            InternalServerDescription::TYPE_MONGOS        => Server::TYPE_MONGOS,
            InternalServerDescription::TYPE_RS_PRIMARY    => Server::TYPE_RS_PRIMARY,
            InternalServerDescription::TYPE_RS_SECONDARY  => Server::TYPE_RS_SECONDARY,
            InternalServerDescription::TYPE_RS_ARBITER    => Server::TYPE_RS_ARBITER,
            InternalServerDescription::TYPE_RS_OTHER      => Server::TYPE_RS_OTHER,
            InternalServerDescription::TYPE_RS_GHOST      => Server::TYPE_RS_GHOST,
            InternalServerDescription::TYPE_LOAD_BALANCER => Server::TYPE_LOAD_BALANCER,
            InternalServerDescription::TYPE_UNKNOWN       => Server::TYPE_UNKNOWN,
            default                                       => Server::TYPE_UNKNOWN,
        };

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
            latency:           $sd->roundTripTimeMs !== null ? (int) round($sd->roundTripTimeMs) : null,
            serverDescription: $serverDescription,
            info:              $sd->helloResponse,
            tags:              $sd->tags,
            executor:          $this,
        );
    }

    /**
     * Normalise doc-sequence items for APM without a full BSON round-trip.
     *
     * Doc-sequence items are always plain PHP arrays (produced by the op-builder
     * methods). Associative arrays are converted to stdClass recursively so that
     * the APM command document matches what Document::fromPHP()->toPHP() would
     * return for an equivalent body field.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<stdClass>
     */
    private static function normalizeDocSeqForApm(array $items): array
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if ($value instanceof Document) {
                return $value->toPHP(['root' => 'object', 'document' => 'object', 'array' => 'array']);
            }

            if ($value instanceof PackedArray) {
                return $value->toPHP(['root' => 'array', 'document' => 'object', 'array' => 'array']);
            }

            if (is_array($value)) {
                $normalized = array_map($normalize, $value);

                return array_is_list($value) ? $normalized : (object) $normalized;
            }

            return $value;
        };

        return array_map($normalize, $items);
    }

    /**
     * Compute a CSOT deadline in nanoseconds from timeoutMS, or null if unset.
     *
     * Called once per top-level operation; the returned value is threaded through
     * all internal calls (server selection, doSendCommand).
     */
    private function computeDeadlineNs(): ?int
    {
        return $this->options->timeoutMS !== null
            ? hrtime(true) + $this->options->timeoutMS * 1_000_000
            : null;
    }

    /**
     * Convert a CSOT deadline to a remaining server-selection timeout in ms.
     *
     * Returns null to use the topology's configured serverSelectionTimeoutMS.
     */
    private function remainingSelectionMs(?int $deadlineNs): ?int
    {
        if ($deadlineNs === null) {
            return null;
        }

        return max(0, intdiv($deadlineNs - hrtime(true), 1_000_000));
    }

    /**
     * Estimate the BSON-encoded size of a document without allocating the BSON bytes.
     *
     * This is used to detect oversized documents before attempting to encode them,
     * which would cause out-of-memory errors when documents approach maxMessageSizeBytes.
     */
    private static function estimateBsonSize(array|object $doc): int
    {
        // Document/PackedArray already carry their BSON bytes — exact size in O(1).
        if ($doc instanceof Document || $doc instanceof PackedArray) {
            return strlen((string) $doc);
        }

        $fields = is_object($doc) ? get_object_vars($doc) : $doc;
        $size   = 5; // 4-byte document length + 1-byte terminator

        foreach ($fields as $key => $value) {
            $size += 1 + strlen((string) $key) + 1; // type byte + key + null terminator

            if (is_string($value)) {
                $size += 4 + strlen($value) + 1; // int32 length + string bytes + null terminator
            } elseif (is_array($value) || is_object($value)) {
                $size += self::estimateBsonSize($value);
            } else {
                $size += 8; // conservative estimate for scalar BSON types
            }
        }

        return $size;
    }
}
