<?php

declare(strict_types=1);

namespace MongoDB\Tests\Phpt;

use Generator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function dirname;
use function escapeshellarg;
use function fclose;
use function ksort;
use function preg_match;
use function proc_close;
use function proc_open;
use function sprintf;
use function str_contains;
use function stream_get_contents;
use function strlen;
use function substr;
use function trim;

use const PHP_BINARY;

/**
 * Runs the official ext-mongodb .phpt test suite against the userland driver.
 *
 * Each .phpt file becomes a single PHPUnit test case. Execution is delegated
 * to PHP's own run-tests.php with an auto_prepend_file that boots the
 * Composer autoloader, so tests run in a clean subprocess just as they would
 * against the real C extension.
 *
 * Tests that cannot be made compliant in pure PHP are listed in skip_list.php.
 */
class PhptRunnerTest extends TestCase
{
    private static string $prependFile;
    private static string $phptRoot;
    private static string $runTests;
    /** @var array<string, string> */
    private static array $skipList;

    public static function setUpBeforeClass(): void
    {
        self::$prependFile = __DIR__ . '/prepend.php';
        self::$phptRoot    = dirname(__DIR__) . '/references/mongo-php-driver/tests';
        self::$runTests    = dirname(__DIR__) . '/references/mongo-php-driver/run-tests.php';
        self::$skipList    = require __DIR__ . '/skip_list.php';
    }

    /** @return Generator<string, array{string}> */
    public static function phptFiles(): Generator
    {
        $root     = dirname(__DIR__) . '/references/mongo-php-driver/tests';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $files    = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'phpt') {
                continue;
            }

            $relative          = substr($file->getPathname(), strlen($root) + 1);
            $files[$relative]  = $file->getPathname();
        }

        ksort($files);

        foreach ($files as $relative => $absolute) {
            yield $relative => [$absolute];
        }
    }

    /** @dataProvider phptFiles */
    public function testPhpt(string $filePath): void
    {
        $relative = substr($filePath, strlen(self::$phptRoot) + 1);

        // Permanently impossible tests (operator overloading, arithmetic hooks)
        if (isset(self::$skipList[$relative])) {
            $this->markTestSkipped(self::$skipList[$relative]);
        }

        $cmd = sprintf(
            'TEST_PHP_EXECUTABLE=%s php %s -P -q -g SKIP,FAIL,BORK -d %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::$runTests),
            escapeshellarg('auto_prepend_file=' . self::$prependFile),
            escapeshellarg($filePath),
        );

        $process  = proc_open($cmd, [1 => ['pipe', 'w']], $pipes);
        $output   = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        if (str_contains($output, 'SKIP ')) {
            $reason = preg_match('/^SKIP\s+.+?\s+reason:\s*(.+)$/m', $output, $m)
                ? trim($m[1])
                : trim($output);
            $this->markTestSkipped($reason);
        }

        $this->assertSame(0, $exitCode, $output);
    }
}