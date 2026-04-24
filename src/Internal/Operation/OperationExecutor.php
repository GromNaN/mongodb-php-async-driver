<?php

declare(strict_types=1);

namespace MongoDB\Internal\Operation;

use Exception;
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
use MongoDB\Internal\Session\SessionPool;
use MongoDB\Internal\Topology\InternalServerDescription;
use MongoDB\Internal\Topology\TopologyManager;
use MongoDB\Internal\Uri\UriOptions;
use RuntimeException;
use stdClass;
use Throwable;

use function array_map;
use function array_search;
use function array_values;
use function assert;
use function count;
use function explode;
use function get_object_vars;
use function hrtime;
use function in_array;
use function intdiv;
use function is_array;
use function is_object;
use function is_string;
use function iterator_to_array;
use function sprintf;
use function strlen;
use function strpos;
use function strtolower;
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
     * @param SessionPool      $sessionPool Session pool for implicit sessions.
     * @param list<Subscriber> $subscribers Monitoring subscribers.
     */
    public function __construct(
        private TopologyManager $topology,
        private UriOptions $options,
        private SessionPool $sessionPool,
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

        $maxAwaitTimeMS = (int) ($command->getOptions()['maxAwaitTimeMS'] ?? 0);

        return $this->sendCommand($pool, $db, $cmdName, $prepared, $server, $maxAwaitTimeMS, $command, $session);
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
        $errorReplies   = [];
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

                if ($bulkOptions['bypassDocumentValidation'] ?? false) {
                    $insertBase['bypassDocumentValidation'] = true;
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
                        throw $e;
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
            errorReplies:    $errorReplies,
        );

        if ($errorReplies !== []) {
            $reply = (array) $errorReplies[0];

            throw new BulkWriteException(
                message:     (string) ($reply['errmsg'] ?? ''),
                code:        (int) ($reply['code'] ?? 0),
                writeResult: $writeResult,
            );
        }

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

        if ($session !== null && $writeConcern !== null && $writeConcern->getW() === 0) {
            throw new DriverInvalidArgumentException(
                'Cannot combine "session" option with an unacknowledged write concern',
            );
        }

        $server = $this->topology->selectServer(new ReadPreference(ReadPreference::PRIMARY));
        $pool   = $this->getOrCreatePool($server->host, $server->port);

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
                throw new DriverInvalidArgumentException('unable to send document: namespace is too large');
            }
        }

        foreach ($allOps as $op) {
            $docToCheck = $op['document'] ?? null;
            if ($docToCheck !== null && self::estimateBsonSize($docToCheck) >= $maxMessageSizeBytes) {
                throw new DriverInvalidArgumentException('unable to send document: document is too large');
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

            // Command body (kind 0): no ops field; ops go in kind-1 doc sequence.
            // Include ops in the command for APM events (CommandStartedEvent.getCommand()).
            $command = [
                'bulkWrite'  => 1,
                'ops'        => $remappedOps,
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
    ): array {
        // Inject an implicit session lsid if the command doesn't already have one.
        $implicitLsid = null;
        if (! isset($prepared['lsid'])) {
            $implicitLsid      = $this->sessionPool->acquire();
            $prepared['lsid']  = $implicitLsid;
        }

        // Detect unacknowledged writes (writeConcern w=0). Per the Command Monitoring spec,
        // APM events for unacknowledged writes must use a synthetic {ok: 1} reply.
        $wc = $prepared['writeConcern'] ?? null;
        $isUnacknowledged = is_array($wc) && ($wc['w'] ?? 1) === 0;

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

        // Build APM command doc from the (small) body only, avoiding BSON-encoding of large
        // doc-sequence fields (e.g. ops containing 16 MB documents) which would cause OOM.
        // Re-add doc-sequence fields as PHP arrays; CommandStartedEvent consumers receive them
        // as plain arrays which is equivalent to the decoded BSON representation.
        $commandDoc = Document::fromPHP($bodyForEncoding)->toPHP(['root' => 'object', 'document' => 'object']);
        assert($commandDoc instanceof stdClass);
        foreach ($docSequences as $seq) {
            $items = $prepared[$seq['id']] ?? [];
            // Decode each item as an object (matching ext-mongodb APM behaviour).
            $commandDoc->{$seq['id']} = PackedArray::fromPHP($items)
                ->toPHP(['root' => 'array', 'document' => 'object']);
        }

        // Sensitive commands must have their command body replaced with {} in APM events.
        $isSensitive = self::isSensitiveCommand($cmdName, $commandDoc);
        $this->fireCommandStarted($cmdName, $isSensitive ? new stdClass() : $commandDoc, $db, $requestId, $server->host, $server->port, $serverConnId, $operationId ?: $requestId);

        try {
            [$bytes] = OpMsgEncoder::encodeWithRequestId($bodyForEncoding, $docSequences);

            $responseBytes = $conn->sendMessage($bytes);
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
                $this->fireCommandFailed($cmdName, $eventErr, $db, $requestId, $durationUs, $server->host, $server->port, $isSensitive ? new stdClass() : (object) $body, $serverConnId);

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
                $this->fireCommandFailed($cmdName, $eventErr, $db, $requestId, $durationUs, $server->host, $server->port, $isSensitive ? new stdClass() : (object) $body, $serverConnId, $operationId ?: $requestId);
                $pool->release($conn);

                throw new CommandException(sprintf('Write Concern error: %s', $wceMsg), $wceCode, (object) $body);
            }

            // Unacknowledged writes must report {ok: 1} in APM (no result fields).
            $apmReply = $isSensitive || $isUnacknowledged ? new stdClass() : (object) $body;
            if (! $isSensitive && $isUnacknowledged) {
                $apmReply->ok = 1;
            }

            $this->fireCommandSucceeded($cmdName, $apmReply, $db, $requestId, $durationUs, $server->host, $server->port, $serverConnId, $operationId ?: $requestId);

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
            $this->fireCommandFailed($cmdName, $wrapped, $db, $requestId, $durationUs, $server->host, $server->port, null, $serverConnId, $operationId ?: $requestId);
            $pool->release($conn);

            throw $wrapped;
        } catch (ConnectionException $e) {
            $durationUs = intdiv(hrtime(true) - $startNs, 1_000);
            $wrapped    = new ConnectionException(
                sprintf('Failed to send "%s" command with database "%s": %s', $cmdName, $db, $e->getMessage()),
                $e->getCode(),
                $e,
            );
            $this->fireCommandFailed($cmdName, $wrapped, $db, $requestId, $durationUs, $server->host, $server->port, null, $serverConnId, $operationId ?: $requestId);
            $pool->release($conn);

            throw $wrapped;
        } catch (Throwable $e) {
            $durationUs = intdiv(hrtime(true) - $startNs, 1_000);
            $this->fireCommandFailed($cmdName, $e, $db, $requestId, $durationUs, $server->host, $server->port, null, $serverConnId, $operationId ?: $requestId);

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
    ): CursorInterface {
        $body = $this->doSendCommand($pool, $db, $cmdName, $prepared, $server);

        $this->advanceSessionFromResponse($session, $body);

        // Pass batchSize and comment through to getMore commands (spec requirement).
        // find uses top-level batchSize; aggregate uses cursor.batchSize.
        $cursorOpt = $prepared['cursor'] ?? null;
        $batchSize = isset($prepared['batchSize'])
            ? (int) $prepared['batchSize']
            : (int) ((is_array($cursorOpt) ? ($cursorOpt['batchSize'] ?? 0) : 0));
        $comment = $prepared['comment'] ?? null;

        return $this->buildCursor($body, $db, $cmdName, $pool, $server, $maxAwaitTimeMS, $debugCommand, $session, $batchSize, $comment);
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
        int $operationId = 0,
    ): void {
        $event = null;
        GlobalSubscriberRegistry::dispatch(
            $this->subscribers,
            CommandSubscriber::class,
            static fn (CommandSubscriber $subscriber) => $subscriber->commandStarted(
                $event ??= CommandStartedEvent::create(
                    commandName:         $cmdName,
                    command:             $cmd,
                    databaseName:        $db,
                    requestId:           $requestId,
                    operationId:         $operationId ?: $requestId,
                    host:                $host,
                    port:                $port,
                    serverConnectionId:  $serverConnectionId,
                ),
            ),
        );
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
        int $operationId = 0,
    ): void {
        $event = null;
        GlobalSubscriberRegistry::dispatch(
            $this->subscribers,
            CommandSubscriber::class,
            static fn (CommandSubscriber $subscriber) => $subscriber->commandSucceeded(
                $event ??= CommandSucceededEvent::create(
                    commandName:         $cmdName,
                    reply:               $reply,
                    databaseName:        $db,
                    requestId:           $requestId,
                    operationId:         $operationId ?: $requestId,
                    durationMicros:      $durationMicros,
                    host:                $host,
                    port:                $port,
                    serverConnectionId:  $serverConnectionId,
                ),
            ),
        );
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
        int $operationId = 0,
    ): void {
        $exception = $e instanceof Exception ? $e : new RuntimeException($e->getMessage(), $e->getCode(), $e);
        $event     = null;
        GlobalSubscriberRegistry::dispatch(
            $this->subscribers,
            CommandSubscriber::class,
            static fn (CommandSubscriber $subscriber) => $subscriber->commandFailed(
                $event ??= CommandFailedEvent::create(
                    commandName:         $cmdName,
                    databaseName:        $db,
                    error:               $exception,
                    requestId:           $requestId,
                    operationId:         $operationId ?: $requestId,
                    durationMicros:      $durationMicros,
                    host:                $host,
                    port:                $port,
                    serverConnectionId:  $serverConnectionId,
                    reply:               $reply,
                ),
            ),
        );
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
            throw new DriverInvalidArgumentException(sprintf('Invalid namespace provided: %s', $namespace));
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

    /**
     * Return true when this command must have its body and reply redacted in APM events.
     *
     * Per the Command Monitoring specification, the following commands are sensitive:
     * authenticate, saslStart, saslContinue, getnonce, createUser, updateUser,
     * copydbgetnonce, copydbsaslstart, copydb, and hello / isMaster when they contain
     * speculativeAuthenticate.
     */
    private static function isSensitiveCommand(string $cmdName, object $cmd): bool
    {
        static $unconditional = [
            'authenticate'   => true,
            'saslstart'      => true,
            'saslcontinue'   => true,
            'getnonce'       => true,
            'createuser'     => true,
            'updateuser'     => true,
            'copydbgetnonce' => true,
            'copydbsaslstart' => true,
            'copydb'         => true,
        ];

        $lower = strtolower($cmdName);

        if (isset($unconditional[$lower])) {
            return true;
        }

        // hello (and legacy hello variants) are sensitive only when speculativeAuthenticate is present.
        if ($lower === 'hello' || $lower === 'ismaster' || $lower === 'ismaster') {
            return isset($cmd->speculativeAuthenticate);
        }

        return false;
    }
}
