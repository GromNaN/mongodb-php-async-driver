<?php

declare(strict_types=1);

namespace MongoDB\Internal\Topology;

use MongoDB\Driver\ReadPreference;

use function array_any;
use function array_filter;
use function array_intersect_assoc;

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
     *  - latency window filter (localThresholdMs)
     *
     * @param array<string, InternalServerDescription> $servers
     *
     * @return InternalServerDescription[]
     */
    public static function select(
        array $servers,
        TopologyType $topologyType,
        ReadPreference $readPreference,
        int $localThresholdMs = 15,
    ): array {
        // ----------------------------------------------------------------
        // Special topology handling
        // ----------------------------------------------------------------

        if ($topologyType === TopologyType::Unknown) {
            // Unknown topology: discovery not yet complete — no server is suitable.
            return [];
        }

        if ($topologyType === TopologyType::Single) {
            // Single topology: return the sole available server regardless of RP.
            return self::filterAvailable($servers);
        }

        if ($topologyType === TopologyType::LoadBalanced) {
            // Load-balanced topology: always exactly one load-balancer entry.
            return self::filterAvailable($servers);
        }

        if ($topologyType === TopologyType::Sharded) {
            // Sharded: mongos servers within the latency window.
            $mongos = self::filterByType($servers, InternalServerDescription::TYPE_MONGOS);

            return self::filterByLatency($mongos, $localThresholdMs);
        }

        // ----------------------------------------------------------------
        // Replica-set / unknown topology — honour the read preference.
        // ----------------------------------------------------------------

        $mode    = $readPreference->getModeString();
        $tagSets = $readPreference->getTagSets();

        switch ($mode) {
            case ReadPreference::PRIMARY:
                return self::filterByType($servers, InternalServerDescription::TYPE_RS_PRIMARY);

            case ReadPreference::PRIMARY_PREFERRED:
                $primaries = self::filterByType($servers, InternalServerDescription::TYPE_RS_PRIMARY);
                if ($primaries !== []) {
                    return $primaries;
                }

                // Fall back to secondaries.
                return self::selectSecondaries($servers, $tagSets, $localThresholdMs);

            case ReadPreference::SECONDARY:
                return self::selectSecondaries($servers, $tagSets, $localThresholdMs);

            case ReadPreference::SECONDARY_PREFERRED:
                $secondaries = self::selectSecondaries($servers, $tagSets, $localThresholdMs);
                if ($secondaries !== []) {
                    return $secondaries;
                }

                // Fall back to primary (ignoring tag sets for the primary fallback).
                return self::filterByType($servers, InternalServerDescription::TYPE_RS_PRIMARY);

            case ReadPreference::NEAREST:
                // All available servers (any type), filtered by tags then latency.
                $available = self::filterAvailable($servers);
                $tagged    = self::filterByTagSets($available, $tagSets);

                return self::filterByLatency($tagged, $localThresholdMs);

            default:
                // Should never happen with a well-constructed ReadPreference.
                return [];
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Return servers whose type is not Unknown (i.e., they responded to hello).
     *
     * @param array<string, InternalServerDescription> $servers
     *
     * @return InternalServerDescription[]
     */
    private static function filterAvailable(array $servers): array
    {
        return array_filter($servers, static fn (InternalServerDescription $sd) => $sd->isAvailable());
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
     * Select secondaries, apply tag-set filtering, then apply the latency window.
     *
     * @param array<string, InternalServerDescription> $servers
     * @param array<array<string, string>>             $tagSets
     *
     * @return InternalServerDescription[]
     */
    private static function selectSecondaries(array $servers, array $tagSets, int $localThresholdMs): array
    {
        $secondaries = self::filterByType($servers, InternalServerDescription::TYPE_RS_SECONDARY);
        $tagged      = self::filterByTagSets($secondaries, $tagSets);

        return self::filterByLatency($tagged, $localThresholdMs);
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
