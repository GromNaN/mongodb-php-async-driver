<?php

declare(strict_types=1);

namespace MongoDB\Tests\Topology;

use MongoDB\Driver\ReadPreference;
use MongoDB\Internal\Topology\InternalServerDescription;
use MongoDB\Internal\Topology\ServerSelector;
use MongoDB\Internal\Topology\TopologyType;
use PHPUnit\Framework\TestCase;

use function array_first;
use function array_keys;
use function array_map;

class ServerSelectorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function sd(
        string $host,
        string $type,
        ?int $rttMs = null,
        array $tags = [],
    ): InternalServerDescription {
        return new InternalServerDescription(
            host: $host,
            port: 27017,
            type: $type,
            roundTripTimeMs: $rttMs,
            tags: $tags,
        );
    }

    private static function servers(InternalServerDescription ...$sds): array
    {
        $map = [];
        foreach ($sds as $sd) {
            $map[$sd->host . ':' . $sd->port] = $sd;
        }

        return $map;
    }

    // -------------------------------------------------------------------------
    // Single topology
    // -------------------------------------------------------------------------

    public function testSingleTopologyReturnsAvailableServer(): void
    {
        $servers = self::servers(
            self::sd('a', InternalServerDescription::TYPE_STANDALONE, 5),
        );

        $result = ServerSelector::select($servers, TopologyType::Single, new ReadPreference(ReadPreference::PRIMARY));

        $this->assertCount(1, $result);
        $this->assertSame('a', array_first($result)->host);
    }

    public function testSingleTopologyExcludesUnknownServer(): void
    {
        $servers = self::servers(
            self::sd('a', InternalServerDescription::TYPE_UNKNOWN, null),
        );

        $result = ServerSelector::select($servers, TopologyType::Single, new ReadPreference(ReadPreference::PRIMARY));

        $this->assertCount(0, $result);
    }

    // -------------------------------------------------------------------------
    // LoadBalanced topology
    // -------------------------------------------------------------------------

    public function testLoadBalancedTopologyReturnsLbServer(): void
    {
        // Spec: Single/read/SecondaryPreferred — LB server returned regardless of RP and tags.
        $servers = self::servers(
            self::sd('g', InternalServerDescription::TYPE_LOAD_BALANCER, 0),
        );

        $rp     = new ReadPreference(ReadPreference::SECONDARY, [['data_center' => 'nyc']]);
        $result = ServerSelector::select($servers, TopologyType::LoadBalanced, $rp);

        $this->assertCount(1, $result);
        $this->assertSame('g', array_first($result)->host);
    }

    // -------------------------------------------------------------------------
    // Unknown topology / ghost servers
    // -------------------------------------------------------------------------

    public function testUnknownTopologyReturnsEmpty(): void
    {
        // Spec: Unknown/read/SecondaryPreferred — empty topology → no candidates.
        $result = ServerSelector::select([], TopologyType::Unknown, new ReadPreference(ReadPreference::SECONDARY_PREFERRED));

        $this->assertCount(0, $result);
    }

    public function testGhostServerIsNotSelectable(): void
    {
        // Spec: Unknown/read/ghost — RSGhost is TYPE_UNKNOWN-equivalent for availability.
        // RSGhost type is not RS_SECONDARY / RS_PRIMARY / etc. so it cannot be selected.
        $servers = self::servers(
            self::sd('a', InternalServerDescription::TYPE_RS_GHOST, 5),
        );

        $result = ServerSelector::select($servers, TopologyType::Unknown, new ReadPreference(ReadPreference::NEAREST));

        $this->assertCount(0, $result);
    }

    // -------------------------------------------------------------------------
    // Sharded topology
    // -------------------------------------------------------------------------

    public function testShardedTopologyAppliesLatencyWindow(): void
    {
        // Spec: Sharded/read/Primary — g:5ms, h:35ms → only g survives window (15ms default).
        $servers = self::servers(
            self::sd('g', InternalServerDescription::TYPE_MONGOS, 5),
            self::sd('h', InternalServerDescription::TYPE_MONGOS, 35),
        );

        $result = ServerSelector::select($servers, TopologyType::Sharded, new ReadPreference(ReadPreference::PRIMARY));

        $this->assertCount(1, $result);
        $this->assertSame('g', array_first($result)->host);
    }

    public function testShardedTopologyReturnsMongosOnly(): void
    {
        $servers = self::servers(
            self::sd('a', InternalServerDescription::TYPE_MONGOS, 10),
            self::sd('b', InternalServerDescription::TYPE_STANDALONE, 5),
        );

        $result = ServerSelector::select($servers, TopologyType::Sharded, new ReadPreference(ReadPreference::PRIMARY));

        $this->assertCount(1, $result);
        $this->assertSame('a', array_first($result)->host);
    }

    // -------------------------------------------------------------------------
    // Replica set — primary mode
    // -------------------------------------------------------------------------

    public function testPrimaryModeReturnsPrimary(): void
    {
        $servers = self::servers(
            self::sd('primary', InternalServerDescription::TYPE_RS_PRIMARY, 5),
            self::sd('secondary', InternalServerDescription::TYPE_RS_SECONDARY, 3),
        );

        $result = ServerSelector::select($servers, TopologyType::ReplicaSetWithPrimary, new ReadPreference(ReadPreference::PRIMARY));

        $this->assertCount(1, $result);
        $this->assertSame('primary', array_first($result)->host);
    }

    public function testPrimaryModeReturnsEmptyWhenNoPrimary(): void
    {
        $servers = self::servers(
            self::sd('s1', InternalServerDescription::TYPE_RS_SECONDARY, 5),
            self::sd('s2', InternalServerDescription::TYPE_RS_SECONDARY, 8),
        );

        $result = ServerSelector::select($servers, TopologyType::ReplicaSetNoPrimary, new ReadPreference(ReadPreference::PRIMARY));

        $this->assertCount(0, $result);
    }

    // -------------------------------------------------------------------------
    // Replica set — primaryPreferred mode
    // -------------------------------------------------------------------------

    public function testPrimaryPreferredReturnsPrimaryWhenAvailable(): void
    {
        $servers = self::servers(
            self::sd('primary', InternalServerDescription::TYPE_RS_PRIMARY, 5),
            self::sd('secondary', InternalServerDescription::TYPE_RS_SECONDARY, 3),
        );

        $result = ServerSelector::select($servers, TopologyType::ReplicaSetWithPrimary, new ReadPreference(ReadPreference::PRIMARY_PREFERRED));

        $this->assertCount(1, $result);
        $this->assertSame('primary', array_first($result)->host);
    }

    public function testPrimaryPreferredFallsBackToSecondaries(): void
    {
        $servers = self::servers(
            self::sd('s1', InternalServerDescription::TYPE_RS_SECONDARY, 5),
            self::sd('s2', InternalServerDescription::TYPE_RS_SECONDARY, 8),
        );

        $result = ServerSelector::select($servers, TopologyType::ReplicaSetNoPrimary, new ReadPreference(ReadPreference::PRIMARY_PREFERRED));

        $this->assertCount(2, $result);
    }

    // -------------------------------------------------------------------------
    // Replica set — secondary mode
    // -------------------------------------------------------------------------

    public function testSecondaryModeReturnsSecondariesWithinLatencyWindow(): void
    {
        $servers = self::servers(
            self::sd('primary', InternalServerDescription::TYPE_RS_PRIMARY, 5),
            self::sd('s1', InternalServerDescription::TYPE_RS_SECONDARY, 10),
            self::sd('s2', InternalServerDescription::TYPE_RS_SECONDARY, 30),
        );

        // default localThresholdMs = 15; minRtt = 10 → threshold = 25; s2 (30) excluded
        $result = ServerSelector::select($servers, TopologyType::ReplicaSetWithPrimary, new ReadPreference(ReadPreference::SECONDARY));

        $this->assertCount(1, $result);
        $this->assertSame('s1', array_first($result)->host);
    }

    // -------------------------------------------------------------------------
    // Replica set — secondaryPreferred mode
    // -------------------------------------------------------------------------

    public function testSecondaryPreferredFallsBackToPrimary(): void
    {
        $servers = self::servers(
            self::sd('primary', InternalServerDescription::TYPE_RS_PRIMARY, 5),
        );

        $result = ServerSelector::select($servers, TopologyType::ReplicaSetWithPrimary, new ReadPreference(ReadPreference::SECONDARY_PREFERRED));

        $this->assertCount(1, $result);
        $this->assertSame('primary', array_first($result)->host);
    }

    // -------------------------------------------------------------------------
    // Replica set — nearest mode
    // -------------------------------------------------------------------------

    public function testNearestModeIncludesAllTypesWithinLatencyWindow(): void
    {
        $servers = self::servers(
            self::sd('primary', InternalServerDescription::TYPE_RS_PRIMARY, 5),
            self::sd('s1', InternalServerDescription::TYPE_RS_SECONDARY, 10),
            self::sd('s2', InternalServerDescription::TYPE_RS_SECONDARY, 25),
        );

        // localThresholdMs = 15; minRtt = 5 → threshold = 20; s2 (25) excluded
        $result = ServerSelector::select($servers, TopologyType::ReplicaSetWithPrimary, new ReadPreference(ReadPreference::NEAREST));

        $hosts = array_map(static fn ($sd) => $sd->host, $result);
        $this->assertContains('primary', $hosts);
        $this->assertContains('s1', $hosts);
        $this->assertNotContains('s2', $hosts);
    }

    // -------------------------------------------------------------------------
    // Latency window
    // -------------------------------------------------------------------------

    public function testLatencyWindowExcludesSlowServers(): void
    {
        $servers = self::servers(
            self::sd('fast', InternalServerDescription::TYPE_RS_SECONDARY, 10),
            self::sd('medium', InternalServerDescription::TYPE_RS_SECONDARY, 24),
            self::sd('slow', InternalServerDescription::TYPE_RS_SECONDARY, 26),
        );

        // localThresholdMs = 15; minRtt = 10 → threshold = 25; slow (26) excluded
        $result = ServerSelector::select($servers, TopologyType::ReplicaSetWithPrimary, new ReadPreference(ReadPreference::SECONDARY), 15);

        $hosts = array_map(static fn ($sd) => $sd->host, $result);
        $this->assertContains('fast', $hosts);
        $this->assertContains('medium', $hosts);
        $this->assertNotContains('slow', $hosts);
    }

    public function testLatencyWindowBoundaryIsInclusive(): void
    {
        $servers = self::servers(
            self::sd('s1', InternalServerDescription::TYPE_RS_SECONDARY, 10),
            self::sd('s2', InternalServerDescription::TYPE_RS_SECONDARY, 25),
        );

        // localThresholdMs = 15; minRtt = 10 → threshold = 25; s2 exactly at boundary → included
        $result = ServerSelector::select($servers, TopologyType::ReplicaSetWithPrimary, new ReadPreference(ReadPreference::SECONDARY), 15);

        $this->assertCount(2, $result);
    }

    public function testLatencyWindowWithAllNullRttReturnsAllCandidates(): void
    {
        $servers = self::servers(
            self::sd('s1', InternalServerDescription::TYPE_RS_SECONDARY, null),
            self::sd('s2', InternalServerDescription::TYPE_RS_SECONDARY, null),
        );

        $result = ServerSelector::select($servers, TopologyType::ReplicaSetWithPrimary, new ReadPreference(ReadPreference::SECONDARY));

        $this->assertCount(2, $result);
    }

    public function testLatencyWindowExcludesNullRttServersWhenOthersHaveMeasurements(): void
    {
        $servers = self::servers(
            self::sd('measured', InternalServerDescription::TYPE_RS_SECONDARY, 10),
            self::sd('unmeasured', InternalServerDescription::TYPE_RS_SECONDARY, null),
        );

        // Servers with no RTT are excluded when at least one RTT measurement exists
        $result = ServerSelector::select($servers, TopologyType::ReplicaSetWithPrimary, new ReadPreference(ReadPreference::SECONDARY));

        $this->assertCount(1, $result);
        $this->assertSame('measured', array_first($result)->host);
    }

    // -------------------------------------------------------------------------
    // Result is a sequential (list) array
    // -------------------------------------------------------------------------

    public function testResultIsSequentialList(): void
    {
        $servers = self::servers(
            self::sd('s1', InternalServerDescription::TYPE_RS_SECONDARY, 10),
            self::sd('s2', InternalServerDescription::TYPE_RS_SECONDARY, 12),
        );

        $result = ServerSelector::select($servers, TopologyType::ReplicaSetWithPrimary, new ReadPreference(ReadPreference::SECONDARY));

        $this->assertSame([0, 1], array_keys($result));
    }

    // -------------------------------------------------------------------------
    // Tag-set filtering
    // -------------------------------------------------------------------------

    public function testTagSetFilteringKeepsMatchingServers(): void
    {
        $servers = self::servers(
            self::sd('s1', InternalServerDescription::TYPE_RS_SECONDARY, 5, ['dc' => 'east']),
            self::sd('s2', InternalServerDescription::TYPE_RS_SECONDARY, 5, ['dc' => 'west']),
        );

        $rp     = new ReadPreference(ReadPreference::SECONDARY, [['dc' => 'east']]);
        $result = ServerSelector::select($servers, TopologyType::ReplicaSetWithPrimary, $rp);

        $this->assertCount(1, $result);
        $this->assertSame('s1', array_first($result)->host);
    }

    public function testTagSetFilteringWithMultipleSets(): void
    {
        $servers = self::servers(
            self::sd('s1', InternalServerDescription::TYPE_RS_SECONDARY, 5, ['dc' => 'east']),
            self::sd('s2', InternalServerDescription::TYPE_RS_SECONDARY, 5, ['dc' => 'west']),
            self::sd('s3', InternalServerDescription::TYPE_RS_SECONDARY, 5, ['dc' => 'eu']),
        );

        // First tag set matches s1, second tag set matches s2; s3 should be excluded.
        $rp     = new ReadPreference(ReadPreference::SECONDARY, [['dc' => 'east'], ['dc' => 'west']]);
        $result = ServerSelector::select($servers, TopologyType::ReplicaSetWithPrimary, $rp);

        $hosts = array_map(static fn ($sd) => $sd->host, $result);
        $this->assertContains('s1', $hosts);
        $this->assertContains('s2', $hosts);
        $this->assertNotContains('s3', $hosts);
    }

    public function testEmptyTagSetMatchesAllServers(): void
    {
        // Spec: SecondaryPreferred_empty_tags — tag_sets: [{data_center:nyc}, {}]
        // The empty {} tag set is a wildcard and matches any server.
        $servers = self::servers(
            self::sd('primary', InternalServerDescription::TYPE_RS_PRIMARY, 5),
            self::sd('s1', InternalServerDescription::TYPE_RS_SECONDARY, 5),
        );

        // First tag set {data_center:nyc} matches nothing (no tags on servers),
        // second tag set {} matches all — so s1 is selected.
        $rp     = new ReadPreference(ReadPreference::SECONDARY_PREFERRED, [['data_center' => 'nyc'], []]);
        $result = ServerSelector::select($servers, TopologyType::ReplicaSetWithPrimary, $rp);

        $this->assertCount(1, $result);
        $this->assertSame('s1', array_first($result)->host);
    }

    // -------------------------------------------------------------------------
    // Spec edge cases
    // -------------------------------------------------------------------------

    public function testPossiblePrimaryIsNotSelectedForPrimaryMode(): void
    {
        // Spec: ReplicaSetNoPrimary/read/PossiblePrimary — PossiblePrimary is not a true primary.
        $servers = self::servers(
            self::sd('b', InternalServerDescription::TYPE_POSSIBLE_PRIMARY, 5),
        );

        $result = ServerSelector::select($servers, TopologyType::ReplicaSetNoPrimary, new ReadPreference(ReadPreference::PRIMARY));

        $this->assertCount(0, $result);
    }

    public function testSingleTopologyIgnoresTagsAndReturnsServer(): void
    {
        // Spec: Single/read/SecondaryPreferred — Single topology ignores RP tags.
        // The server has {data_center:dc} but RP asks for {data_center:nyc}; still returned.
        $servers = self::servers(
            self::sd('a', InternalServerDescription::TYPE_STANDALONE, 5, ['data_center' => 'dc']),
        );

        $rp     = new ReadPreference(ReadPreference::SECONDARY_PREFERRED, [['data_center' => 'nyc']]);
        $result = ServerSelector::select($servers, TopologyType::Single, $rp);

        $this->assertCount(1, $result);
        $this->assertSame('a', array_first($result)->host);
    }

    public function testPrimaryPreferredWithNonMatchingTagsFallsBackToPrimary(): void
    {
        // Spec: ReplicaSetWithPrimary/read/PrimaryPreferred_non_matching —
        // When primary is available it is returned even though tags don't match it.
        // (Tags are only applied to the secondary fallback, not the primary short-circuit.)
        $servers = self::servers(
            self::sd('a', InternalServerDescription::TYPE_RS_PRIMARY, 26, ['data_center' => 'nyc']),
            self::sd('b', InternalServerDescription::TYPE_RS_SECONDARY, 5, ['data_center' => 'nyc']),
        );

        $rp     = new ReadPreference(ReadPreference::PRIMARY_PREFERRED, [['data_center' => 'sf']]);
        $result = ServerSelector::select($servers, TopologyType::ReplicaSetWithPrimary, $rp);

        $this->assertCount(1, $result);
        $this->assertSame('a', array_first($result)->host);
    }

    public function testSecondaryPreferredWithNonMatchingTagsFallsBackToPrimary(): void
    {
        // Spec: ReplicaSetWithPrimary/read/SecondaryPreferred_non_matching —
        // No secondary matches the tag; fall back to primary (tags ignored for fallback).
        $servers = self::servers(
            self::sd('a', InternalServerDescription::TYPE_RS_PRIMARY, 26, ['data_center' => 'nyc']),
            self::sd('b', InternalServerDescription::TYPE_RS_SECONDARY, 5, ['data_center' => 'nyc']),
        );

        $rp     = new ReadPreference(ReadPreference::SECONDARY_PREFERRED, [['data_center' => 'sf']]);
        $result = ServerSelector::select($servers, TopologyType::ReplicaSetWithPrimary, $rp);

        $this->assertCount(1, $result);
        $this->assertSame('a', array_first($result)->host);
    }
}
