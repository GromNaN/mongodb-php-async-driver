<?php

declare(strict_types=1);

namespace MongoDB\Benchmark\Driver;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * End-to-end driver benchmarks: network + BSON encode/decode.
 *
 * Run with ext-mongodb (extension must be active):
 *   cd benchmark && ./vendor/bin/phpbench run src/Driver \
 *       --report=aggregate --tag=ext_mongodb
 *
 * Run with the userland driver (disable ext-mongodb via PHP_INI_SCAN_DIR):
 *   cd benchmark && PHP_INI_SCAN_DIR="" ./vendor/bin/phpbench run src/Driver \
 *       --report=aggregate --tag=userland
 *
 * Compare both:
 *   cd benchmark && ./vendor/bin/phpbench report --report=benchmark_compare \
 *       --ref=ext_mongodb --ref=userland
 */
#[Iterations(5)]
#[Warmup(3)]
final class DriverBench
{
    private static ?Manager $manager = null;

    private const DB   = 'benchmark_driver';
    private const COLL = 'bench';
    private const NS   = self::DB . '.' . self::COLL;

    // ── Static helpers ─────────────────────────────────────────────────────

    private static function manager(): Manager
    {
        if (self::$manager === null) {
            self::$manager = new Manager(
                (string) (getenv('MONGODB_URI') ?: 'mongodb://127.0.0.1:27017'),
            );
            // Establish the connection before measurements start.
            self::$manager->executeCommand('admin', new Command(['ping' => 1]))->toArray();
        }

        return self::$manager;
    }

    private static function drop(): void
    {
        self::manager()->executeCommand(self::DB, new Command(['drop' => self::COLL]))->toArray();
    }

    private static function smallDoc(): array
    {
        return [
            'name'      => 'Alice',
            'age'       => 30,
            'email'     => 'alice@example.com',
            'score'     => 98.6,
            'active'    => true,
            'createdAt' => new UTCDateTime(),
        ];
    }

    private static function largeDoc(): array
    {
        return [
            'name'      => 'Bob',
            'age'       => 42,
            'email'     => 'bob@example.com',
            'score'     => 77.3,
            'active'    => false,
            'createdAt' => new UTCDateTime(),
            'address'   => ['street' => '42 Elm St', 'city' => 'Metropolis', 'state' => 'NY', 'zip' => '10001'],
            'tags'      => ['php', 'mongodb', 'async', 'revolt', 'driver', 'benchmark'],
            'meta'      => [
                'field_0' => 'aaaaaaaaaaaaaaaa', 'field_1' => 'bbbbbbbbbbbbbbbb',
                'field_2' => 'cccccccccccccccc', 'field_3' => 'dddddddddddddddd',
                'field_4' => 'eeeeeeeeeeeeeeee', 'field_5' => 'ffffffffffffffff',
                'field_6' => 'gggggggggggggggg', 'field_7' => 'hhhhhhhhhhhhhhhh',
                'field_8' => 'iiiiiiiiiiiiiiii', 'field_9' => 'jjjjjjjjjjjjjjjj',
            ],
        ];
    }

    // ── Before-method helpers ──────────────────────────────────────────────

    /** Called before ping and insert benchmarks: just warm up the connection. */
    public function init(): void
    {
        self::manager();
    }

    /** Seed the collection with 100 small documents for find benchmarks. */
    public function seed100Small(): void
    {
        self::drop();
        $bulk = new BulkWrite();
        for ($i = 0; $i < 100; $i++) {
            $bulk->insert(self::smallDoc());
        }

        self::manager()->executeBulkWrite(self::NS, $bulk);
    }

    /** Seed the collection with 1 000 small documents for cursor benchmarks. */
    public function seed1000Small(): void
    {
        self::drop();
        $bulk = new BulkWrite();
        for ($i = 0; $i < 1000; $i++) {
            $bulk->insert(self::smallDoc());
        }

        self::manager()->executeBulkWrite(self::NS, $bulk);
    }

    /** Seed the collection with 100 large documents for find benchmarks. */
    public function seed100Large(): void
    {
        self::drop();
        $bulk = new BulkWrite();
        for ($i = 0; $i < 100; $i++) {
            $bulk->insert(self::largeDoc());
        }

        self::manager()->executeBulkWrite(self::NS, $bulk);
    }

    // ── Benchmarks ─────────────────────────────────────────────────────────

    /**
     * Baseline: pure network round-trip, minimal BSON payload.
     */
    #[Revs(30)]
    #[BeforeMethods('init')]
    #[Groups(['network'])]
    public function benchPing(): void
    {
        self::manager()->executeCommand('admin', new Command(['ping' => 1]))->toArray();
    }

    /**
     * Single insert — small document (~150 B), acknowledged write.
     */
    #[Revs(20)]
    #[BeforeMethods('init')]
    #[Groups(['write'])]
    public function benchInsertOneSmall(): void
    {
        $bulk = new BulkWrite();
        $bulk->insert(self::smallDoc());
        self::manager()->executeBulkWrite(self::NS, $bulk);
    }

    /**
     * Single insert — large document (~700 B, nested arrays and subdocuments).
     */
    #[Revs(20)]
    #[BeforeMethods('init')]
    #[Groups(['write'])]
    public function benchInsertOneLarge(): void
    {
        $bulk = new BulkWrite();
        $bulk->insert(self::largeDoc());
        self::manager()->executeBulkWrite(self::NS, $bulk);
    }

    /**
     * Bulk write of 100 small documents — one OP_MSG, server batches internally.
     */
    #[Revs(15)]
    #[BeforeMethods('init')]
    #[Groups(['write', 'bulk'])]
    public function benchBulkInsert100Small(): void
    {
        $bulk = new BulkWrite();
        for ($i = 0; $i < 100; $i++) {
            $bulk->insert(self::smallDoc());
        }

        self::manager()->executeBulkWrite(self::NS, $bulk);
    }

    /**
     * Bulk write of 1 000 small documents.
     */
    #[Revs(10)]
    #[BeforeMethods('init')]
    #[Groups(['write', 'bulk'])]
    public function benchBulkInsert1000Small(): void
    {
        $bulk = new BulkWrite();
        for ($i = 0; $i < 1000; $i++) {
            $bulk->insert(self::smallDoc());
        }

        self::manager()->executeBulkWrite(self::NS, $bulk);
    }

    /**
     * Find with limit:1 — one round-trip, decode one document.
     */
    #[Revs(20)]
    #[BeforeMethods('seed100Small')]
    #[Groups(['read'])]
    public function benchFindOne(): void
    {
        self::manager()->executeQuery(self::NS, new Query([], ['limit' => 1]))->toArray();
    }

    /**
     * Fetch and iterate 100 small documents — fits in first batch (no getMore).
     */
    #[Revs(20)]
    #[BeforeMethods('seed100Small')]
    #[Groups(['read', 'cursor'])]
    public function benchFindIterate100Small(): void
    {
        self::manager()->executeQuery(self::NS, new Query([]))->toArray();
    }

    /**
     * Fetch and iterate 1 000 small documents — requires multiple getMore round-trips.
     */
    #[Revs(10)]
    #[BeforeMethods('seed1000Small')]
    #[Groups(['read', 'cursor'])]
    public function benchFindIterate1000Small(): void
    {
        self::manager()->executeQuery(self::NS, new Query([]))->toArray();
    }

    /**
     * Fetch and iterate 100 large documents — heavier BSON decode.
     */
    #[Revs(10)]
    #[BeforeMethods('seed100Large')]
    #[Groups(['read', 'cursor'])]
    public function benchFindIterate100Large(): void
    {
        self::manager()->executeQuery(self::NS, new Query([]))->toArray();
    }
}