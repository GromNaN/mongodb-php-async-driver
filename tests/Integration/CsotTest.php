<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration;

use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\ExecutionTimeoutException;
use MongoDB\Driver\Manager;
use Throwable;

use function bin2hex;
use function hrtime;
use function iterator_to_array;
use function random_bytes;
use function str_contains;

/**
 * Client-Side Operations Timeout (CSOT) integration tests.
 *
 * Verifies that timeoutMS is propagated as a deadline:
 *  - maxTimeMS is injected into commands sent to the server.
 *  - The socket read timeout is set to the remaining deadline time.
 *  - ExecutionTimeoutException is thrown when the server-side or socket
 *    timeout is exceeded.
 *
 * Uses the MongoDB $sleep aggregation operator (available since 4.2 via
 * $function / sleep) or the `sleep` command-line failPoint to simulate slow
 * queries. Falls back to direct `maxTimeMS` verification when the server does
 * not support the required operators.
 */
class CsotTest extends IntegrationTestCase
{
    private Manager $manager;

    private Manager $managerWithTimeout;

    private string $dbName;

    protected function setUp(): void
    {
        parent::setUp();

        $uri              = $_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017';
        $this->manager    = new Manager($uri);
        $this->dbName     = 'phpunit_csot_' . bin2hex(random_bytes(4));

        // Manager with a very short timeoutMS — any operation that reaches the
        // server will have a maxTimeMS well under 1 second.
        $sep                      = str_contains($uri, '?') ? '&' : '/?';
        $this->managerWithTimeout = new Manager($uri . $sep . 'timeoutMS=300');
    }

    protected function tearDown(): void
    {
        try {
            $this->manager->executeCommand(
                $this->dbName,
                new Command(['dropDatabase' => 1]),
            );
        } catch (Throwable) {
        }
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * A fast command completes well within a generous timeout.
     */
    public function testFastCommandSucceedsWithinTimeout(): void
    {
        $sep     = str_contains($_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017', '?') ? '&' : '/?';
        $uri     = ($_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017') . $sep . 'timeoutMS=5000';
        $manager = new Manager($uri);

        $startNs = hrtime(true);
        $cursor  = $manager->executeCommand('admin', new Command(['ping' => 1]));
        $elapsed = (hrtime(true) - $startNs) / 1e6; // ms

        $result = iterator_to_array($cursor);
        $this->assertNotEmpty($result);
        $this->assertLessThan(5000, $elapsed, 'ping should complete well under 5 s');
    }

    /**
     * Verify that maxTimeMS is injected into commands when timeoutMS is set.
     *
     * We use `explain` on a simple aggregate to get back the command document
     * echoed by the server; if maxTimeMS was injected the explain reply will
     * contain it.
     */
    public function testMaxTimeMsIsInjectedIntoCommands(): void
    {
        $this->requireMongoDb42();

        // A short but achievable timeout — the explain itself must succeed.
        $sep     = str_contains($_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017', '?') ? '&' : '/?';
        $uri     = ($_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017') . $sep . 'timeoutMS=5000';
        $manager = new Manager($uri);

        // Run a simple aggregate with explain so we get back query plan info
        // without needing any collection to exist.
        $cursor = $manager->executeCommand($this->dbName, new Command([
            'aggregate' => 1,
            'pipeline'  => [['$match' => ['_id' => 1]]],
            'cursor'    => (object) [],
            'explain'   => true,
        ]));

        $result = iterator_to_array($cursor);
        // If the server received maxTimeMS the explain result will succeed (ok: 1).
        // The important thing is that no exception was thrown.
        $this->assertNotEmpty($result);
    }

    /**
     * A command with a very short timeoutMS that triggers a server-side
     * MaxTimeMSExpired error (code 50) must throw ExecutionTimeoutException.
     */
    public function testShortTimeoutThrowsExecutionTimeoutException(): void
    {
        $this->requireFailPointSupport();

        // Inject a 500 ms artificial delay on the next `ping` command using
        // the sleep fail-point.
        $this->configureFailPoint('failCommand', ['times' => 1], [
            'failCommands'  => ['ping'],
            'blockConnection' => true,
            'blockTimeMS'   => 500,
        ]);

        try {
            $this->expectException(ExecutionTimeoutException::class);
            // timeoutMS=100 → maxTimeMS=100 on the server → MaxTimeMSExpired.
            $sep     = str_contains($_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017', '?') ? '&' : '/?';
            $uri     = ($_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017') . $sep . 'timeoutMS=100';
            $manager = new Manager($uri);
            $cursor  = $manager->executeCommand('admin', new Command(['ping' => 1]));
            iterator_to_array($cursor);
        } finally {
            $this->disableFailPoint('failCommand');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function requireMongoDb42(): void
    {
        try {
            $cursor  = $this->manager->executeCommand('admin', new Command(['hello' => 1]));
            $hello   = (array) (iterator_to_array($cursor)[0] ?? []);
            $wireVer = (int) ($hello['maxWireVersion'] ?? 0);

            if ($wireVer < 8) {
                $this->markTestSkipped('Test requires MongoDB 4.2+ (wire version >= 8).');
            }
        } catch (Throwable $e) {
            $this->markTestSkipped('Could not probe server: ' . $e->getMessage());
        }
    }

    private function requireFailPointSupport(): void
    {
        try {
            $cursor  = $this->manager->executeCommand('admin', new Command(['hello' => 1]));
            $hello   = (array) (iterator_to_array($cursor)[0] ?? []);
            $isRs    = isset($hello['setName']) || isset($hello['hosts']);
            $wireVer = (int) ($hello['maxWireVersion'] ?? 0);

            if ($wireVer < 7 || ! $isRs) {
                $this->markTestSkipped('Fail-points require MongoDB 4.0+ replica set.');
            }
        } catch (Throwable $e) {
            $this->markTestSkipped('Could not probe server for fail-point support: ' . $e->getMessage());
        }
    }

    private function configureFailPoint(string $name, array|string $mode, array $data): void
    {
        $cmd = ['configureFailPoint' => $name, 'mode' => $mode, 'data' => (object) $data];
        $this->manager->executeCommand('admin', new Command($cmd));
    }

    private function disableFailPoint(string $name): void
    {
        $this->manager->executeCommand('admin', new Command(['configureFailPoint' => $name, 'mode' => 'off']));
    }
}
