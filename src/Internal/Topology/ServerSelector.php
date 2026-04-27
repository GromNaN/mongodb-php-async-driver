<?php

declare(strict_types=1);

namespace MongoDB\Internal\Topology;

use MongoDB\Driver\ReadPreference;

use function array_any;
use function array_diff_key;
use function array_filter;
use function array_flip;
use function array_intersect_assoc;
use function array_rand;
use function array_values;
use function count;

/**
 * Stateless server-selection logic (SDAM spec §Server Selection).
 *
 * All methods are pure functions that operate on the snapshot of topology state
 * passed to them; no I/O or blocking is performed here.
 *
 * @internal
 */
final class ServerSelector
{
    /**
     * Select servers matching a read preference from the current server map.
     *
     * Returns the subset of {@see InternalServerDescription} objects that are
     * eligible for the requested operation, after applying:
     *  - availability filter (type != Unknown)
     *  - mode-specific type filter
     *  - tag-set matching
     *  - deprioritized server exclusion (when non-deprioritized candidates exist)
     *  - latency window filter (localThresholdMs)
     *
     * @param array<string, InternalServerDescription> $servers
     * @param string[]                                 $deprioritizedServers Addresses ("host:port") to deprioritize.
     *
     * @return InternalServerDescription[]
     */
    public static function select(
        array $servers,
        TopologyType $topologyType,
        ReadPreference $readPreference,
        int $localThresholdMs = 15,
        array $deprioritizedServers = [],
        string $operation = 'read',
    ): array {
        $suitable = self::selectSuitable($servers, $topologyType, $readPreference, $deprioritizedServers, $operation);

        // For Single and LoadBalanced, no latency filtering is applied.
        if (
            $topologyType === TopologyType::Single
            || $topologyType === TopologyType::LoadBalanced
            || $topologyType === TopologyType::Unknown
        ) {
            return $suitable;
        }

        return self::filterByLatency($suitable, $localThresholdMs);
    }

    /**
     * Return the set of suitable servers before latency-window filtering.
     *
     * This is the "suitable_servers" stage from the SDAM spec: mode + tag-set
     * filtering is applied, and deprioritized servers are excluded when
     * non-deprioritized candidates are available.
     *
     * @param array<string, InternalServerDescription> $servers
     * @param string[]                                 $deprioritizedServers Addresses ("host:port") to deprioritize.
     *
     * @return InternalServerDescription[]
     */
    public static function selectSuitable(
        array $servers,
        TopologyType $topologyType,
        ReadPreference $readPreference,
        array $deprioritizedServers = [],
        string $operation = 'read',
    ): array {
        // For write operations on replica-set topologies, the spec requires that
        // drivers always target the primary regardless of the read preference.
        if (
            $operation === 'write'
            && ($topologyType === TopologyType::ReplicaSetWithPrimary
                || $topologyType === TopologyType::ReplicaSetNoPrimary)
        ) {
            $readPreference = new ReadPreference(ReadPreference::PRIMARY);
        }

        if ($deprioritizedServers !== []) {
            // Try selection with deprioritized servers removed.
            $deprioritizedIndex = array_flip($deprioritizedServers);
            $filteredServers    = array_diff_key($servers, $deprioritizedIndex);
            $candidates         = self::applySuitableFilter($filteredServers, $topologyType, $readPreference);
            if ($candidates !== []) {
                return $candidates;
            }

            // All candidates were deprioritized — fall back to full server list.
        }

        return self::applySuitableFilter($servers, $topologyType, $readPreference);
    }

    /**
     * Choose a single server from the latency-window candidates using the
     * operationCount-based two-random-picks algorithm (multi-threaded spec §6).
     *
     * Algorithm:
     *  1. If there is only one candidate, return it.
     *  2. Pick two candidates at random (without replacement).
     *  3. Return the one with the lower operationCount; break ties randomly.
     *
     * @param InternalServerDescription[] $candidates      Servers in the latency window.
     * @param array<string, int>          $operationCounts Map of "host:port" → outstanding op count.
     *
     * @return InternalServerDescription|null Null only when $candidates is empty.
     */
    public static function selectInWindow(array $candidates, array $operationCounts = []): ?InternalServerDescription
    {
        $candidates = array_values($candidates);
        $count      = count($candidates);

        if ($count === 0) {
            return null;
        }

        if ($count === 1) {
            return $candidates[0];
        }

        // Pick two distinct indexes at random.
        $keys = (array) array_rand($candidates, 2);
        $a    = $candidates[$keys[0]];
        $b    = $candidates[$keys[1]];

        $opCountA = $operationCounts[$a->getAddress()] ?? 0;
        $opCountB = $operationCounts[$b->getAddress()] ?? 0;

        if ($opCountA < $opCountB) {
            return $a;
        }

        if ($opCountB < $opCountA) {
            return $b;
        }

        // Tie: pick randomly between the two.
        return $candidates[$keys[array_rand($keys)]];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Apply mode and tag-set filtering without latency window.
     *
     * @param array<string, InternalServerDescription> $servers
     *
     * @return InternalServerDescription[]
     */
    private static function applySuitableFilter(
        array $servers,
        TopologyType $topologyType,
        ReadPreference $readPreference,
    ): array {
        if ($topologyType === TopologyType::Unknown) {
            return [];
        }

        if ($topologyType === TopologyType::Single || $topologyType === TopologyType::LoadBalanced) {
            return self::filterAvailable($servers);
        }

        if ($topologyType === TopologyType::Sharded) {
            return array_values(self::filterByType($servers, InternalServerDescription::TYPE_MONGOS));
        }

        $mode    = $readPreference->getModeString();
        $tagSets = $readPreference->getTagSets();

        switch ($mode) {
            case ReadPreference::PRIMARY:
                return array_values(self::filterByType($servers, InternalServerDescription::TYPE_RS_PRIMARY));

            case ReadPreference::PRIMARY_PREFERRED:
                $primaries = self::filterByType($servers, InternalServerDescription::TYPE_RS_PRIMARY);
                if ($primaries !== []) {
                    return array_values($primaries);
                }

                return self::suitableSecondaries($servers, $tagSets);

            case ReadPreference::SECONDARY:
                return self::suitableSecondaries($servers, $tagSets);

            case ReadPreference::SECONDARY_PREFERRED:
                $secondaries = self::suitableSecondaries($servers, $tagSets);
                if ($secondaries !== []) {
                    return $secondaries;
                }

                return array_values(self::filterByType($servers, InternalServerDescription::TYPE_RS_PRIMARY));

            case ReadPreference::NEAREST:
                $available = self::filterAvailable($servers);

                return array_values(self::filterByTagSets($available, $tagSets));

            default:
                return [];
        }
    }

    /**
     * Return servers that are eligible for selection (known, readable types).
     *
     * Excluded: Unknown, RSGhost (cannot participate in reads/writes), and
     * PossiblePrimary (placeholder — not yet confirmed to be primary).
     *
     * @param array<string, InternalServerDescription> $servers
     *
     * @return InternalServerDescription[]
     */
    private static function filterAvailable(array $servers): array
    {
        return array_values(array_filter(
            $servers,
            static fn (InternalServerDescription $sd) => $sd->isAvailable()
                && $sd->type !== InternalServerDescription::TYPE_RS_GHOST
                && $sd->type !== InternalServerDescription::TYPE_POSSIBLE_PRIMARY,
        ));
    }

    /**
     * @param array<string, InternalServerDescription> $servers
     *
     * @return InternalServerDescription[]
     */
    private static function filterByType(array $servers, string $type): array
    {
        return array_filter($servers, static fn (InternalServerDescription $sd) => $sd->type === $type);
    }

    /**
     * Select secondaries matching tag sets (no latency filtering).
     *
     * @param array<string, InternalServerDescription> $servers
     * @param array<array<string, string>>             $tagSets
     *
     * @return InternalServerDescription[]
     */
    private static function suitableSecondaries(array $servers, array $tagSets): array
    {
        $secondaries = self::filterByType($servers, InternalServerDescription::TYPE_RS_SECONDARY);

        return array_values(self::filterByTagSets($secondaries, $tagSets));
    }

    /**
     * Filter servers by the given tag sets.
     *
     * An empty $tagSets list is treated as "match all".
     * A server matches if it satisfies *at least one* tag set (a tag set is
     * satisfied when the server has *all* tags in that set).
     *
     * @param InternalServerDescription>   $server[]
     * @param array<array<string, string>> $tagSets
     *
     * @return InternalServerDescription[]
     */
    private static function filterByTagSets(array $servers, array $tagSets): array
    {
        if ($tagSets === []) {
            return $servers;
        }

        return array_filter(
            $servers,
            static fn (InternalServerDescription $sd) => array_any(
                $tagSets,
                static fn (array $tagSet) => array_intersect_assoc($tagSet, $sd->tags) === $tagSet,
            ),
        );
    }

    /**
     * Apply the latency window: keep only servers within
     *   min(RTT across candidates) + $localThresholdMs
     *
     * Servers with no RTT measurement are excluded.
     *
     * @param InternalServerDescription> $server[]
     *
     * @return InternalServerDescription[]
     */
    private static function filterByLatency(array $servers, int $localThresholdMs): array
    {
        // Single pass to find the minimum RTT (excludes servers with no measurement).
        $minRtt = null;
        foreach ($servers as $sd) {
            if ($sd->roundTripTimeMs === null) {
                continue;
            }

            if ($minRtt !== null && $sd->roundTripTimeMs >= $minRtt) {
                continue;
            }

            $minRtt = $sd->roundTripTimeMs;
        }

        if ($minRtt === null) {
            return $servers; // No RTT data: cannot filter, return all candidates.
        }

        $threshold = $minRtt + $localThresholdMs;

        // Second pass: keep only servers within the latency window.
        $result = [];
        foreach ($servers as $sd) {
            if ($sd->roundTripTimeMs === null || $sd->roundTripTimeMs > $threshold) {
                continue;
            }

            $result[] = $sd;
        }

        return $result;
    }
}
