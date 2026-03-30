<?php

declare(strict_types=1);

namespace MongoDB\Internal\Connection;

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
     * - If no event-loop is running (the typical synchronous entry-point),
     *   `EventLoop::run()` is used to drive the loop to completion and then
     *   the result (or exception) is returned / re-thrown.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     *
     * @throws Throwable Re-throws any exception thrown by $operation.
     */
    public static function run(callable $operation): mixed
    {
        if (EventLoop::getDriver()->isRunning()) {
            // Already inside an async context – delegate to Amp futures so
            // the current fiber suspends cleanly without blocking the loop.
            return async($operation)->await();
        }

        // Synchronous entry-point: spin up the event loop for this one call.
        $result    = null;
        $exception = null;

        EventLoop::run(function () use ($operation, &$result, &$exception): void {
            try {
                $result = async($operation)->await();
            } catch (Throwable $e) {
                $exception = $e;
            }
        });

        if ($exception !== null) {
            throw $exception;
        }

        return $result;
    }
}
