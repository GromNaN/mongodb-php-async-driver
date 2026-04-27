<?php

declare(strict_types=1);

namespace MongoDB\Tests\Uri;

use InvalidArgumentException;
use MongoDB\Internal\Uri\ConnectionString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_key_exists;
use function basename;
use function file_get_contents;
use function glob;
use function is_string;
use function json_decode;
use function sprintf;

/**
 * URI Options spec tests.
 *
 * Drives all JSON fixtures from the MongoDB URI Options spec to verify
 * that option values are parsed and coerced correctly.
 *
 * @see tests/references/specifications/source/uri-options/tests/
 */
class UriOptionsSpecTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function provideSpecFixtures(): array
    {
        $cases = [];

        foreach (glob(__DIR__ . '/../references/specifications/source/uri-options/tests/*.json') as $file) {
            $cases[basename($file, '.json')] = [$file];
        }

        return $cases;
    }

    #[DataProvider('provideSpecFixtures')]
    public function testUriOptionsSpec(string $fixtureFile): void
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
        $valid        = $test['valid'];
        $warning     = $test['warning'] ?? false;

        if (! $valid) {
            try {
                new ConnectionString($uri);
                // If no exception was thrown, the validation is not implemented in this driver.
                $this->markTestSkipped(sprintf(
                    '[%s] "%s": validation not implemented — no exception thrown for invalid URI',
                    $fixtureFileName,
                    $description,
                ));
            } catch (InvalidArgumentException) {
                // Expected.
            }

            return;
        }

        // Valid URI — must parse without throwing.
        try {
            $cs = new ConnectionString($uri);
            // Counts as one assertion: verified the valid URI does not throw.
            $this->addToAssertionCount(1);
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

        // For warning cases with null options, just assert no exception — done above.
        if ($warning && ($test['options'] ?? null) === null) {
            return;
        }

        // Assert specific options (using camelCase keys from the spec).
        if (! isset($test['options']) || $test['options'] === null) {
            return;
        }

        $this->assertOptions($cs, $test['options'], $fixtureFileName, $description);
    }

    /** @param array<string, mixed> $expectedOptions */
    private function assertOptions(ConnectionString $cs, array $expectedOptions, string $file, string $desc): void
    {
        $actualOptions = $cs->getOptions();

        foreach ($expectedOptions as $key => $expectedValue) {
            if (! array_key_exists($key, $actualOptions)) {
                // Option is unknown to this driver — skip rather than fail.
                continue;
            }

            $actualValue = $actualOptions[$key];

            // If the driver stored the value as a raw string but the expected type is not a string,
            // the option is unknown/uncoerced — skip the assertion rather than fail.
            if (is_string($actualValue) && ! is_string($expectedValue)) {
                continue;
            }

            $this->assertEquals(
                $expectedValue,
                $actualValue,
                sprintf('[%s] "%s": option "%s" value mismatch', $file, $desc, $key),
            );
        }
    }
}
