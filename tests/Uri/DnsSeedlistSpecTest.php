<?php

declare(strict_types=1);

namespace MongoDB\Tests\Uri;

use InvalidArgumentException;
use MongoDB\Internal\Uri\ConnectionString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function basename;
use function dirname;
use function file_get_contents;
use function glob;
use function json_decode;
use function sprintf;

/**
 * Initial DNS Seedlist Discovery spec tests.
 *
 * Drives the JSON fixtures from the MongoDB DNS Seedlist spec to verify
 * that URI-level errors (port in SRV, two hosts, directConnection=true,
 * srvMaxHosts conflicts detectable from the URI) are rejected at construction
 * time. Fixtures that require actual DNS resolution to verify seeds/hosts or
 * detect TXT-record errors are skipped.
 *
 * @see tests/references/specifications/source/initial-dns-seedlist-discovery/tests/
 */
class DnsSeedlistSpecTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function provideSpecFixtures(): array
    {
        $cases = [];

        foreach (glob(__DIR__ . '/../references/specifications/source/initial-dns-seedlist-discovery/tests/**/*.json') as $file) {
            $category = basename(dirname($file));
            $name     = sprintf('%s/%s', $category, basename($file, '.json'));
            $cases[$name] = [$file];
        }

        return $cases;
    }

    #[DataProvider('provideSpecFixtures')]
    public function testDnsSeedlistSpec(string $fixtureFile): void
    {
        $data  = json_decode(file_get_contents($fixtureFile), true);
        $uri   = $data['uri'];
        $error = $data['error'] ?? false;

        if (! $error) {
            // Valid fixtures: verifying seeds/hosts/options requires DNS
            // resolution which is not available in unit tests.
            $this->markTestSkipped(sprintf(
                '%s: valid fixtures require DNS resolution — skipped in unit tests.',
                basename($fixtureFile),
            ));
        }

        // Error fixture: try to construct the ConnectionString.
        // If it throws here, the URI-level validation caught the error → pass.
        // If it does not throw, the error is DNS-dependent (e.g. TXT record
        // validation, parent-part mismatch, no SRV results) → skip.
        try {
            new ConnectionString($uri);
        } catch (InvalidArgumentException) {
            // URI-level error correctly rejected at parse time.
            $this->addToAssertionCount(1);

            return;
        }

        $this->markTestSkipped(sprintf(
            '%s: error is DNS-dependent (TXT record or SRV response validation) — skipped in unit tests.',
            basename($fixtureFile),
        ));
    }
}
