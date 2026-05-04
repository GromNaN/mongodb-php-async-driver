<?php

declare(strict_types=1);

namespace MongoDB\Benchmark\Driver;

use MongoDB\Internal\SyncRunner;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * Measures the pure overhead of SyncRunner::run() with no I/O — isolates the
 * fiber dispatch cost (fiber create/reuse + event-loop queue + suspend/resume).
 *
 * Run with the userland driver (ext-mongodb must be disabled):
 *   cd benchmark && PHP_INI_SCAN_DIR="" ./vendor/bin/phpbench run src/Driver/SyncRunnerBench.php \
 *       --report=aggregate --tag=fiber_pool
 */
#[Iterations(5)]
#[Warmup(3)]
#[Groups(['sync-runner'])]
final class SyncRunnerBench
{
    /**
     * No-op callable — measures raw SyncRunner dispatch overhead.
     * Each rev: one EventLoop::queue(), one fiber resume/suspend cycle,
     * one suspension resolve, and one suspension->suspend() return.
     */
    #[Revs(2000)]
    public function benchNoOp(): void
    {
        SyncRunner::run(static fn () => null);
    }

    /**
     * Minimal return value — same path but with a scalar result to verify
     * that the result is correctly threaded through the suspension.
     */
    #[Revs(2000)]
    public function benchReturnScalar(): void
    {
        SyncRunner::run(static fn () => 42);
    }
}