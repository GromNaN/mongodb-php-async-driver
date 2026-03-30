<?php

declare(strict_types=1);

namespace MongoDB\Tests\Phpt;

use Generator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function dirname;
use function escapeshellarg;
use function explode;
use function fclose;
use function file_get_contents;
use function fwrite;
use function preg_match;
use function preg_quote;
use function preg_split;
use function proc_close;
use function proc_open;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function trim;
use function var_export;

use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * Runs the official ext-mongodb .phpt test suite against the userland driver.
 *
 * Each .phpt file becomes a single PHPUnit test case.  The FILE section is
 * executed in a subprocess with PHP_INI_SCAN_DIR="" (so ext-mongodb is not
 * loaded) and with a prepend file that boots the Composer autoloader.
 *
 * Tests that cannot be made compliant in pure PHP are listed in skip_list.php.
 */
class PhptRunnerTest extends TestCase
{
    private static string $prependFile;
    private static string $phptRoot;
    /** @var array<string, string> */
    private static array $skipList;

    public static function setUpBeforeClass(): void
    {
        self::$prependFile = __DIR__ . '/prepend.php';
        self::$phptRoot    = dirname(__DIR__) . '/references/mongo-php-driver/tests';
        self::$skipList    = require __DIR__ . '/skip_list.php';
    }

    /** @return Generator<string, array{string}> */
    public static function phptFiles(): Generator
    {
        $root     = dirname(__DIR__) . '/references/mongo-php-driver/tests';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'phpt') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);

            yield $relative => [$file->getPathname()];
        }
    }

    /** @dataProvider phptFiles */
    public function testPhpt(string $filePath): void
    {
        $relative = substr($filePath, strlen(self::$phptRoot) + 1);

        // --SKIP LIST-- (permanently impossible tests) ----------------------
        if (isset(self::$skipList[$relative])) {
            $this->markTestSkipped(self::$skipList[$relative]);
        }

        $sections = $this->parseSections($filePath);
        $dir      = dirname($filePath);

        // --SKIPIF-- ---------------------------------------------------------
        if (isset($sections['SKIPIF'])) {
            $skipOutput = $this->runPhpCode($sections['SKIPIF'], $dir);
            if (str_contains(strtolower($skipOutput), 'skip')) {
                $this->markTestSkipped(trim($skipOutput));
            }
        }

        // Extra INI settings
        $extraIni = [];
        if (isset($sections['INI'])) {
            foreach (explode("\n", trim($sections['INI'])) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $extraIni[] = $line;
            }
        }

        // --FILE-- -----------------------------------------------------------
        $actual = $this->runPhpCode($sections['FILE'], $dir, $extraIni);

        // --EXPECT*-- --------------------------------------------------------
        if (isset($sections['EXPECT'])) {
            $this->assertSame(
                $this->normalise($sections['EXPECT']),
                $this->normalise($actual),
            );
        } elseif (isset($sections['EXPECTF'])) {
            $pattern = $this->expectfToRegex($sections['EXPECTF']);
            $this->assertMatchesRegularExpression($pattern, $this->normalise($actual));
        } elseif (isset($sections['EXPECTREGEX'])) {
            $this->assertMatchesRegularExpression(
                '/' . trim($sections['EXPECTREGEX']) . '/s',
                $this->normalise($actual),
            );
        } else {
            $this->addWarning('No EXPECT* section found in ' . $filePath);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Parse a .phpt file into named sections.
     *
     * @return array<string, string>
     */
    private function parseSections(string $file): array
    {
        $sections = [];
        $current  = null;

        foreach (explode("\n", file_get_contents($file)) as $line) {
            if (preg_match('/^--([A-Z_]+)--\s*$/', $line, $m)) {
                $current            = $m[1];
                $sections[$current] = '';
            } elseif ($current !== null) {
                $sections[$current] .= $line . "\n";
            }
        }

        return $sections;
    }

    /**
     * Execute a block of PHP code in a subprocess and return combined output.
     *
     * Code is passed via stdin (no temp file).  __DIR__ occurrences are
     * replaced with the literal original directory so that relative
     * require_once calls inside the test code still resolve correctly.
     *
     * @param list<string> $extraIni Additional ini=value settings.
     */
    private function runPhpCode(string $code, string $dir, array $extraIni = []): string
    {
        // Replace the __DIR__ magic constant with the real directory so that
        // require_once __DIR__ . '/../utils/basic.inc' works when code is fed
        // through stdin (where __DIR__ would otherwise be empty).
        $code = str_replace('__DIR__', var_export($dir, true), $code);

        $iniArgs = ' -d ' . escapeshellarg('auto_prepend_file=' . self::$prependFile);
        foreach ($extraIni as $setting) {
            $iniArgs .= ' -d ' . escapeshellarg($setting);
        }

        // 2>&1 merges stderr into stdout so error messages appear inline,
        // matching the format the .phpt EXPECT sections are written for.
        $cmd = 'PHP_INI_SCAN_DIR="" php' . $iniArgs . ' 2>&1';

        $process = proc_open($cmd, [
            0 => ['pipe', 'r'],  // stdin  – we write the code here
            1 => ['pipe', 'w'],  // stdout (stderr already merged via 2>&1)
        ], $pipes, $dir);

        fwrite($pipes[0], $code);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        return $output;
    }

    /** Normalise line endings and strip trailing whitespace. */
    private function normalise(string $s): string
    {
        return rtrim(str_replace("\r\n", "\n", $s));
    }

    /**
     * Convert a PHPT --EXPECTF-- template to a PCRE regex.
     *
     * Conversion table matches PHP's own run-tests.php:
     *   %d   → unsigned decimal integer
     *   %i   → signed decimal integer
     *   %f   → floating-point number
     *   %s   → one or more non-newline characters
     *   %S   → zero or more non-newline characters
     *   %a   → one or more characters of any kind (including newlines)
     *   %A   → zero or more characters of any kind (including newlines)
     *   %w   → zero or more whitespace characters
     *   %e   → directory separator (/ or \)
     *   %x   → one or more hexadecimal digits
     *   %c   → any single character
     *   %%   → a literal %
     *   %r…%r → the content between is already a regex fragment
     */
    private function expectfToRegex(string $expectf): string
    {
        $map = [
            '%d' => '\d+',
            '%i' => '[+-]?\d+',
            '%f' => '[+-]?\.?\d+\.?\d*(?:[Ee][+-]?\d+)?',
            '%s' => '[^\r\n]+',
            '%S' => '[^\r\n]*',
            '%a' => '.+',
            '%A' => '.*',
            '%w' => '\s*',
            '%e' => '[/\\\\]',
            '%x' => '[0-9a-fA-F]+',
            '%X' => '[0-9a-fA-F]+',
            '%c' => '.',
            '%%' => '%',
        ];

        $parts = preg_split('/(%r.+?%r|%[disaSAwexXfFci%])/s', trim($expectf), -1, PREG_SPLIT_DELIM_CAPTURE);

        $regex = '';
        foreach ($parts as $part) {
            if (str_starts_with($part, '%r') && str_contains(substr($part, 2), '%r')) {
                // Inline regex: strip the %r delimiters.
                $regex .= substr($part, 2, strrpos($part, '%r') - 2);
            } elseif (isset($map[$part])) {
                $regex .= $map[$part];
            } else {
                $regex .= preg_quote($part, '/');
            }
        }

        return '/^' . $regex . '$/s';
    }
}
