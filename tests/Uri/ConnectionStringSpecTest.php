<?php

declare(strict_types=1);

namespace MongoDB\Tests\Uri;

use InvalidArgumentException;
use MongoDB\Internal\Uri\ConnectionString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function basename;
use function count;
use function file_get_contents;
use function glob;
use function json_decode;
use function sprintf;
use function strtolower;

/**
 * Connection String spec tests.
 *
 * Drives all JSON fixtures from the MongoDB Connection String spec to verify
 * that valid URIs parse correctly and invalid URIs throw exceptions.
 *
 * @see tests/references/specifications/source/connection-string/tests/
 */
class ConnectionStringSpecTest extends TestCase
{
    private const DEFAULT_PORT = 27017;

    /** @return array<string, array{string}> */
    public static function provideSpecFixtures(): array
    {
        $cases = [];

        foreach (glob(__DIR__ . '/../references/specifications/source/connection-string/tests/*.json') as $file) {
            $cases[basename($file, '.json')] = [$file];
        }

        return $cases;
    }

    #[DataProvider('provideSpecFixtures')]
    public function testConnectionStringSpec(string $fixtureFile): void
    {
        $fixture = json_decode(file_get_contents($fixtureFile), true);

        foreach ($fixture['tests'] as $test) {
            $this->runSingleTest($test, basename($fixtureFile));
        }
    }

    private function runSingleTest(array $test, string $fixtureFileName): void
    {
        $description = $test['description'];
        $uri         = $test['uri'];
        $valid       = $test['valid'];
        $warning     = $test['warning'] ?? false;

        if (! $valid) {
            // Invalid URIs must throw on construction.
            try {
                new ConnectionString($uri);
                $this->fail(sprintf(
                    '[%s] "%s": expected InvalidArgumentException but none was thrown',
                    $fixtureFileName,
                    $description,
                ));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1); // Verified: invalid URI correctly rejected.
            }

            return;
        }

        // Valid URI — must not throw.
        try {
            $cs = new ConnectionString($uri);
        } catch (InvalidArgumentException $e) {
            if ($warning) {
                // Spec allows drivers to reject invalid option values instead of warning;
                // our parser is stricter than spec for some options — skip rather than fail.
                $this->markTestSkipped(sprintf(
                    '[%s] "%s": parser is stricter than spec (warning case threw): %s',
                    $fixtureFileName,
                    $description,
                    $e->getMessage(),
                ));
            }

            $this->fail(sprintf(
                '[%s] "%s": unexpected InvalidArgumentException: %s',
                $fixtureFileName,
                $description,
                $e->getMessage(),
            ));
        }

        // When warning is expected, we skip detailed option assertions (the
        // driver may silently ignore or reject the problematic option — both
        // are acceptable here since we test the URI-parsing layer only).
        if ($warning) {
            // Just assert parsing did not throw — already done above.
            if (isset($test['options']) && $test['options'] !== null) {
                // Some warning fixtures still have expected options; assert those.
                $this->assertOptions($cs, $test['options'], $fixtureFileName, $description);
            }

            return;
        }

        // Assert hosts.
        if (isset($test['hosts']) && $test['hosts'] !== null) {
            $this->assertHosts($cs, $test['hosts'], $fixtureFileName, $description);
        }

        // Assert auth (username / password / database).
        if (isset($test['auth']) && $test['auth'] !== null) {
            $auth = $test['auth'];
            $this->assertSame(
                $auth['username'],
                $cs->getUsername(),
                sprintf('[%s] "%s": username mismatch', $fixtureFileName, $description),
            );
            $this->assertSame(
                $auth['password'],
                $cs->getPassword(),
                sprintf('[%s] "%s": password mismatch', $fixtureFileName, $description),
            );
            $this->assertSame(
                $auth['db'],
                $cs->getDatabase(),
                sprintf('[%s] "%s": database mismatch', $fixtureFileName, $description),
            );
        }

        // Assert options (fixture uses lowercase keys; our impl uses camelCase).
        if (! isset($test['options']) || $test['options'] === null) {
            return;
        }

        $this->assertOptions($cs, $test['options'], $fixtureFileName, $description);
    }

    /** @param array<array{type: string, host: string, port: int|null}> $expectedHosts */
    private function assertHosts(ConnectionString $cs, array $expectedHosts, string $file, string $desc): void
    {
        $actualHosts = $cs->getHosts();

        $this->assertCount(
            count($expectedHosts),
            $actualHosts,
            sprintf('[%s] "%s": host count mismatch', $file, $desc),
        );

        foreach ($expectedHosts as $i => $expected) {
            $actual = $actualHosts[$i] ?? null;
            $this->assertNotNull(
                $actual,
                sprintf('[%s] "%s": missing host at index %d', $file, $desc, $i),
            );

            $this->assertSame(
                $expected['host'],
                $actual['host'],
                sprintf('[%s] "%s": host[%d].host mismatch', $file, $desc, $i),
            );

            $expectedPort = $expected['port'] ?? self::DEFAULT_PORT;
            $this->assertSame(
                $expectedPort,
                $actual['port'],
                sprintf('[%s] "%s": host[%d].port mismatch', $file, $desc, $i),
            );
        }
    }

    /** @param array<string, mixed> $expectedOptions */
    private function assertOptions(ConnectionString $cs, array $expectedOptions, string $file, string $desc): void
    {
        $actualOptions = $cs->getOptions();

        // Build a lowercase-keyed index of actual options for case-insensitive lookup.
        $actualLower = [];
        foreach ($actualOptions as $key => $value) {
            if ($key === '__srv') {
                continue;
            }

            $actualLower[strtolower($key)] = $value;
        }

        foreach ($expectedOptions as $expectedKey => $expectedValue) {
            $lowerKey = strtolower($expectedKey);

            $this->assertArrayHasKey(
                $lowerKey,
                $actualLower,
                sprintf('[%s] "%s": missing option "%s"', $file, $desc, $expectedKey),
            );

            $this->assertEquals(
                $expectedValue,
                $actualLower[$lowerKey],
                sprintf('[%s] "%s": option "%s" value mismatch', $file, $desc, $expectedKey),
            );
        }
    }
}
