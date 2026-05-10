<?php

declare(strict_types=1);

namespace MongoDB\Tests\Internal;

use Fiber;
use MongoDB\Internal\SyncRunner;
use PHPUnit\Framework\TestCase;

class SyncRunnerTest extends TestCase
{
    /**
     * Regression test: SyncRunner::run() called from inside a fiber (e.g. a
     * destructor triggered mid-operation) must use the async path, not the
     * synchronous worker-fiber path.
     *
     * Without the fix, the synchronous path re-uses the cached Revolt
     * Suspension for the worker fiber.  If that suspension was already pending
     * (because the outer operation was in the middle of an await()), calling
     * suspend() a second time throws:
     *   "Must call resume() or throw() before calling suspend() again"
     */
    public function testNestedCallFromInsideFiberUsesAsyncPath(): void
    {
        // Outer call from {main} – drives the event loop normally.
        $outerResult = SyncRunner::run(static function (): string {
            // Simulate a nested SyncRunner::run() from inside a fiber, as
            // would happen if a PHP destructor fires mid-operation while the
            // worker fiber is running.
            $innerFiber = new Fiber(static function (): void {
                // We are now inside a fiber. This mimics a destructor call
                // that happens while the reusable worker fiber executes.
                $innerResult = SyncRunner::run(static fn (): string => 'inner');

                // The inner call must return the correct value, not a
                // suspension-task array or garbage from incorrect re-entry.
                TestCase::assertSame('inner', $innerResult);
            });

            $innerFiber->start();

            return 'outer';
        });

        $this->assertSame('outer', $outerResult);
    }

    /**
     * Regression test: two sequential SyncRunner::run() calls from {main}
     * must both complete correctly regardless of the reusable fiber caching
     * the Suspension object between calls.
     */
    public function testSequentialCallsFromMainWork(): void
    {
        $first  = SyncRunner::run(static fn () => 'first');
        $second = SyncRunner::run(static fn () => 'second');

        $this->assertSame('first', $first);
        $this->assertSame('second', $second);
    }

    /**
     * Calling SyncRunner::run() from directly inside the worker fiber (the
     * body of an outer SyncRunner::run() operation) must work.  This is the
     * exact pattern triggered when a PHP destructor fires mid-operation inside
     * the worker fiber, e.g. the GridFS WritableStream destructor calling
     * Manager::selectServer() → SyncRunner::run().
     */
    public function testNestedCallDirectlyFromWorkerFiber(): void
    {
        $innerResult = null;

        // Outer call takes the sync (worker-fiber) path.
        SyncRunner::run(static function () use (&$innerResult): void {
            // We are now executing inside the reusable worker fiber.
            // Fiber::getCurrent() !== null, so the inner call must take the
            // async path – not attempt to re-enter the worker-fiber path.
            $innerResult = SyncRunner::run(static fn (): string => 'nested');
        });

        $this->assertSame('nested', $innerResult);
    }
}
