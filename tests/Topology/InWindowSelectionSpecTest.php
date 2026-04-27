<?php

declare(strict_types=1);

namespace MongoDB\Tests\Topology;

use MongoDB\Driver\ReadPreference;
use MongoDB\Internal\Topology\InternalServerDescription;
use MongoDB\Internal\Topology\ServerSelector;
use MongoDB\Internal\Topology\TopologyType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function abs;
use function array_column;
use function array_fill_keys;
use function basename;
use function explode;
use function file_get_contents;
use function glob;
use function json_decode;
use function sprintf;

/**
 * Spec tests for operationCount-based server selection within the latency window.
 *
 * Each fixture specifies a topology, per-server operationCounts, and an
 * iteration count.  After running selectInWindow() for the specified number
 * of iterations, the observed selection frequencies must be within the
 * fixture's tolerance of the expected frequencies.
 *
 * When tolerance is 0 the observed frequency must be exactly equal to the
 * expected one (spec requirement).
 *
 * @see tests/references/specifications/source/server-selection/tests/in_window/
 */
class InWindowSelectionSpecTest extends TestCase
{
    private const TYPE_MAP = [
        'Unknown'         => InternalServerDescription::TYPE_UNKNOWN,
        'Standalone'      => InternalServerDescription::TYPE_STANDALONE,
        'Mongos'          => InternalServerDescription::TYPE_MONGOS,
        'RSPrimary'       => InternalServerDescription::TYPE_RS_PRIMARY,
        'RSSecondary'     => InternalServerDescription::TYPE_RS_SECONDARY,
        'RSArbiter'       => InternalServerDescription::TYPE_RS_ARBITER,
        'RSOther'         => InternalServerDescription::TYPE_RS_OTHER,
        'RSGhost'         => InternalServerDescription::TYPE_RS_GHOST,
        'PossiblePrimary' => InternalServerDescription::TYPE_POSSIBLE_PRIMARY,
        'LoadBalancer'    => InternalServerDescription::TYPE_LOAD_BALANCER,
    ];

    private const TOPOLOGY_MAP = [
        'Unknown'               => TopologyType::Unknown,
        'Single'                => TopologyType::Single,
        'Sharded'               => TopologyType::Sharded,
        'ReplicaSetWithPrimary' => TopologyType::ReplicaSetWithPrimary,
        'ReplicaSetNoPrimary'   => TopologyType::ReplicaSetNoPrimary,
        'LoadBalanced'          => TopologyType::LoadBalanced,
    ];

    /** @return array<string, array{string}> */
    public static function provideInWindowFixtures(): array
    {
        $cases = [];

        foreach (glob(__DIR__ . '/../references/specifications/source/server-selection/tests/in_window/*.json') as $file) {
            $cases[basename($file, '.json')] = [$file];
        }

        return $cases;
    }

    #[DataProvider('provideInWindowFixtures')]
    public function testInWindowSelection(string $fixtureFile): void
    {
        $data = json_decode(file_get_contents($fixtureFile), true);

        $topologyDesc = $data['topology_description'];
        $topologyType = self::TOPOLOGY_MAP[$topologyDesc['type']];
        $servers      = $this->buildServers($topologyDesc['servers']);

        // Build the operationCounts map from the fixture's mocked state.
        $operationCounts = array_column($data['mocked_topology_state'], 'operation_count', 'address');

        $iterations = (int) $data['iterations'];
        $tolerance  = (float) $data['outcome']['tolerance'];

        // Run server selection $iterations times and count selections per address.
        $rp        = new ReadPreference(ReadPreference::NEAREST);
        $selectionCounts = array_fill_keys(array_column($topologyDesc['servers'], 'address'), 0);

        // Get candidates once — operationCounts are frozen (mocked state, not live).
        $candidates = ServerSelector::select($servers, $topologyType, $rp);

        for ($i = 0; $i < $iterations; $i++) {
            $selected = ServerSelector::selectInWindow($candidates, $operationCounts);
            if ($selected === null) {
                continue;
            }

            $selectionCounts[$selected->getAddress()] = ($selectionCounts[$selected->getAddress()] ?? 0) + 1;
        }

        // Verify frequencies.
        foreach ($data['outcome']['expected_frequencies'] as $address => $expectedFreq) {
            $observedFreq = (float) $selectionCounts[$address] / $iterations;

            if ($tolerance === 0.0) {
                $this->assertSame(
                    (float) $expectedFreq,
                    $observedFreq,
                    sprintf('%s: %s expected frequency %s, got %s', basename($fixtureFile), $address, $expectedFreq, $observedFreq),
                );
            } else {
                $this->assertLessThanOrEqual(
                    $tolerance,
                    abs($observedFreq - $expectedFreq),
                    sprintf(
                        '%s: %s observed frequency %.4f deviates from expected %.4f by more than tolerance %.4f',
                        basename($fixtureFile),
                        $address,
                        $observedFreq,
                        $expectedFreq,
                        $tolerance,
                    ),
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<array<string, mixed>> $serverDefs
     *
     * @return array<string, InternalServerDescription>
     */
    private function buildServers(array $serverDefs): array
    {
        $servers = [];

        foreach ($serverDefs as $def) {
            $address = $def['address'];
            [$host, $portStr] = explode(':', $address);
            $port  = (int) $portStr;
            $type  = self::TYPE_MAP[$def['type']] ?? InternalServerDescription::TYPE_UNKNOWN;
            $rtt   = isset($def['avg_rtt_ms']) ? (float) $def['avg_rtt_ms'] : null;
            $tags  = (array) ($def['tags'] ?? []);

            $servers[$address] = new InternalServerDescription(
                host:            $host,
                port:            $port,
                type:            $type,
                roundTripTimeMs: $rtt,
                tags:            $tags,
            );
        }

        return $servers;
    }
}
