<?php

declare(strict_types=1);

namespace MongoDB\Tests\Topology;

use MongoDB\Driver\ReadPreference;
use MongoDB\Internal\Topology\InternalServerDescription;
use MongoDB\Internal\Topology\ServerSelector;
use MongoDB\Internal\Topology\TopologyType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function array_values;
use function basename;
use function explode;
use function file_get_contents;
use function glob;
use function json_decode;
use function preg_match;
use function sort;
use function sprintf;
use function strtolower;

/**
 * Server selection logic spec tests.
 *
 * Drives all JSON fixtures from the MongoDB Server Selection spec to verify
 * that suitable_servers (pre-latency) and in_latency_window (post-latency)
 * are computed correctly for every topology type and read preference mode.
 *
 * @see tests/references/specifications/source/server-selection/tests/server_selection/
 */
class ServerSelectionSpecTest extends TestCase
{
    private const TYPE_MAP = [
        'Unknown'          => InternalServerDescription::TYPE_UNKNOWN,
        'Standalone'       => InternalServerDescription::TYPE_STANDALONE,
        'Mongos'           => InternalServerDescription::TYPE_MONGOS,
        'RSPrimary'        => InternalServerDescription::TYPE_RS_PRIMARY,
        'RSSecondary'      => InternalServerDescription::TYPE_RS_SECONDARY,
        'RSArbiter'        => InternalServerDescription::TYPE_RS_ARBITER,
        'RSOther'          => InternalServerDescription::TYPE_RS_OTHER,
        'RSGhost'          => InternalServerDescription::TYPE_RS_GHOST,
        'PossiblePrimary'  => InternalServerDescription::TYPE_POSSIBLE_PRIMARY,
        'LoadBalancer'     => InternalServerDescription::TYPE_LOAD_BALANCER,
    ];

    private const TOPOLOGY_MAP = [
        'Unknown'                => TopologyType::Unknown,
        'Single'                 => TopologyType::Single,
        'Sharded'                => TopologyType::Sharded,
        'ReplicaSetWithPrimary'  => TopologyType::ReplicaSetWithPrimary,
        'ReplicaSetNoPrimary'    => TopologyType::ReplicaSetNoPrimary,
        'LoadBalanced'           => TopologyType::LoadBalanced,
    ];

    private const MODE_MAP = [
        'Primary'            => ReadPreference::PRIMARY,
        'PrimaryPreferred'   => ReadPreference::PRIMARY_PREFERRED,
        'Secondary'          => ReadPreference::SECONDARY,
        'SecondaryPreferred' => ReadPreference::SECONDARY_PREFERRED,
        'Nearest'            => ReadPreference::NEAREST,
    ];

    /** @return array<string, array{string}> */
    public static function provideSpecFixtures(): array
    {
        $cases = [];

        foreach (glob(__DIR__ . '/../references/specifications/source/server-selection/tests/server_selection/**/**/*.json') as $file) {
            $name          = strtolower(basename($file, '.json'));
            $parts         = [];
            $relative      = $file;
            // Build a unique name from topology/rw/testname
            if (preg_match('@/server_selection/([^/]+)/([^/]+)/([^/]+)\.json$@', $file, $m)) {
                $name = sprintf('%s/%s/%s', $m[1], $m[2], $m[3]);
            }

            $cases[$name] = [$file];
        }

        return $cases;
    }

    #[DataProvider('provideSpecFixtures')]
    public function testServerSelectionSpec(string $fixtureFile): void
    {
        $data = json_decode(file_get_contents($fixtureFile), true);

        $topologyDesc = $data['topology_description'];
        $topologyType = self::TOPOLOGY_MAP[$topologyDesc['type']];
        $servers      = $this->buildServers($topologyDesc['servers']);

        $rp        = $this->buildReadPreference($data['read_preference'] ?? ['mode' => 'Primary']);
        $operation = $data['operation'] ?? 'read';

        $deprioritizedAddresses = [];
        foreach ($data['deprioritized_servers'] ?? [] as $ds) {
            $deprioritizedAddresses[] = $ds['address'];
        }

        $expectedSuitable = $this->extractAddresses($data['suitable_servers']);
        $expectedInWindow = $this->extractAddresses($data['in_latency_window']);

        // Verify suitable_servers (pre-latency-window).
        $suitableResult = ServerSelector::selectSuitable(
            $servers,
            $topologyType,
            $rp,
            $deprioritizedAddresses,
            $operation,
        );
        $this->assertSameAddresses(
            $expectedSuitable,
            $suitableResult,
            sprintf('%s: suitable_servers', basename($fixtureFile)),
        );

        // Verify in_latency_window (post-latency-window).
        $inWindowResult = ServerSelector::select(
            $servers,
            $topologyType,
            $rp,
            15,
            $deprioritizedAddresses,
            $operation,
        );
        $this->assertSameAddresses(
            $expectedInWindow,
            $inWindowResult,
            sprintf('%s: in_latency_window', basename($fixtureFile)),
        );
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

    private function buildReadPreference(array $rpDef): ReadPreference
    {
        $mode    = self::MODE_MAP[$rpDef['mode']] ?? ReadPreference::PRIMARY;
        // Primary mode does not accept tag sets (spec: tags conflict with primary).
        $tagSets = $mode === ReadPreference::PRIMARY ? [] : ($rpDef['tag_sets'] ?? []);

        return new ReadPreference($mode, $tagSets);
    }

    /**
     * @param array<array<string, mixed>> $serverDefs
     *
     * @return string[]
     */
    private function extractAddresses(array $serverDefs): array
    {
        return array_column($serverDefs, 'address');
    }

    /**
     * @param string[]                    $expectedAddresses
     * @param InternalServerDescription[] $actual
     */
    private function assertSameAddresses(array $expectedAddresses, array $actual, string $message): void
    {
        $actualAddresses = array_map(
            static fn (InternalServerDescription $sd) => $sd->getAddress(),
            array_values($actual),
        );

        sort($expectedAddresses);
        sort($actualAddresses);

        $this->assertSame($expectedAddresses, $actualAddresses, $message);
    }
}
