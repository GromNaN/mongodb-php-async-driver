<?php

declare(strict_types=1);

namespace MongoDB\Internal\Connection;

use Fiber;
use Revolt\EventLoop;
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
     * Execute $operation and return its result, bridging sync ↔ async.
     *
     * - If a Revolt event-loop is already running (i.e. we are inside a fiber),
     *   the callable is wrapped in `\Amp\async()` and awaited in place – the
     *   current fiber suspends while other fibers can make progress.
     *
     * - If no event-loop is running (the typical synchronous entry-point), a
     *   Revolt suspension is used: the operation is queued as a fiber, and
     *   `suspension->suspend()` drives the event loop until the fiber completes.
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

        // Synchronous entry-point.
        // 1. Obtain a suspension for the current (main) context.
        // 2. Queue the operation as a fiber; when done it resumes / throws the suspension.
        // 3. suspension->suspend() drives the event loop until resumed.
        $suspension = EventLoop::getSuspension();

        EventLoop::queue(static function () use ($operation, $suspension): void {
            // This runs as a regular callback (main context) inside the event loop.
            // We start our own fiber here so that async()->await() inside
            // $operation works correctly.
            $fiber = new Fiber(static function () use ($operation, $suspension): void {
                try {
                    $suspension->resume($operation());
                } catch (Throwable $e) {
                    $suspension->throw($e);
                }
            });

            $fiber->start();
            // The fiber may suspend internally (e.g. via delay()), in which case
            // the event loop will resume it when the timer fires.  The suspension
            // will be resolved only after $operation() returns or throws.
        });

        return $suspension->suspend();
    }
}
