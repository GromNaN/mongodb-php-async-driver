<?php

declare(strict_types=1);

namespace MongoDB\Internal;

use Fiber;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Throwable;

use function Amp\async;

/**
 * Bridges synchronous PHP call-sites with Amp/Revolt async operations.
 *
 * Usage
 * ─────
 *   $result = SyncRunner::run(function (): mixed {
 *       // fiber-aware code – may call ->await(), use async(), etc.
 *       return $connection->sendCommand('admin', ['ping' => 1]);
 *   });
 *
 * @internal
 */
final class SyncRunner
{
    /**
     * Reusable worker fiber for the synchronous entry-point.
     *
     * In synchronous mode only one SyncRunner::run() is active at a time
     * (nested calls from inside the event loop take the async() path), so a
     * single pooled fiber is enough.  The fiber loops forever: each iteration
     * it suspends waiting for a [$callable, $suspension] pair, executes it,
     * resolves the suspension, and suspends again ready for the next call.
     * This avoids allocating a new Fiber (and its C stack) on every run().
     */
    private static ?Fiber $fiber = null;

    /**
     * Execute $operation and return its result, bridging sync ↔ async.
     *
     * - If a Revolt event-loop is already running (i.e. we are inside a fiber),
     *   the callable is wrapped in `\Amp\async()` and awaited in place – the
     *   current fiber suspends while other fibers can make progress.
     *
     * - If no event-loop is running (the typical synchronous entry-point), the
     *   operation is dispatched to a reusable worker fiber; the main context
     *   suspends via a Revolt suspension until the fiber completes.
     *
     * @param callable(): T $operation
     *
     * @return T
     *
     * @throws Throwable Re-throws any exception thrown by $operation.
     *
     * @template T
     */
    public static function run(callable $operation): mixed
    {
        if (EventLoop::getDriver()->isRunning()) {
            // Already inside an async context – delegate to Amp futures so
            // the current fiber suspends cleanly without blocking the loop.
            return async($operation)->await();
        }

        // Synchronous entry-point: lazily create (or recreate after a fatal
        // termination) the single reusable worker fiber.
        if (self::$fiber === null || self::$fiber->isTerminated()) {
            self::$fiber = new Fiber(static function (): void {
                while (true) {
                    /** @var array{callable(): mixed, Suspension} $task */
                    $task        = Fiber::suspend();
                    [$op, $susp] = $task;

                    try {
                        $susp->resume($op());
                    } catch (Throwable $e) {
                        $susp->throw($e);
                    }
                }
            });

            // Advance the fiber to its first Fiber::suspend() so it is ready
            // to accept work.
            self::$fiber->start();
        }

        $suspension = EventLoop::getSuspension();

        // Queue a callback that hands [$operation, $suspension] to the worker
        // fiber.  We must go through EventLoop::queue() so the resume happens
        // inside a running event-loop tick, which lets async I/O within the
        // operation interact correctly with the loop.
        $fiber = self::$fiber;

        EventLoop::queue(static function () use ($operation, $suspension, $fiber): void {
            $fiber->resume([$operation, $suspension]);
            // The fiber may suspend internally (e.g. waiting for network I/O),
            // in which case the event loop resumes it when the I/O completes.
            // The suspension is resolved only after $operation() returns or throws.
        });

        return $suspension->suspend();
    }
}
