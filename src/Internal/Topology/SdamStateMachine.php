<?php

declare(strict_types=1);

namespace MongoDB\Internal\Topology;

use function array_keys;
use function is_array;
use function strrpos;
use function strtolower;
use function substr;

/**
 * Pure, stateless SDAM state-transition logic.
 *
 * Given the current topology state and a freshly-observed server description,
 * {@see SdamStateMachine::applyServerDescription()} returns the new topology
 * state — updated server map, topology type, and (for replica sets) the
 * confirmed set name.  No I/O or side-effects are performed here.
 *
 * @internal
 */
final class SdamStateMachine
{
    /**
     * Apply a new server description to the topology and return the updated state.
     *
     * @param TopologyType                             $topologyType   Current topology type.
     * @param array<string, InternalServerDescription> $servers        Current server map keyed by "host:port".
     * @param InternalServerDescription                $newSd          Freshly-observed server description.
     * @param string|null                              $replicaSetName Known replica-set name (if any).
     *
     * @return array{type: TopologyType, servers: array<string, InternalServerDescription>, setName: string|null}
     */
    public static function applyServerDescription(
        TopologyType $topologyType,
        array $servers,
        InternalServerDescription $newSd,
        ?string $replicaSetName = null,
    ): array {
        $address = $newSd->getAddress();

        // Ensure the server is tracked even if we received it out-of-band.
        if (! isset($servers[$address])) {
            $servers[$address] = $newSd;
        }

        switch ($topologyType) {
            // -----------------------------------------------------------------
            case TopologyType::Unknown:
                [$topologyType, $servers, $replicaSetName] = self::applyToUnknown(
                    $topologyType,
                    $servers,
                    $newSd,
                    $replicaSetName,
                );
                break;

            // -----------------------------------------------------------------
            case TopologyType::Single:
                // A single-server topology never changes type; just update that server.
                $servers[$address] = $newSd;
                break;

            // -----------------------------------------------------------------
            case TopologyType::Sharded:
                [$topologyType, $servers] = self::applyToSharded($servers, $newSd);
                break;

            // -----------------------------------------------------------------
            case TopologyType::ReplicaSetNoPrimary:
            case TopologyType::ReplicaSetWithPrimary:
                [$topologyType, $servers, $replicaSetName] = self::applyToReplicaSet(
                    $topologyType,
                    $servers,
                    $newSd,
                    $replicaSetName,
                );
                break;

            // -----------------------------------------------------------------
            case TopologyType::LoadBalanced:
                // LoadBalanced topology: always exactly one server, always LoadBalancer type.
                $servers[$address] = $newSd;
                break;
        }

        return [
            'type'    => $topologyType,
            'servers' => $servers,
            'setName' => $replicaSetName,
        ];
    }

    // =========================================================================
    // Per-topology handlers
    // =========================================================================

    /**
     * @param array<string, InternalServerDescription> $servers
     *
     * @return array{TopologyType, array<string, InternalServerDescription>, string|null}
     */
    private static function applyToUnknown(
        TopologyType $topologyType,
        array $servers,
        InternalServerDescription $newSd,
        ?string $replicaSetName,
    ): array {
        $address = $newSd->getAddress();

        switch ($newSd->type) {
            case InternalServerDescription::TYPE_STANDALONE:
                // Single server — transition to Single topology.
                $topologyType     = TopologyType::Single;
                // Remove all other seeds; a single topology has exactly one server.
                $servers          = [$address => $newSd];
                break;

            case InternalServerDescription::TYPE_MONGOS:
                $topologyType     = TopologyType::Sharded;
                $servers[$address] = $newSd;
                break;

            case InternalServerDescription::TYPE_RS_PRIMARY:
                $replicaSetName    = $newSd->setName;
                $topologyType      = TopologyType::ReplicaSetWithPrimary;
                $servers[$address] = $newSd;
                // Seed the member list from the primary's hello response.
                $servers = self::updateRsMembersFromPrimary($servers, $newSd);
                break;

            case InternalServerDescription::TYPE_RS_SECONDARY:
            case InternalServerDescription::TYPE_RS_ARBITER:
            case InternalServerDescription::TYPE_RS_OTHER:
            case InternalServerDescription::TYPE_RS_GHOST:
                $topologyType      = TopologyType::ReplicaSetNoPrimary;
                $servers[$address] = $newSd;
                if ($newSd->setName !== null && $replicaSetName === null) {
                    $replicaSetName = $newSd->setName;
                }

                break;

            case InternalServerDescription::TYPE_LOAD_BALANCER:
                $topologyType      = TopologyType::LoadBalanced;
                $servers[$address] = $newSd;
                break;

            default:
                // Unknown / PossiblePrimary: update the server, topology stays Unknown.
                $servers[$address] = $newSd;
                break;
        }

        return [$topologyType, $servers, $replicaSetName];
    }

    /**
     * @param array<string, InternalServerDescription> $servers
     *
     * @return array{TopologyType, array<string, InternalServerDescription>}
     */
    private static function applyToSharded(
        array $servers,
        InternalServerDescription $newSd,
    ): array {
        $address = $newSd->getAddress();

        if (
            $newSd->type === InternalServerDescription::TYPE_MONGOS
            || $newSd->type === InternalServerDescription::TYPE_UNKNOWN
        ) {
            $servers[$address] = $newSd;
        } else {
            // Non-mongos server in a sharded topology: mark as Unknown, keep entry.
            $servers[$address] = $newSd->withType(InternalServerDescription::TYPE_UNKNOWN);
        }

        return [TopologyType::Sharded, $servers];
    }

    /**
     * @param array<string, InternalServerDescription> $servers
     *
     * @return array{TopologyType, array<string, InternalServerDescription>, string|null}
     */
    private static function applyToReplicaSet(
        TopologyType $topologyType,
        array $servers,
        InternalServerDescription $newSd,
        ?string $replicaSetName,
    ): array {
        $address = $newSd->getAddress();

        // Set-name mismatch: discard the update (mark server Unknown), no topology change.
        if (
            $newSd->setName !== null
            && $replicaSetName !== null
            && $newSd->setName !== $replicaSetName
        ) {
            $servers[$address] = $newSd->withType(InternalServerDescription::TYPE_UNKNOWN);
            $topologyType = self::checkForPrimary($servers);

            return [$topologyType, $servers, $replicaSetName];
        }

        // Capture set name from the first RS member that reports one.
        if ($newSd->setName !== null && $replicaSetName === null) {
            $replicaSetName = $newSd->setName;
        }

        switch ($newSd->type) {
            case InternalServerDescription::TYPE_RS_PRIMARY:
                $servers[$address] = $newSd;
                $servers = self::updateRsMembersFromPrimary($servers, $newSd);
                // Invalidate any other servers currently claiming to be primary.
                foreach ($servers as $addr => $sd) {
                    if (
                        $addr === $address
                        || $sd->type !== InternalServerDescription::TYPE_RS_PRIMARY
                    ) {
                        continue;
                    }

                    $servers[$addr] = $sd->withType(InternalServerDescription::TYPE_UNKNOWN);
                }

                break;

            case InternalServerDescription::TYPE_RS_SECONDARY:
            case InternalServerDescription::TYPE_RS_ARBITER:
            case InternalServerDescription::TYPE_RS_OTHER:
            case InternalServerDescription::TYPE_RS_GHOST:
            case InternalServerDescription::TYPE_UNKNOWN:
                $servers[$address] = $newSd;
                break;

            default:
                // A non-RS type appeared in an RS topology — mark it Unknown.
                $servers[$address] = $newSd->withType(InternalServerDescription::TYPE_UNKNOWN);
                break;
        }

        $topologyType = self::checkForPrimary($servers);

        return [$topologyType, $servers, $replicaSetName];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Seed the server map from the primary's advertised member lists (hosts,
     * passives, arbiters).  Servers that the primary no longer mentions are
     * removed; newly-mentioned servers are initialised as Unknown.
     *
     * @param array<string, InternalServerDescription> $servers
     *
     * @return array<string, InternalServerDescription>
     */
    private static function updateRsMembersFromPrimary(
        array $servers,
        InternalServerDescription $primarySd,
    ): array {
        $response = $primarySd->helloResponse;

        // Collect all members reported by the primary.
        $reported = [];
        foreach (['hosts', 'passives', 'arbiters'] as $key) {
            if (! isset($response[$key]) || ! is_array($response[$key])) {
                continue;
            }

            foreach ($response[$key] as $addr) {
                $reported[strtolower((string) $addr)] = true;
            }
        }

        // Always include the primary itself.
        $reported[strtolower($primarySd->getAddress())] = true;

        // Remove servers not in the primary's view.
        foreach (array_keys($servers) as $addr) {
            if (isset($reported[strtolower($addr)])) {
                continue;
            }

            unset($servers[$addr]);
        }

        // Add newly-reported servers as Unknown placeholders.
        foreach (array_keys($reported) as $addr) {
            if (isset($servers[$addr])) {
                continue;
            }

            [$h, $p] = self::splitAddress($addr);
            $servers[$addr] = new InternalServerDescription(
                host: $h,
                port: $p,
                type: InternalServerDescription::TYPE_UNKNOWN,
            );
        }

        return $servers;
    }

    /**
     * Determine whether any primary is present and return the appropriate RS type.
     *
     * @param array<string, InternalServerDescription> $servers
     */
    private static function checkForPrimary(array $servers): TopologyType
    {
        foreach ($servers as $sd) {
            if ($sd->type === InternalServerDescription::TYPE_RS_PRIMARY) {
                return TopologyType::ReplicaSetWithPrimary;
            }
        }

        return TopologyType::ReplicaSetNoPrimary;
    }

    /**
     * Split a "host:port" address string into its components.
     *
     * @return array{string, int}
     */
    private static function splitAddress(string $address): array
    {
        $lastColon = strrpos($address, ':');
        if ($lastColon === false) {
            return [$address, 27017];
        }

        $host = substr($address, 0, $lastColon);
        $port = (int) substr($address, $lastColon + 1);

        return [$host, $port ?: 27017];
    }
}
