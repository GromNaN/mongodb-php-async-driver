<?php

declare(strict_types=1);

namespace MongoDB\Tests\Phpt;

use Generator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function bin2hex;
use function dirname;
use function escapeshellarg;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function preg_split;
use function random_bytes;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function substr;
use function trim;
use function unlink;

use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * Runs the official ext-mongodb .phpt test suite against the userland driver.
 *
 * Each .phpt file becomes a single PHPUnit test case.  The FILE section is
 * executed in a subprocess with PHP_INI_SCAN_DIR="" (so ext-mongodb is not
 * loaded) and with a prepend file that boots the Composer autoloader.
 */
class PhptRunnerTest extends TestCase
{
    private static string $prependFile;
    private static string $phptRoot;

    public static function setUpBeforeClass(): void
    {
        self::$prependFile = __DIR__ . '/prepend.php';
        self::$phptRoot    = dirname(__DIR__, 2) . '/.refs/mongo-php-driver/tests';
    }

    /** @return Generator<string, array{string}> */
    public static function phptFiles(): Generator
    {
        $root     = dirname(__DIR__, 2) . '/.refs/mongo-php-driver/tests';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'phpt') {
                continue;
            }

            // Key is the relative path; used as the test name in the output.
            $relative = substr($file->getPathname(), strlen($root) + 1);
            yield $relative => [$file->getPathname()];
        }
    }

    /** @dataProvider phptFiles */
    public function testPhpt(string $filePath): void
    {
        $sections = $this->parseSections($filePath);
        $dir      = dirname($filePath);

        // --SKIPIF-- --------------------------------------------------------
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
                if ($line !== '') {
                    $extraIni[] = $line;
                }
            }
        }

        // --FILE-- ----------------------------------------------------------
        $actual = $this->runPhpCode($sections['FILE'], $dir, $extraIni);

        // --EXPECT* -- -------------------------------------------------------
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
                $current              = $m[1];
                $sections[$current]   = '';
            } elseif ($current !== null) {
                $sections[$current] .= $line . "\n";
            }
        }

        return $sections;
    }

    /**
     * Execute a block of PHP code in a subprocess and return its combined
     * stdout + stderr output.
     *
     * The code is written to a temp file placed in $dir so that __DIR__ inside
     * the test code resolves to the original test's directory.
     *
     * @param list<string> $extraIni  Additional -d ini=value flags.
     */
    private function runPhpCode(string $code, string $dir, array $extraIni = []): string
    {
        $tmpFile = $dir . '/_phpunit_phpt_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($tmpFile, $code);

        try {
            $iniFlags = '';
            foreach ($extraIni as $setting) {
                $iniFlags .= ' -d ' . escapeshellarg($setting);
            }

            $cmd = 'PHP_INI_SCAN_DIR="" php'
                . ' -d auto_prepend_file=' . escapeshellarg(self::$prependFile)
                . $iniFlags
                . ' ' . escapeshellarg($tmpFile)
                . ' 2>&1';

            exec($cmd, $output);

            return implode("\n", $output);
        } finally {
            unlink($tmpFile);
        }
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
     *   %r…%r → content is already a regex fragment
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

        // Split on %r...%r blocks and on plain % tokens so we can handle them
        // independently.
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
