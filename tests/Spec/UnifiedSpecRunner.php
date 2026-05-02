<?php

declare(strict_types=1);

namespace MongoDB\Tests\Spec;

use MongoDB\BSON\Binary;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Int64;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Exception\RuntimeException as DriverRuntimeException;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Query;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\WriteConcern;
use MongoDB\Driver\WriteResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function end;
use function file_get_contents;
use function http_build_query;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function iterator_to_array;
use function json_decode;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function version_compare;

use const JSON_THROW_ON_ERROR;

/**
 * Minimal unified spec runner supporting schemaVersion ≤ 1.1.
 *
 * Covers CRUD collection/database operations, expectResult, outcome,
 * and expectEvents (commandStartedEvent). TestRunner operations
 * (failPoint, etc.) are skipped with markTestSkipped.
 */
final class UnifiedSpecRunner
{
    private ?array $cachedServerVersion = null;

    public function __construct(private readonly string $uri)
    {
    }

    /**
     * Run a single test case identified by its 0-based index in the fixture file.
     */
    public function runTest(string $file, int $testIndex, TestCase $phpunitTest): void
    {
        $fixture  = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $testCase = $fixture['tests'][$testIndex];

        // Schema version gate
        if (version_compare($fixture['schemaVersion'], '1.1', '>')) {
            $phpunitTest->markTestSkipped(sprintf('schemaVersion %s not supported', $fixture['schemaVersion']));

            return;
        }

        // File-level requirements
        if (! empty($fixture['runOnRequirements'])) {
            if (! $this->checkRequirements($fixture['runOnRequirements'], $phpunitTest)) {
                return;
            }
        }

        // Test-level requirements
        if (! empty($testCase['runOnRequirements'])) {
            if (! $this->checkRequirements($testCase['runOnRequirements'], $phpunitTest)) {
                return;
            }
        }

        // Build entity map: id → entity config
        $entities   = [];
        $collectors = []; // clientId → EventCollector

        foreach ($fixture['createEntities'] ?? [] as $entityDef) {
            $type   = array_key_first($entityDef);
            $config = $entityDef[$type];
            $id     = $config['id'];

            match ($type) {
                'client'     => (function () use (&$entities, &$collectors, $id, $config): void {
                    $manager = new Manager($this->buildUri($config['uriOptions'] ?? []));
                    $collector = null;
                    if (! empty($config['observeEvents'])) {
                        $collector = new EventCollector();
                        $manager->addSubscriber($collector);
                    }

                    $entities[$id] = ['type' => 'client', 'manager' => $manager];
                    if ($collector === null) {
                        return;
                    }

                    $collectors[$id] = $collector;
                })(),
                'database'   => (static function () use (&$entities, $id, $config): void {
                    $entities[$id] = [
                        'type'         => 'database',
                        'clientId'     => $config['client'],
                        'databaseName' => $config['databaseName'],
                    ];
                })(),
                'collection' => (static function () use (&$entities, $id, $config): void {
                    $collOpts = $config['collectionOptions'] ?? [];
                    $entities[$id] = [
                        'type'           => 'collection',
                        'databaseId'     => $config['database'],
                        'collectionName' => $config['collectionName'],
                        'readConcern'    => isset($collOpts['readConcern'])
                            ? new ReadConcern($collOpts['readConcern']['level'] ?? null)
                            : null,
                        'writeConcern'   => isset($collOpts['writeConcern'])
                            ? new WriteConcern($collOpts['writeConcern']['w'] ?? WriteConcern::MAJORITY)
                            : null,
                    ];
                })(),
                default      => null,
            };
        }

        // Seed initial data
        foreach ($fixture['initialData'] ?? [] as $collData) {
            $this->setupCollectionData($entities, $collData);
        }

        // Reset event collectors before the test operations
        foreach ($collectors as $collector) {
            $collector->clear();
        }

        // Execute operations
        foreach ($testCase['operations'] as $operation) {
            $objectId = $operation['object'] ?? null;
            $opName   = $operation['name'];
            $args     = $operation['arguments'] ?? [];

            if ($objectId === 'testRunner') {
                $phpunitTest->markTestSkipped(sprintf('testRunner operation "%s" not supported', $opName));

                return;
            }

            $entity    = $entities[$objectId];
            $result    = null;
            $exception = null;

            try {
                $result = $this->dispatchOperation($entities, $entity, $opName, $args, $phpunitTest);
            } catch (Throwable $e) {
                $exception = $e;
            }

            if (isset($operation['expectError'])) {
                if ($exception === null) {
                    $phpunitTest->fail(sprintf('Expected error for operation "%s" but none was thrown', $opName));
                }

                $this->assertError($phpunitTest, $exception, $operation['expectError']);
            } elseif ($exception !== null) {
                throw $exception;
            }

            if (! array_key_exists('expectResult', $operation) || $exception !== null) {
                continue;
            }

            $this->assertMatch($phpunitTest, $result, $operation['expectResult'], $opName);
        }

        // Snapshot events *before* outcome checks — assertOutcome issues a find
        // on the entity manager and would append extra events to the collector.
        $eventSnapshots = [];
        foreach ($collectors as $clientId => $collector) {
            $eventSnapshots[$clientId] = $collector->getEvents();
        }

        // Verify collection state
        foreach ($testCase['outcome'] ?? [] as $outcomeData) {
            $this->assertOutcome($entities, $outcomeData, $phpunitTest);
        }

        // Verify APM events using the pre-outcome snapshot
        foreach ($testCase['expectEvents'] ?? [] as $expectSpec) {
            $clientId = $expectSpec['client'];
            $events   = $eventSnapshots[$clientId] ?? [];
            $this->assertEvents($phpunitTest, $events, $expectSpec['events']);
        }
    }

    // -------------------------------------------------------------------------
    // Operation dispatch
    // -------------------------------------------------------------------------

    private function dispatchOperation(
        array $entities,
        array $entity,
        string $opName,
        array $args,
        TestCase $test,
    ): mixed {
        // Convert all driver arguments from assoc-array (json_decode assoc mode)
        // to stdClass so that {} is encoded as a BSON document, not a BSON array.
        // The 'requests' key is left as a raw PHP list; individual sub-documents
        // inside each request are fixed inside collectionBulkWrite().
        $fixedArgs = [];
        foreach ($args as $key => $value) {
            $fixedArgs[$key] = $key === 'requests' ? $value : $this->fixDocument($value);
        }

        return match ($entity['type']) {
            'collection' => $this->executeCollectionOp($entities, $entity, $opName, $fixedArgs, $test),
            'database'   => $this->executeDatabaseOp($entities, $entity, $opName, $fixedArgs, $test),
            'client'     => $test->markTestSkipped(sprintf('Client-level operation "%s" not supported', $opName)),
            default      => throw new RuntimeException(sprintf('Unsupported entity type "%s" for operation "%s"', $entity['type'], $opName)),
        };
    }

    // -------------------------------------------------------------------------
    // Collection operations
    // -------------------------------------------------------------------------

    private function executeCollectionOp(
        array $entities,
        array $entity,
        string $opName,
        array $args,
        TestCase $test,
    ): mixed {
        $info = $this->resolveCollection($entities, $entity);

        return match ($opName) {
            'find'                  => $this->find($info, $args),
            'findOne'               => $this->findOne($info, $args),
            'insertOne'             => $this->insertOne($info, $args),
            'insertMany'            => $this->insertMany($info, $args),
            'updateOne'             => $this->updateOne($info, $args),
            'updateMany'            => $this->updateMany($info, $args),
            'replaceOne'            => $this->replaceOne($info, $args),
            'deleteOne'             => $this->deleteOne($info, $args),
            'deleteMany'            => $this->deleteMany($info, $args),
            'findOneAndDelete'      => $this->findAndModify($info, $args, 'delete'),
            'findOneAndReplace'     => $this->findAndModify($info, $args, 'replace'),
            'findOneAndUpdate'      => $this->findAndModify($info, $args, 'update'),
            'aggregate'             => $this->aggregate($info, $args),
            'count'                 => $this->count($info, $args),
            'countDocuments'        => $this->countDocuments($info, $args),
            'estimatedDocumentCount' => $this->estimatedDocumentCount($info, $args),
            'distinct'              => $this->distinct($info, $args),
            'bulkWrite'             => $this->collectionBulkWrite($info, $args),
            default                 => $test->markTestSkipped(sprintf('Unsupported collection operation: %s', $opName)),
        };
    }

    private function find(array $info, array $args): array
    {
        $opts = [];
        foreach (['sort', 'limit', 'skip', 'batchSize', 'projection', 'collation', 'hint', 'comment', 'let', 'allowDiskUse', 'maxTimeMS', 'rawData'] as $key) {
            if (! isset($args[$key])) {
                continue;
            }

            $opts[$key] = $args[$key];
        }

        $cursor = $info['manager']->executeQuery($info['ns'], new Query($args['filter'] ?? [], $opts));

        return array_map([$this, 'normalize'], iterator_to_array($cursor, false));
    }

    private function findOne(array $info, array $args): ?array
    {
        $opts = ['limit' => 1, 'singleBatch' => true];
        foreach (['sort', 'skip', 'projection', 'collation', 'hint', 'comment', 'let', 'maxTimeMS'] as $key) {
            if (! isset($args[$key])) {
                continue;
            }

            $opts[$key] = $args[$key];
        }

        $cursor  = $info['manager']->executeQuery($info['ns'], new Query($args['filter'] ?? [], $opts));
        $results = iterator_to_array($cursor, false);

        return ! empty($results) ? $this->normalize($results[0]) : null;
    }

    private function insertOne(array $info, array $args): array
    {
        $bwOpts = [];
        foreach (['comment', 'bypassDocumentValidation', 'rawData'] as $k) {
            if (! isset($args[$k])) {
                continue;
            }

            $bwOpts[$k] = $args[$k];
        }

        $bw = new BulkWrite($bwOpts);
        $id = $bw->insert($args['document']);
        $wcOpts = $info['writeConcern'] !== null ? ['writeConcern' => $info['writeConcern']] : [];
        $info['manager']->executeBulkWrite($info['ns'], $bw, $wcOpts ?: null);

        return ['insertedId' => $id];
    }

    private function insertMany(array $info, array $args): array
    {
        $bwOpts = ['ordered' => $args['ordered'] ?? true];
        foreach (['comment', 'bypassDocumentValidation', 'rawData'] as $k) {
            if (! isset($args[$k])) {
                continue;
            }

            $bwOpts[$k] = $args[$k];
        }

        $bw  = new BulkWrite($bwOpts);
        $ids = [];
        foreach ($args['documents'] as $doc) {
            $ids[] = $bw->insert($doc);
        }

        $wcOpts = $info['writeConcern'] !== null ? ['writeConcern' => $info['writeConcern']] : [];
        $info['manager']->executeBulkWrite($info['ns'], $bw, $wcOpts ?: null);

        $insertedIds = [];
        foreach (array_keys($ids) as $i) {
            $insertedIds[(string) $i] = $ids[$i];
        }

        return ['insertedIds' => $insertedIds];
    }

    private function updateOne(array $info, array $args): array
    {
        $bwOpts = [];
        foreach (['let', 'comment', 'bypassDocumentValidation', 'rawData'] as $k) {
            if (! isset($args[$k])) {
                continue;
            }

            $bwOpts[$k] = $args[$k];
        }

        $opts = ['multi' => false];
        foreach (['upsert', 'collation', 'hint', 'arrayFilters', 'sort'] as $key) {
            if (! isset($args[$key])) {
                continue;
            }

            $opts[$key] = $args[$key];
        }

        $update = $args['update'];
        $this->validateUpdateDocument($update);

        $bw = new BulkWrite($bwOpts);
        $bw->update($args['filter'], $update, $opts);
        $wcOpts = $info['writeConcern'] !== null ? ['writeConcern' => $info['writeConcern']] : [];
        $result = $info['manager']->executeBulkWrite($info['ns'], $bw, $wcOpts ?: null);

        return $this->buildUpdateResult($result);
    }

    private function updateMany(array $info, array $args): array
    {
        $bwOpts = [];
        foreach (['let', 'comment', 'bypassDocumentValidation', 'rawData'] as $k) {
            if (! isset($args[$k])) {
                continue;
            }

            $bwOpts[$k] = $args[$k];
        }

        $opts = ['multi' => true];
        foreach (['upsert', 'collation', 'hint', 'arrayFilters'] as $key) {
            if (! isset($args[$key])) {
                continue;
            }

            $opts[$key] = $args[$key];
        }

        $update = $args['update'];
        $this->validateUpdateDocument($update);

        $bw = new BulkWrite($bwOpts);
        $bw->update($args['filter'], $update, $opts);
        $wcOpts = $info['writeConcern'] !== null ? ['writeConcern' => $info['writeConcern']] : [];
        $result = $info['manager']->executeBulkWrite($info['ns'], $bw, $wcOpts ?: null);

        return $this->buildUpdateResult($result);
    }

    private function replaceOne(array $info, array $args): array
    {
        $bwOpts = [];
        foreach (['let', 'comment', 'bypassDocumentValidation', 'rawData'] as $k) {
            if (! isset($args[$k])) {
                continue;
            }

            $bwOpts[$k] = $args[$k];
        }

        $opts = ['multi' => false];
        foreach (['upsert', 'collation', 'hint', 'sort'] as $key) {
            if (! isset($args[$key])) {
                continue;
            }

            $opts[$key] = $args[$key];
        }

        $replacement = $args['replacement'];
        // Client-side: replacement must not be an update operator document or pipeline.
        // After fixDocument(), arrays become stdClass and lists remain arrays.
        if ($replacement instanceof stdClass) {
            $keys = array_keys((array) $replacement);
            if (! empty($keys) && str_starts_with((string) $keys[0], '$')) {
                throw new InvalidArgumentException('First key in $replacement is an update operator');
            }
        } elseif (is_array($replacement) && array_is_list($replacement) && ! empty($replacement)) {
            throw new InvalidArgumentException('$replacement is an update pipeline');
        }

        $bw = new BulkWrite($bwOpts);
        $bw->update($args['filter'], $replacement, $opts);
        $wcOpts = $info['writeConcern'] !== null ? ['writeConcern' => $info['writeConcern']] : [];
        $result = $info['manager']->executeBulkWrite($info['ns'], $bw, $wcOpts ?: null);

        return $this->buildUpdateResult($result);
    }

    private function deleteOne(array $info, array $args): array
    {
        $bwOpts = [];
        foreach (['let', 'comment', 'rawData'] as $k) {
            if (! isset($args[$k])) {
                continue;
            }

            $bwOpts[$k] = $args[$k];
        }

        $opts = ['limit' => 1];
        foreach (['collation', 'hint'] as $key) {
            if (! isset($args[$key])) {
                continue;
            }

            $opts[$key] = $args[$key];
        }

        $bw = new BulkWrite($bwOpts);
        $bw->delete($args['filter'], $opts);
        $wcOpts = $info['writeConcern'] !== null ? ['writeConcern' => $info['writeConcern']] : [];
        $result = $info['manager']->executeBulkWrite($info['ns'], $bw, $wcOpts ?: null);

        if (! $result->isAcknowledged()) {
            return ['acknowledged' => false];
        }

        return ['deletedCount' => $result->getDeletedCount()];
    }

    private function deleteMany(array $info, array $args): array
    {
        $bwOpts = [];
        foreach (['let', 'comment', 'rawData'] as $k) {
            if (! isset($args[$k])) {
                continue;
            }

            $bwOpts[$k] = $args[$k];
        }

        $opts = ['limit' => 0];
        foreach (['collation', 'hint'] as $key) {
            if (! isset($args[$key])) {
                continue;
            }

            $opts[$key] = $args[$key];
        }

        $bw = new BulkWrite($bwOpts);
        $bw->delete($args['filter'], $opts);
        $wcOpts = $info['writeConcern'] !== null ? ['writeConcern' => $info['writeConcern']] : [];
        $result = $info['manager']->executeBulkWrite($info['ns'], $bw, $wcOpts ?: null);

        if (! $result->isAcknowledged()) {
            return ['acknowledged' => false];
        }

        return ['deletedCount' => $result->getDeletedCount()];
    }

    private function findAndModify(array $info, array $args, string $mode): ?array
    {
        $cmd = ['findAndModify' => $info['collName'], 'query' => $args['filter'] ?? []];

        if ($mode === 'delete') {
            $cmd['remove'] = true;
        } else {
            $cmd['update'] = $mode === 'replace' ? $args['replacement'] : $args['update'];
            if (($args['returnDocument'] ?? 'Before') === 'After') {
                $cmd['new'] = true;
            }

            if (isset($args['upsert'])) {
                $cmd['upsert'] = $args['upsert'];
            }

            if (isset($args['arrayFilters'])) {
                $cmd['arrayFilters'] = $args['arrayFilters'];
            }
        }

        foreach (['sort', 'collation', 'hint', 'let', 'comment', 'maxTimeMS', 'rawData'] as $key) {
            if (! isset($args[$key])) {
                continue;
            }

            $cmd[$key] = $args[$key];
        }

        // findAndModify uses 'fields' not 'projection'
        if (isset($args['projection'])) {
            $cmd['fields'] = $args['projection'];
        }

        // Include collection-level write concern if set
        $unacknowledged = false;
        if ($info['writeConcern'] !== null) {
            $wcLevel = $info['writeConcern']->getW();
            $cmd['writeConcern'] = ['w' => $wcLevel];
            $unacknowledged      = ($wcLevel === 0);
        }

        $cursor = $info['manager']->executeCommand($info['dbName'], new Command($cmd));

        // Unacknowledged findAndModify returns null (no document value accessible).
        if ($unacknowledged) {
            return null;
        }

        $doc   = (array) iterator_to_array($cursor)[0];
        $value = $doc['value'] ?? null;

        return $value !== null ? $this->normalize($value) : null;
    }

    private function aggregate(array $info, array $args): array
    {
        $batchSizeArg = $args['batchSize'] ?? null;
        $cmd          = [
            'aggregate' => $info['collName'],
            'pipeline'  => $args['pipeline'],
            'cursor'    => (object) ($batchSizeArg !== null && $batchSizeArg > 0 ? ['batchSize' => $batchSizeArg] : []),
        ];
        foreach (['collation', 'hint', 'comment', 'let', 'allowDiskUse', 'maxTimeMS', 'rawData'] as $key) {
            if (! isset($args[$key])) {
                continue;
            }

            $cmd[$key] = $args[$key];
        }

        // For $merge/$out pipelines, execute but do not iterate the result cursor —
        // all documents were written to another collection and no getMore is needed.
        // Note: after fixDocument(), pipeline stages are stdClass objects, not arrays.
        $lastStage     = end($args['pipeline']);
        $lastStageKeys = is_array($lastStage) ? array_keys($lastStage) : array_keys((array) $lastStage);
        if (! empty($lastStageKeys) && in_array($lastStageKeys[0], ['$merge', '$out'], true)) {
            if ($info['readConcern'] !== null) {
                $info['manager']->executeReadCommand($info['dbName'], new Command($cmd), ['readConcern' => $info['readConcern']]);
            } else {
                $info['manager']->executeCommand($info['dbName'], new Command($cmd));
            }

            return [];
        }

        if ($info['readConcern'] !== null) {
            $cursor = $info['manager']->executeReadCommand($info['dbName'], new Command($cmd), ['readConcern' => $info['readConcern']]);
        } else {
            $cursor = $info['manager']->executeCommand($info['dbName'], new Command($cmd));
        }

        return array_map([$this, 'normalize'], iterator_to_array($cursor, false));
    }

    private function count(array $info, array $args): int
    {
        $cmd = ['count' => $info['collName']];
        if (! empty($args['filter'])) {
            $cmd['query'] = $args['filter'];
        }

        foreach (['collation', 'hint', 'limit', 'skip', 'maxTimeMS', 'comment', 'rawData'] as $key) {
            if (! isset($args[$key])) {
                continue;
            }

            $cmd[$key] = $args[$key];
        }

        $cursor = $info['manager']->executeCommand($info['dbName'], new Command($cmd));

        return (int) ((array) iterator_to_array($cursor)[0])['n'];
    }

    private function countDocuments(array $info, array $args): int
    {
        $pipeline = [['$match' => $args['filter'] ?? []]];
        if (isset($args['skip'])) {
            $pipeline[] = ['$skip' => (int) $args['skip']];
        }

        if (isset($args['limit'])) {
            $pipeline[] = ['$limit' => (int) $args['limit']];
        }

        $pipeline[] = ['$group' => ['_id' => 1, 'n' => ['$sum' => 1]]];
        $cmd = ['aggregate' => $info['collName'], 'pipeline' => $pipeline, 'cursor' => (object) []];
        foreach (['collation', 'hint', 'maxTimeMS', 'comment', 'rawData'] as $key) {
            if (! isset($args[$key])) {
                continue;
            }

            $cmd[$key] = $args[$key];
        }

        $cursor  = $info['manager']->executeCommand($info['dbName'], new Command($cmd));
        $results = iterator_to_array($cursor, false);

        return empty($results) ? 0 : (int) ((array) $results[0])['n'];
    }

    private function estimatedDocumentCount(array $info, array $args): int
    {
        $cmd = ['count' => $info['collName']];
        foreach (['maxTimeMS', 'comment', 'rawData'] as $key) {
            if (! isset($args[$key])) {
                continue;
            }

            $cmd[$key] = $args[$key];
        }

        $cursor = $info['manager']->executeCommand($info['dbName'], new Command($cmd));

        return (int) ((array) iterator_to_array($cursor)[0])['n'];
    }

    private function distinct(array $info, array $args): array
    {
        $cmd = ['distinct' => $info['collName'], 'key' => $args['fieldName']];
        if (! empty($args['filter'])) {
            $cmd['query'] = $args['filter'];
        }

        foreach (['collation', 'comment', 'hint', 'maxTimeMS', 'rawData'] as $key) {
            if (! isset($args[$key])) {
                continue;
            }

            $cmd[$key] = $args[$key];
        }

        $cursor = $info['manager']->executeCommand($info['dbName'], new Command($cmd));

        return (array) ((array) iterator_to_array($cursor)[0])['values'];
    }

    private function collectionBulkWrite(array $info, array $args): array
    {
        $bwOpts = ['ordered' => $args['ordered'] ?? true];
        foreach (['let', 'comment', 'bypassDocumentValidation', 'rawData'] as $k) {
            if (! isset($args[$k])) {
                continue;
            }

            $bwOpts[$k] = $args[$k];
        }

        $bw                    = new BulkWrite($bwOpts);
        $insertedIdsByPosition = [];

        foreach ($args['requests'] as $i => $request) {
            // Each request is a raw PHP assoc array (not yet fixDocument'd).
            $opType = array_key_first($request);
            // Fix the per-request arguments so {} becomes stdClass.
            $opArgs = (array) $this->fixDocument($request[$opType]);

            switch ($opType) {
                case 'insertOne':
                    $insertedIdsByPosition[$i] = $bw->insert($opArgs['document']);
                    break;

                case 'updateOne':
                    $opts = ['multi' => false];
                    foreach (['upsert', 'collation', 'hint', 'arrayFilters', 'let', 'sort'] as $k) {
                        if (! isset($opArgs[$k])) {
                            continue;
                        }

                        $opts[$k] = $opArgs[$k];
                    }

                    $this->validateUpdateDocument($opArgs['update']);
                    $bw->update($opArgs['filter'], $opArgs['update'], $opts);
                    break;

                case 'updateMany':
                    $opts = ['multi' => true];
                    foreach (['upsert', 'collation', 'hint', 'arrayFilters', 'let'] as $k) {
                        if (! isset($opArgs[$k])) {
                            continue;
                        }

                        $opts[$k] = $opArgs[$k];
                    }

                    $this->validateUpdateDocument($opArgs['update']);
                    $bw->update($opArgs['filter'], $opArgs['update'], $opts);
                    break;

                case 'replaceOne':
                    $opts = ['multi' => false];
                    foreach (['upsert', 'collation', 'hint', 'let', 'sort'] as $k) {
                        if (! isset($opArgs[$k])) {
                            continue;
                        }

                        $opts[$k] = $opArgs[$k];
                    }

                    $replacement = $opArgs['replacement'];
                    if ($replacement instanceof stdClass) {
                        $rKeys = array_keys((array) $replacement);
                        if (! empty($rKeys) && str_starts_with((string) $rKeys[0], '$')) {
                            throw new InvalidArgumentException('First key in $replacement is an update operator');
                        }
                    } elseif (is_array($replacement) && array_is_list($replacement) && ! empty($replacement)) {
                        throw new InvalidArgumentException('$replacement is an update pipeline');
                    }

                    $bw->update($opArgs['filter'], $replacement, $opts);
                    break;

                case 'deleteOne':
                    $opts = ['limit' => 1];
                    foreach (['collation', 'hint', 'let'] as $k) {
                        if (! isset($opArgs[$k])) {
                            continue;
                        }

                        $opts[$k] = $opArgs[$k];
                    }

                    $bw->delete($opArgs['filter'], $opts);
                    break;

                case 'deleteMany':
                    $opts = ['limit' => 0];
                    foreach (['collation', 'hint', 'let'] as $k) {
                        if (! isset($opArgs[$k])) {
                            continue;
                        }

                        $opts[$k] = $opArgs[$k];
                    }

                    $bw->delete($opArgs['filter'], $opts);
                    break;

                default:
                    throw new RuntimeException(sprintf('Unsupported bulkWrite request type: %s', $opType));
            }
        }

        $wcOpts = $info['writeConcern'] !== null ? ['writeConcern' => $info['writeConcern']] : [];
        $result = $info['manager']->executeBulkWrite($info['ns'], $bw, $wcOpts ?: null);

        if (! $result->isAcknowledged()) {
            return ['acknowledged' => false];
        }

        $insertedIds = [];
        foreach ($insertedIdsByPosition as $pos => $id) {
            $insertedIds[(string) $pos] = $id;
        }

        $upsertedIds = [];
        foreach ($result->getUpsertedIds() as $pos => $id) {
            $upsertedIds[(string) $pos] = $id;
        }

        return [
            'deletedCount'  => $result->getDeletedCount(),
            'insertedCount' => $result->getInsertedCount(),
            'insertedIds'   => $insertedIds,
            'matchedCount'  => $result->getMatchedCount(),
            'modifiedCount' => $result->getModifiedCount(),
            'upsertedCount' => $result->getUpsertedCount(),
            'upsertedIds'   => $upsertedIds,
        ];
    }

    // -------------------------------------------------------------------------
    // Database operations
    // -------------------------------------------------------------------------

    private function executeDatabaseOp(
        array $entities,
        array $entity,
        string $opName,
        array $args,
        TestCase $test,
    ): mixed {
        $info = $this->resolveDatabase($entities, $entity);

        return match ($opName) {
            'aggregate'        => $this->dbAggregate($info, $args),
            'dropCollection'   => $this->dropCollection($info, $args),
            'createCollection' => $this->createCollection($info, $args),
            default            => $test->markTestSkipped(sprintf('Unsupported database operation: %s', $opName)),
        };
    }

    private function dbAggregate(array $info, array $args): array
    {
        $cmd = [
            'aggregate' => 1,
            'pipeline'  => $args['pipeline'],
            'cursor'    => (object) (isset($args['batchSize']) ? ['batchSize' => $args['batchSize']] : []),
        ];
        foreach (['collation', 'hint', 'comment', 'let', 'allowDiskUse', 'maxTimeMS', 'rawData'] as $key) {
            if (! isset($args[$key])) {
                continue;
            }

            $cmd[$key] = $args[$key];
        }

        $cursor = $info['manager']->executeCommand($info['dbName'], new Command($cmd));

        return array_map([$this, 'normalize'], iterator_to_array($cursor, false));
    }

    private function dropCollection(array $info, array $args): void
    {
        try {
            $info['manager']->executeCommand($info['dbName'], new Command(['drop' => $args['collection']]));
        } catch (DriverRuntimeException) {
            // Ignore "ns not found"
        }
    }

    private function createCollection(array $info, array $args): void
    {
        $cmd = ['create' => $args['collection']];
        foreach (['viewOn', 'pipeline', 'validator', 'validationLevel', 'validationAction', 'expireAfterSeconds'] as $key) {
            if (! isset($args[$key])) {
                continue;
            }

            $cmd[$key] = $args[$key];
        }

        $info['manager']->executeCommand($info['dbName'], new Command($cmd));
    }

    // -------------------------------------------------------------------------
    // Initial data setup
    // -------------------------------------------------------------------------

    private function setupCollectionData(array $entities, array $collData): void
    {
        $manager = $this->getAnyManager($entities);
        $dbName  = $collData['databaseName'];
        $collName = $collData['collectionName'];

        try {
            $manager->executeCommand($dbName, new Command(['drop' => $collName]));
        } catch (DriverRuntimeException) {
            // Collection may not exist — that is fine
        }

        if (empty($collData['documents'])) {
            return;
        }

        $ns = $dbName . '.' . $collName;
        $bw = new BulkWrite(['ordered' => true]);
        foreach ($collData['documents'] as $doc) {
            $bw->insert($this->fixDocument($doc));
        }

        $manager->executeBulkWrite($ns, $bw);
    }

    // -------------------------------------------------------------------------
    // Assertions
    // -------------------------------------------------------------------------

    /**
     * Recursively match actual against expected, supporting unified spec operators.
     */
    private function assertMatch(TestCase $test, mixed $actual, mixed $expected, string $path): void
    {
        // $$unsetOrMatches — actual may be null/absent, or must match inner value
        if (is_array($expected) && array_key_exists('$$unsetOrMatches', $expected)) {
            if ($actual === null) {
                return;
            }

            $this->assertMatch($test, $actual, $expected['$$unsetOrMatches'], $path);

            return;
        }

        // $$type — BSON type check
        if (is_array($expected) && array_key_exists('$$type', $expected)) {
            $types      = (array) $expected['$$type'];
            $actualType = $this->phpToBsonType($actual);
            $test->assertContains(
                $actualType,
                $types,
                sprintf('Type mismatch at %s: expected one of [%s], got %s', $path, implode(', ', $types), $actualType),
            );

            return;
        }

        // null
        if ($expected === null) {
            $test->assertNull($actual, sprintf('Expected null at %s', $path));

            return;
        }

        // Array or object
        if (is_array($expected)) {
            if (array_is_list($expected)) {
                // Sequential array → exact element-count + recursive element match
                $actualList = is_array($actual) ? $actual : (array) $actual;
                $test->assertCount(count($expected), $actualList, sprintf('Array length mismatch at %s', $path));
                foreach ($expected as $i => $expectedEl) {
                    $this->assertMatch($test, $actualList[$i] ?? null, $expectedEl, sprintf('%s[%d]', $path, $i));
                }
            } else {
                // Associative object → subset match (actual may have extra keys)
                $actualAssoc = is_array($actual) ? $actual : (array) $actual;
                foreach ($expected as $key => $value) {
                    // $$exists handled inline
                    if (is_array($value) && array_key_exists('$$exists', $value)) {
                        $exists = array_key_exists($key, $actualAssoc);
                        if ($value['$$exists'] && ! $exists) {
                            $test->fail(sprintf('Expected key "%s" to exist at %s', $key, $path));
                        } elseif (! $value['$$exists'] && $exists) {
                            $test->fail(sprintf('Expected key "%s" NOT to exist at %s', $key, $path));
                        }

                        continue;
                    }

                    $this->assertMatch($test, $actualAssoc[$key] ?? null, $value, sprintf('%s.%s', $path, $key));
                }
            }

            return;
        }

        // Scalar — strict equality
        $test->assertSame($expected, $actual, sprintf('Value mismatch at %s', $path));
    }

    private function assertError(TestCase $test, Throwable $e, array $expectError): void
    {
        // isError: true — exception was thrown; already guaranteed by the caller.

        if (isset($expectError['errorCode'])) {
            $test->assertSame((int) $expectError['errorCode'], $e->getCode(), 'Error code mismatch');
        }

        if (! isset($expectError['expectResult']) || ! ($e instanceof BulkWriteException)) {
            return;
        }

        $wr          = $e->getWriteResult();
        $upsertedIds = [];
        foreach ($wr->getUpsertedIds() as $pos => $id) {
            $upsertedIds[(string) $pos] = $id;
        }

        $partial = [
            'deletedCount'  => $wr->getDeletedCount(),
            'insertedCount' => $wr->getInsertedCount(),
            'matchedCount'  => $wr->getMatchedCount(),
            'modifiedCount' => $wr->getModifiedCount(),
            'upsertedCount' => $wr->getUpsertedCount(),
            'upsertedIds'   => $upsertedIds,
        ];
        $this->assertMatch($test, $partial, $expectError['expectResult'], 'expectError.expectResult');
    }

    private function assertOutcome(array $entities, array $outcomeData, TestCase $test): void
    {
        $manager  = $this->getAnyManager($entities);
        $dbName   = $outcomeData['databaseName'];
        $collName = $outcomeData['collectionName'];
        $ns       = $dbName . '.' . $collName;

        $cursor     = $manager->executeQuery(
            $ns,
            new Query([], ['sort' => ['_id' => 1]]),
            ['readPreference' => new ReadPreference(ReadPreference::PRIMARY)],
        );
        $actualDocs = array_map([$this, 'normalize'], iterator_to_array($cursor, false));
        $expected   = $outcomeData['documents'];

        $test->assertCount(count($expected), $actualDocs, sprintf('Outcome doc count mismatch for %s.%s', $dbName, $collName));
        foreach ($expected as $i => $expectedDoc) {
            $this->assertMatch($test, $actualDocs[$i] ?? null, $expectedDoc, sprintf('outcome[%d]', $i));
        }
    }

    /** @param CommandStartedEvent[] $actualEvents */
    private function assertEvents(TestCase $test, array $actualEvents, array $expectedEvents): void
    {
        // Only verify event types we actually collect (commandStartedEvent).
        // Other event types (commandSucceededEvent, commandFailedEvent, SDAM) are ignored.
        $verifiable = array_values(array_filter($expectedEvents, static fn ($e) => isset($e['commandStartedEvent'])));
        $test->assertCount(count($verifiable), $actualEvents, 'APM event count mismatch');
        foreach ($verifiable as $i => $expected) {
            $actual = $actualEvents[$i] ?? null;
            $test->assertNotNull($actual, sprintf('Missing APM event at index %d', $i));

            $test->assertInstanceOf(CommandStartedEvent::class, $actual);
            $spec = $expected['commandStartedEvent'];

            if (isset($spec['commandName'])) {
                $test->assertSame($spec['commandName'], $actual->getCommandName(), sprintf('Event[%d] commandName', $i));
            }

            if (isset($spec['databaseName'])) {
                $test->assertSame($spec['databaseName'], $actual->getDatabaseName(), sprintf('Event[%d] databaseName', $i));
            }

            if (! isset($spec['command'])) {
                continue;
            }

            $actualCmd = $this->normalize($actual->getCommand());
            $this->assertMatch($test, $actualCmd, $spec['command'], sprintf('event[%d].command', $i));
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function checkRequirements(array $requirements, TestCase $test): bool
    {
        foreach ($requirements as $req) {
            if ($this->meetsRequirement($req)) {
                return true;
            }
        }

        $test->markTestSkipped('Server does not meet test requirements');

        return false;
    }

    private function meetsRequirement(array $req): bool
    {
        $version = $this->getServerVersion();

        if (isset($req['minServerVersion']) && version_compare($version, $req['minServerVersion'], '<')) {
            return false;
        }

        if (isset($req['maxServerVersion']) && version_compare($version, $req['maxServerVersion'], '>')) {
            return false;
        }

        // Topology: assume 'single' for standalone
        return ! isset($req['topologies']) || in_array('single', $req['topologies'], true);
    }

    private function getServerVersion(): string
    {
        if ($this->cachedServerVersion === null) {
            $manager  = new Manager($this->uri);
            $cursor   = $manager->executeCommand('admin', new Command(['buildInfo' => 1]));
            $info     = (array) iterator_to_array($cursor)[0];
            $this->cachedServerVersion = (array) $info;
        }

        return (string) $this->cachedServerVersion['version'];
    }

    private function buildUri(array $uriOptions): string
    {
        if (empty($uriOptions)) {
            return $this->uri;
        }

        $sep = str_contains($this->uri, '?') ? '&' : '/?';

        return $this->uri . $sep . http_build_query($uriOptions);
    }

    private function resolveCollection(array $entities, array $entity): array
    {
        $db      = $entities[$entity['databaseId']];
        $manager = $entities[$db['clientId']]['manager'];

        return [
            'manager'      => $manager,
            'dbName'       => $db['databaseName'],
            'collName'     => $entity['collectionName'],
            'ns'           => $db['databaseName'] . '.' . $entity['collectionName'],
            'readConcern'  => $entity['readConcern']  ?? null,
            'writeConcern' => $entity['writeConcern'] ?? null,
        ];
    }

    private function resolveDatabase(array $entities, array $entity): array
    {
        $manager = $entities[$entity['clientId']]['manager'];

        return [
            'manager' => $manager,
            'dbName'  => $entity['databaseName'],
        ];
    }

    private function getAnyManager(array $entities): Manager
    {
        foreach ($entities as $entity) {
            if ($entity['type'] === 'client') {
                return $entity['manager'];
            }
        }

        throw new RuntimeException('No client entity found');
    }

    /** Client-side validation: update document must use operators or be a pipeline. */
    private function validateUpdateDocument(mixed $update): void
    {
        // A list array is a valid pipeline (e.g. [['$set' => ...]])
        if (is_array($update) && array_is_list($update)) {
            return;
        }

        // After fixDocument(), non-list assoc arrays become stdClass objects.
        if ($update instanceof stdClass) {
            $keys = array_keys((array) $update);
            if (empty($keys)) {
                return;
            }

            if (! str_starts_with((string) $keys[0], '$')) {
                throw new InvalidArgumentException(
                    sprintf("Invalid key '%s': update only works with \$ operators and pipelines", $keys[0]),
                );
            }

            return;
        }

        if (! is_array($update) || empty($update)) {
            return;
        }

        $firstKey = (string) array_key_first($update);
        if (! str_starts_with($firstKey, '$')) {
            throw new InvalidArgumentException(
                sprintf("Invalid key '%s': update only works with \$ operators and pipelines", $firstKey),
            );
        }
    }

    private function buildUpdateResult(WriteResult $result): array
    {
        if (! $result->isAcknowledged()) {
            return ['acknowledged' => false];
        }

        $upsertedIds = $result->getUpsertedIds();

        return [
            'matchedCount'  => $result->getMatchedCount(),
            'modifiedCount' => $result->getModifiedCount(),
            'upsertedCount' => $result->getUpsertedCount(),
            'upsertedId'    => $upsertedIds[0] ?? null,
        ];
    }

    /**
     * Recursively convert stdClass / BSON objects to PHP associative arrays
     * for comparison with JSON-decoded fixture data.
     */
    private function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $arr = [];
            foreach ((array) $value as $k => $v) {
                $arr[$k] = $this->normalize($v);
            }

            return $arr;
        }

        if (is_array($value)) {
            return array_map([$this, 'normalize'], $value);
        }

        return $value;
    }

    /**
     * Convert PHP arrays decoded from JSON into a form suitable for BSON encoding.
     *
     * json_decode($json, true) turns every JSON object — including empty `{}` —
     * into a PHP array.  An *empty* PHP array `[]` is treated by the BSON encoder
     * as an array (BSON type 0x04), not as a document (BSON type 0x03), so
     * `{$match: {}}` would be sent as `{$match: []}` and MongoDB rejects it.
     *
     * Rules applied recursively:
     *  • Non-empty list array  → keep as PHP array (encodes to BSON array)
     *  • Assoc array or []     → convert to stdClass (encodes to BSON document)
     *  • Scalars / null / objects → pass through unchanged
     */
    private function fixDocument(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! empty($value) && array_is_list($value)) {
            return array_map([$this, 'fixDocument'], $value);
        }

        $obj = new stdClass();
        foreach ($value as $k => $v) {
            $obj->$k = $this->fixDocument($v);
        }

        return $obj;
    }

    private function phpToBsonType(mixed $value): string
    {
        return match (true) {
            is_int($value)                            => 'int',
            $value instanceof Int64                   => 'long',
            is_float($value)                          => 'double',
            is_string($value)                         => 'string',
            is_bool($value)                           => 'bool',
            $value === null                           => 'null',
            is_array($value) && array_is_list($value) => 'array',
            is_array($value)                          => 'object',
            $value instanceof stdClass                => 'object',
            $value instanceof ObjectId                => 'objectId',
            $value instanceof Binary                  => 'binData',
            $value instanceof UTCDateTime             => 'date',
            $value instanceof Decimal128              => 'decimal',
            $value instanceof Regex                   => 'regex',
            default                                   => 'unknown',
        };
    }
}
