<?php

declare(strict_types=1);

namespace MongoDB\Tests\Spec;

use Generator;
use MongoDB\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function basename;
use function file_get_contents;
use function glob;
use function json_decode;
use function version_compare;

use const JSON_THROW_ON_ERROR;

/**
 * Runs CRUD spec fixtures from specifications/source/crud/tests/unified/.
 *
 * Each test case in each JSON file becomes a separate PHPUnit test.
 * Fixtures with schemaVersion > 1.1 are skipped (client-bulkWrite, etc.).
 */
class CrudSpecTest extends IntegrationTestCase
{
    private static UnifiedSpecRunner $runner;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $uri = $_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017';

        // Strip serverSelectionTimeoutMS added by IntegrationTestCase probe so
        // the runner uses a normal timeout for actual test operations.
        self::$runner = new UnifiedSpecRunner($uri);
    }

    #[DataProvider('provideCrudTests')]
    public function testCrud(string $file, int $testIndex): void
    {
        self::$runner->runTest($file, $testIndex, $this);
    }

    public static function provideCrudTests(): Generator
    {
        $dir = __DIR__ . '/../references/specifications/source/crud/tests/unified';

        foreach (glob($dir . '/*.json') as $file) {
            $fixture = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            // Only load fixtures this runner supports
            if (version_compare($fixture['schemaVersion'], '1.1', '>')) {
                continue;
            }

            $base = basename($file, '.json');
            foreach ($fixture['tests'] as $i => $testCase) {
                $name = $base . '/' . $testCase['description'];

                yield $name => [$file, $i];
            }
        }
    }
}
