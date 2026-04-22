<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration;

use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use PHPUnit\Framework\TestCase;
use Throwable;

use function str_contains;

/**
 * Base class for integration tests that require a running MongoDB server.
 *
 * Checks availability once per test class in setUpBeforeClass() using a short
 * serverSelectionTimeoutMS so the suite does not block indefinitely when no
 * server is running. All tests in the class are skipped when unreachable.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static bool $mongodbAvailable;

    /** URI with a short probe timeout — used only for the availability check. */
    private const PROBE_TIMEOUT_MS = 2000;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $uri = $_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017';

        // Append a short serverSelectionTimeoutMS for the probe, without
        // overriding a value already present in the URI.
        if (! str_contains($uri, 'serverSelectionTimeoutMS')) {
            $separator = str_contains($uri, '?') ? '&' : '/?';
            $uri      .= $separator . 'serverSelectionTimeoutMS=' . self::PROBE_TIMEOUT_MS;
        }

        try {
            $manager = new Manager($uri);
            $manager->executeCommand('admin', new Command(['ping' => 1]));
            self::$mongodbAvailable = true;
        } catch (Throwable) {
            self::$mongodbAvailable = false;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$mongodbAvailable) {
            return;
        }

        $this->markTestSkipped('MongoDB is not available at ' . ($_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017'));
    }
}
