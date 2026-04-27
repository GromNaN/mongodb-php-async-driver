<?php

declare(strict_types=1);

namespace MongoDB\Tests\Topology;

use MongoDB\Internal\Topology\InternalServerDescription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function basename;
use function file_get_contents;
use function glob;
use function json_decode;
use function sprintf;

/**
 * RTT EWMA calculation spec tests.
 *
 * Verifies the exponential weighted moving average formula used for smoothing
 * round-trip time measurements (new_avg = 0.2 * new_rtt + 0.8 * prev_avg).
 *
 * @see tests/references/specifications/source/server-selection/tests/rtt/
 */
class RttCalculationSpecTest extends TestCase
{
    /** @return array<string, array{?float, float, float}> */
    public static function provideRttFixtures(): array
    {
        $cases = [];

        foreach (glob(__DIR__ . '/../references/specifications/source/server-selection/tests/rtt/*.json') as $file) {
            $data   = json_decode(file_get_contents($file), true);
            $prevAvg = $data['avg_rtt_ms'] === 'NULL' ? null : (float) $data['avg_rtt_ms'];
            $name    = basename($file, '.json');

            $cases[$name] = [$prevAvg, (float) $data['new_rtt_ms'], (float) $data['new_avg_rtt']];
        }

        return $cases;
    }

    #[DataProvider('provideRttFixtures')]
    public function testEwmaCalculation(?float $prevAvg, float $newRtt, float $expectedAvg): void
    {
        $result = InternalServerDescription::calculateEwmaRtt($prevAvg, $newRtt);

        $this->assertEqualsWithDelta($expectedAvg, $result, 0.001, sprintf(
            'EWMA(prev=%s, new=%s) expected %s, got %s',
            $prevAvg ?? 'NULL',
            $newRtt,
            $expectedAvg,
            $result,
        ));
    }
}
