<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use MongoDB\Driver\ReadPreference;
use Throwable;

use function Amp\async;
use function Amp\Future\awaitAll;
use function array_map;
use function bin2hex;
use function count;
use function implode;
use function iterator_to_array;
use function random_bytes;
use function range;
use function sort;
use function sprintf;

class AsyncConcurrencyTest extends IntegrationTestCase
{
    private Manager $manager;
    private string $dbName;
    private string $ns;

    protected function setUp(): void
    {
        parent::setUp();

        $uri           = $_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017';
        $this->manager = new Manager($uri);
        $this->dbName  = 'phpunit_async_' . bin2hex(random_bytes(4));
        $this->ns      = $this->dbName . '.concurrent';

        $this->manager->executeCommand($this->dbName, new Command(['drop' => 'concurrent']));
    }

    protected function tearDown(): void
    {
        $this->manager->executeCommand($this->dbName, new Command(['dropDatabase' => 1]));
    }

    /**
     * 20 concurrent inserts must all land without error.
     */
    public function testConcurrentInserts(): void
    {
        $n = 20;

        [$errors] = async(function () use ($n): array {
            $futures = [];
            for ($i = 0; $i < $n; $i++) {
                $futures[$i] = async(function () use ($i): void {
                    $bw = new BulkWrite();
                    $bw->insert(['seq' => $i]);
                    $this->manager->executeBulkWrite($this->ns, $bw);
                });
            }

            return awaitAll($futures);
        })->await();

        $this->assertEmpty($errors, 'Some concurrent inserts failed: ' . $this->formatErrors($errors));

        $docs = iterator_to_array($this->manager->executeQuery($this->ns, new Query([])));
        $this->assertCount($n, $docs);
    }

    /**
     * 10 concurrent readers each querying a disjoint range must each return exactly 5 documents.
     */
    public function testConcurrentReads(): void
    {
        $bw = new BulkWrite();
        for ($i = 0; $i < 50; $i++) {
            $bw->insert(['n' => $i]);
        }

        $this->manager->executeBulkWrite($this->ns, $bw);

        [$errors, $counts] = async(function (): array {
            $futures = [];
            for ($w = 0; $w < 10; $w++) {
                $lo          = $w * 5;
                $hi          = $lo + 5;
                $futures[$w] = async(function () use ($lo, $hi): int {
                    $cursor = $this->manager->executeQuery(
                        $this->ns,
                        new Query(['n' => ['$gte' => $lo, '$lt' => $hi]]),
                        ['readPreference' => new ReadPreference(ReadPreference::PRIMARY)],
                    );

                    return count(iterator_to_array($cursor));
                });
            }

            return awaitAll($futures);
        })->await();

        $this->assertEmpty($errors, 'Some concurrent reads failed: ' . $this->formatErrors($errors));

        foreach ($counts as $w => $count) {
            $this->assertSame(5, $count, sprintf('Worker %d expected 5 docs, got %d', $w, $count));
        }
    }

    /**
     * Simultaneous writers and readers: readers must see all pre-inserted documents;
     * all writes must succeed; the final total must be exact.
     */
    public function testConcurrentWritesAndReads(): void
    {
        $initial = 10;
        $extra   = 10;

        $bw = new BulkWrite();
        for ($i = 0; $i < $initial; $i++) {
            $bw->insert(['n' => $i, 'kind' => 'initial']);
        }

        $this->manager->executeBulkWrite($this->ns, $bw);

        [$writeErrors, $readErrors, $readCounts] = async(function () use ($initial, $extra): array {
            $writeFutures = [];
            for ($i = $initial; $i < $initial + $extra; $i++) {
                $writeFutures[$i] = async(function () use ($i): void {
                    $bw = new BulkWrite();
                    $bw->insert(['n' => $i, 'kind' => 'concurrent']);
                    $this->manager->executeBulkWrite($this->ns, $bw);
                });
            }

            // Readers target the stable 'initial' set (PRIMARY, no dirty reads).
            $readFutures = [];
            for ($r = 0; $r < 5; $r++) {
                $readFutures[$r] = async(function (): int {
                    $cursor = $this->manager->executeQuery(
                        $this->ns,
                        new Query(['kind' => 'initial']),
                        ['readPreference' => new ReadPreference(ReadPreference::PRIMARY)],
                    );

                    return count(iterator_to_array($cursor));
                });
            }

            [$writeErrors]          = awaitAll($writeFutures);
            [$readErrors, $readCounts] = awaitAll($readFutures);

            return [$writeErrors, $readErrors, $readCounts];
        })->await();

        $this->assertEmpty($writeErrors, 'Some concurrent writes failed: ' . $this->formatErrors($writeErrors));
        $this->assertEmpty($readErrors, 'Some concurrent reads failed: ' . $this->formatErrors($readErrors));

        foreach ($readCounts as $r => $count) {
            $this->assertSame($initial, $count, sprintf('Reader %d expected %d initial docs, got %d', $r, $initial, $count));
        }

        $total = count(iterator_to_array($this->manager->executeQuery($this->ns, new Query([]))));
        $this->assertSame($initial + $extra, $total);
    }

    /**
     * 50 concurrent inserts: all succeed and every sequence number is present exactly once.
     */
    public function testHighConcurrency(): void
    {
        $n = 50;

        [$errors] = async(function () use ($n): array {
            $futures = [];
            for ($i = 0; $i < $n; $i++) {
                $futures[$i] = async(function () use ($i): void {
                    $bw = new BulkWrite();
                    $bw->insert(['i' => $i]);
                    $this->manager->executeBulkWrite($this->ns, $bw);
                });
            }

            return awaitAll($futures);
        })->await();

        $this->assertEmpty($errors, 'Some high-concurrency inserts failed: ' . $this->formatErrors($errors));

        $docs = iterator_to_array($this->manager->executeQuery($this->ns, new Query([])));
        $this->assertCount($n, $docs);

        $seqs = array_map(static fn ($d) => (int) ((array) $d)['i'], $docs);
        sort($seqs);
        $this->assertSame(range(0, $n - 1), $seqs, 'Not all sequence numbers are present');
    }

    /**
     * Concurrent $inc updates on a shared counter must be atomic: the final value
     * must equal the number of increments regardless of interleaving.
     */
    public function testConcurrentUpdates(): void
    {
        $increments = 20;

        $bw = new BulkWrite();
        $bw->insert(['counter' => 0, 'type' => 'shared']);
        $this->manager->executeBulkWrite($this->ns, $bw);

        [$errors] = async(function () use ($increments): array {
            $futures = [];
            for ($i = 0; $i < $increments; $i++) {
                $futures[$i] = async(function (): void {
                    $bw = new BulkWrite();
                    $bw->update(['type' => 'shared'], ['$inc' => ['counter' => 1]]);
                    $this->manager->executeBulkWrite($this->ns, $bw);
                });
            }

            return awaitAll($futures);
        })->await();

        $this->assertEmpty($errors, 'Some concurrent updates failed: ' . $this->formatErrors($errors));

        $cursor = $this->manager->executeQuery(
            $this->ns,
            new Query(['type' => 'shared']),
            ['readPreference' => new ReadPreference(ReadPreference::PRIMARY)],
        );
        $doc    = (array) iterator_to_array($cursor)[0];
        $this->assertSame($increments, (int) $doc['counter']);
    }

    /**
     * maxPoolSize=3 with 10 concurrent workers: all operations must complete
     * successfully even though only 3 connections can be open simultaneously.
     * Workers 4–10 wait in the pool queue until a connection is released.
     */
    public function testMaxPoolSizeEnforced(): void
    {
        $workers = 10;
        $maxPool = 3;
        $uri     = $_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017';

        // Separate Manager with a small pool — warm up topology before going async.
        $manager = new Manager($uri, ['maxPoolSize' => $maxPool]);
        $manager->executeCommand('admin', new Command(['ping' => 1]));

        [$errors] = async(function () use ($workers, $manager): array {
            $futures = [];
            for ($i = 0; $i < $workers; $i++) {
                $futures[$i] = async(function () use ($i, $manager): void {
                    $bw = new BulkWrite();
                    $bw->insert(['worker' => $i]);
                    $manager->executeBulkWrite($this->ns, $bw);
                });
            }

            return awaitAll($futures);
        })->await();

        $this->assertEmpty($errors, 'Some workers failed under maxPoolSize=3: ' . $this->formatErrors($errors));

        $docs = iterator_to_array($manager->executeQuery($this->ns, new Query([])));
        $this->assertCount($workers, $docs, 'All workers must have inserted a document');
    }

    /**
     * maxConnecting=1 serialises connection establishment: only one TCP handshake
     * at a time, all other concurrent callers wait.  All operations must still
     * complete and produce the correct result.
     */
    public function testMaxConnectingEnforced(): void
    {
        $workers     = 10;
        $uri         = $_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017';

        // maxConnecting=1 forces sequential handshakes; maxPoolSize is large enough
        // that pool capacity is never the bottleneck.  Warm up topology first.
        $manager = new Manager($uri, ['maxConnecting' => 1, 'maxPoolSize' => $workers]);
        $manager->executeCommand('admin', new Command(['ping' => 1]));

        [$errors] = async(function () use ($workers, $manager): array {
            $futures = [];
            for ($i = 0; $i < $workers; $i++) {
                $futures[$i] = async(function () use ($i, $manager): void {
                    $bw = new BulkWrite();
                    $bw->insert(['worker' => $i]);
                    $manager->executeBulkWrite($this->ns, $bw);
                });
            }

            return awaitAll($futures);
        })->await();

        $this->assertEmpty($errors, 'Some workers failed under maxConnecting=1: ' . $this->formatErrors($errors));

        $docs = iterator_to_array($manager->executeQuery($this->ns, new Query([])));
        $this->assertCount($workers, $docs, 'All workers must have inserted a document');
    }

    // -------------------------------------------------------------------------

    /** @param array<int|string, Throwable> $errors */
    private function formatErrors(array $errors): string
    {
        if ($errors === []) {
            return '';
        }

        $parts = [];
        foreach ($errors as $key => $e) {
            $parts[] = sprintf('[%s] %s: %s', $key, $e::class, $e->getMessage());
        }

        return implode('; ', $parts);
    }
}
