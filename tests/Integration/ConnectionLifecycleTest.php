<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration;

use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;

use function count;
use function is_dir;
use function scandir;
use function sprintf;

class ConnectionLifecycleTest extends IntegrationTestCase
{
    /**
     * Verifies that Manager::__destruct() explicitly closes all application
     * connection pools, preventing file-descriptor accumulation.
     *
     * Creates short-lived Manager instances, each executing one command, then
     * immediately destroyed.  If executor->close() is not called on __destruct(),
     * idle application sockets linger until PHP's GC eventually runs
     * ConnectionPool::__destruct(), which may never happen promptly.  Over
     * hundreds of tests this accumulation exhausts the stream_select()
     * FD_SETSIZE=1024 limit, corrupting Revolt's suspension state and causing
     * every subsequent SyncRunner::run() call to throw
     * "Must call resume() or throw() before calling suspend() again".
     *
     * Strategy: run a warmup batch first to let PHP's autoloader and Revolt
     * reach steady state, then measure FD growth over a second batch.  If
     * Manager properly closes its pools, the second batch adds 0 FDs.
     */
    public function testManagerDestructClosesConnectionPools(): void
    {
        $fdDir = null;

        foreach (['/proc/self/fd', '/dev/fd'] as $dir) {
            if (is_dir($dir)) {
                $fdDir = $dir;
                break;
            }
        }

        if ($fdDir === null) {
            $this->markTestSkipped('Cannot count open file descriptors on this platform');
        }

        $countFds = static fn (): int => count(scandir($fdDir)) - 2; // subtract . and ..
        $uri      = $_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017';

        // Warmup: load all classes and let autoloader + Revolt reach steady state.
        for ($i = 0; $i < 5; $i++) {
            $manager = new Manager($uri);
            $manager->executeCommand('admin', new Command(['ping' => 1]));
            unset($manager);
        }

        $fdsAtSteadyState = $countFds();

        // Measurement: 20 more Manager lifecycles must not add FDs.
        for ($i = 0; $i < 20; $i++) {
            $manager = new Manager($uri);
            $manager->executeCommand('admin', new Command(['ping' => 1]));
            unset($manager); // triggers Manager::__destruct() → executor->close()
        }

        $fdsAfter = $countFds();
        $fdGrowth = $fdsAfter - $fdsAtSteadyState;

        $this->assertLessThanOrEqual(
            5,
            $fdGrowth,
            sprintf(
                'FD count grew by %d after 20 Manager lifecycles: Manager::__destruct() is not closing connection pools',
                $fdGrowth,
            ),
        );
    }
}
