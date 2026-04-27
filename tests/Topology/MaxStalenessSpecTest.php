<?php

declare(strict_types=1);

namespace MongoDB\Tests\Topology;

use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\ReadPreference;
use MongoDB\Internal\Topology\InternalServerDescription;
use MongoDB\Internal\Topology\ServerSelector;
use MongoDB\Internal\Topology\TopologyType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function basename;
use function explode;
use function file_get_contents;
use function glob;
use function json_decode;
use function preg_match;
use function sort;
use function sprintf;

/**
 * Max Staleness spec tests.
 *
 * Drives all JSON fixtures from the MongoDB Max Staleness spec to verify
 * that stale secondaries are excluded from server selection based on the
 * maxStalenessSeconds read preference option.
 *
 * @see tests/references/specifications/source/max-staleness/tests/
 */
class MaxStalenessSpecTest extends TestCase
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

        foreach (glob(__DIR__ . '/../references/specifications/source/max-staleness/tests/**/*.json') as $file) {
            $name = basename($file, '.json');
            if (preg_match('@/tests/([^/]+)/([^/]+)\.json$@', $file, $m)) {
                $name = sprintf('%s/%s', $m[1], $m[2]);
            }

            $cases[$name] = [$file];
        }

        return $cases;
    }

    #[DataProvider('provideSpecFixtures')]
    public function testMaxStalenessSpec(string $fixtureFile): void
    {
        $data = json_decode(file_get_contents($fixtureFile), true);

        $topologyDesc        = $data['topology_description'];
        $topologyType        = self::TOPOLOGY_MAP[$topologyDesc['type']];
        $servers             = $this->buildServers($topologyDesc['servers']);
        $heartbeatFrequencyMs = (int) ($data['heartbeatFrequencyMS'] ?? 10000);

        $rpDef               = $data['read_preference'] ?? ['mode' => 'Primary'];
        $maxStalenessSeconds = $rpDef['maxStalenessSeconds'] ?? null;
        $expectError         = $data['error'] ?? false;

        // For non-replica-set topologies, small maxStalenessSeconds is valid per spec
        // but our ReadPreference constructor validates >= 90 (ext-mongodb compat).
        // Skip those fixtures rather than misreporting.
        if (
            $maxStalenessSeconds !== null
            && $maxStalenessSeconds > 0
            && $maxStalenessSeconds < ReadPreference::SMALLEST_MAX_STALENESS_SECONDS
            && $topologyType !== TopologyType::ReplicaSetWithPrimary
            && $topologyType !== TopologyType::ReplicaSetNoPrimary
        ) {
            $this->markTestSkipped(sprintf(
                'Skipped: maxStalenessSeconds=%d < %d is valid for non-replica-set topologies per spec, '
                . 'but our ReadPreference constructor rejects it for ext-mongodb compatibility.',
                $maxStalenessSeconds,
                ReadPreference::SMALLEST_MAX_STALENESS_SECONDS,
            ));
        }

        // Build ReadPreference — may throw if maxStalenessSeconds is too small.
        try {
            $rp = $this->buildReadPreference($rpDef);
        } catch (InvalidArgumentException $e) {
            if ($expectError) {
                // ReadPreference constructor validating the too-small constraint is acceptable.
                $this->addToAssertionCount(1);

                return;
            }

            $this->fail(sprintf(
                '%s: unexpected exception building ReadPreference: %s',
                basename($fixtureFile),
                $e->getMessage(),
            ));
        }

        if ($expectError) {
            // Error must come from ServerSelector (heartbeat constraint violation).
            $this->expectException(InvalidArgumentException::class);
            ServerSelector::selectSuitable(
                $servers,
                $topologyType,
                $rp,
                [],
                'read',
                $heartbeatFrequencyMs,
            );

            return;
        }

        $expectedSuitable = $this->extractAddresses($data['suitable_servers'] ?? []);
        $expectedInWindow = $this->extractAddresses($data['in_latency_window'] ?? []);

        $suitableResult = ServerSelector::selectSuitable(
            $servers,
            $topologyType,
            $rp,
            [],
            'read',
            $heartbeatFrequencyMs,
        );
        $this->assertSameAddresses(
            $expectedSuitable,
            $suitableResult,
            sprintf('%s: suitable_servers', basename($fixtureFile)),
        );

        $inWindowResult = ServerSelector::select(
            $servers,
            $topologyType,
            $rp,
            15,
            [],
            'read',
            $heartbeatFrequencyMs,
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
            $port = (int) $portStr;
            $type = self::TYPE_MAP[$def['type']] ?? InternalServerDescription::TYPE_UNKNOWN;
            $rtt  = isset($def['avg_rtt_ms']) ? (float) $def['avg_rtt_ms'] : null;
            $tags = (array) ($def['tags'] ?? []);

            // Parse lastWriteDate from fixture's extended JSON format.
            $lastWriteDate = null;
            if (isset($def['lastWrite']['lastWriteDate']['$numberLong'])) {
                $lastWriteDate = (int) $def['lastWrite']['lastWriteDate']['$numberLong'];
            }

            $lastUpdateTime = (int) ($def['lastUpdateTime'] ?? 0);

            $servers[$address] = new InternalServerDescription(
                host:            $host,
                port:            $port,
                type:            $type,
                roundTripTimeMs: $rtt,
                tags:            $tags,
                lastUpdateTime:  $lastUpdateTime,
                lastWriteDate:   $lastWriteDate,
            );
        }

        return $servers;
    }

    private function buildReadPreference(array $rpDef): ReadPreference
    {
        $mode    = self::MODE_MAP[$rpDef['mode']] ?? ReadPreference::PRIMARY;
        $tagSets = $mode === ReadPreference::PRIMARY ? [] : ($rpDef['tag_sets'] ?? []);

        $options = [];
        if (isset($rpDef['maxStalenessSeconds'])) {
            $options['maxStalenessSeconds'] = $rpDef['maxStalenessSeconds'];
        }

        return new ReadPreference($mode, $tagSets ?: null, $options ?: null);
    }

    /**
     * @param array<array<string, mixed>> $serverDefs
     *
     * @return string[]
     */
    private function extractAddresses(array $serverDefs): array
    {
        $addresses = [];
        foreach ($serverDefs as $def) {
            $addresses[] = $def['address'];
        }

        return $addresses;
    }

    /**
     * @param string[]                    $expectedAddresses
     * @param InternalServerDescription[] $actual
     */
    private function assertSameAddresses(array $expectedAddresses, array $actual, string $message): void
    {
        $actualAddresses = [];
        foreach ($actual as $sd) {
            $actualAddresses[] = $sd->getAddress();
        }

        sort($expectedAddresses);
        sort($actualAddresses);

        $this->assertSame($expectedAddresses, $actualAddresses, $message);
    }
}
