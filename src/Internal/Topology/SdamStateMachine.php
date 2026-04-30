<?php

declare(strict_types=1);

namespace MongoDB\Internal\Topology;

use function array_keys;
use function count;
use function in_array;
use function is_array;
use function max;
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
     * @param int|null                                 $maxSetVersion  Highest setVersion seen from a primary.
     * @param string|null                              $maxElectionId  Highest electionId seen (hex OID string).
     *
     * @return array{type: TopologyType, servers: array<string, InternalServerDescription>, setName: string|null, maxSetVersion: int|null, maxElectionId: string|null}
     */
    public static function applyServerDescription(
        TopologyType $topologyType,
        array $servers,
        InternalServerDescription $newSd,
        ?string $replicaSetName = null,
        ?int $maxSetVersion = null,
        ?string $maxElectionId = null,
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
                // If a replicaSet name was specified in the URI, validate it.
                if (
                    $replicaSetName !== null
                    && $newSd->setName !== null
                    && $newSd->setName !== $replicaSetName
                ) {
                    $servers[$address] = $newSd->withType(InternalServerDescription::TYPE_UNKNOWN);
                } else {
                    $servers[$address] = $newSd;
                }

                break;

            // -----------------------------------------------------------------
            case TopologyType::Sharded:
                [$topologyType, $servers] = self::applyToSharded($servers, $newSd);
                break;

            // -----------------------------------------------------------------
            case TopologyType::ReplicaSetNoPrimary:
            case TopologyType::ReplicaSetWithPrimary:
                [$topologyType, $servers, $replicaSetName, $maxSetVersion, $maxElectionId] = self::applyToReplicaSet(
                    $topologyType,
                    $servers,
                    $newSd,
                    $replicaSetName,
                    $maxSetVersion,
                    $maxElectionId,
                );
                break;

            // -----------------------------------------------------------------
            case TopologyType::LoadBalanced:
                // LoadBalanced topology: always exactly one server, always LoadBalancer type.
                $servers[$address] = $newSd;
                break;
        }

        return [
            'type'           => $topologyType,
            'servers'        => $servers,
            'setName'        => $replicaSetName,
            'maxSetVersion'  => $maxSetVersion,
            'maxElectionId'  => $maxElectionId,
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
                if (count($servers) === 1) {
                    // Only seed — transition to Single topology.
                    $topologyType = TopologyType::Single;
                    $servers      = [$address => $newSd];
                } else {
                    // Multi-server topology: a Standalone cannot coexist with other
                    // servers — remove it and stay Unknown.
                    unset($servers[$address]);
                }

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
                $topologyType      = TopologyType::ReplicaSetNoPrimary;
                $servers[$address] = $newSd;
                $servers = self::addNewRsMembers($servers, $newSd);
                $servers = self::applyPrimaryHint($servers, $newSd);
                if ($newSd->setName !== null && $replicaSetName === null) {
                    $replicaSetName = $newSd->setName;
                }

                break;

            case InternalServerDescription::TYPE_RS_GHOST:
                // Ghost members don't trigger a topology-type transition; stay Unknown.
                $servers[$address] = $newSd;
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
     * @return array{TopologyType, array<string, InternalServerDescription>, string|null, int|null, string|null}
     */
    private static function applyToReplicaSet(
        TopologyType $topologyType,
        array $servers,
        InternalServerDescription $newSd,
        ?string $replicaSetName,
        ?int $maxSetVersion,
        ?string $maxElectionId,
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

            return [$topologyType, $servers, $replicaSetName, $maxSetVersion, $maxElectionId];
        }

        // Capture set name from the first RS member that reports one.
        if ($newSd->setName !== null && $replicaSetName === null) {
            $replicaSetName = $newSd->setName;
        }

        switch ($newSd->type) {
            case InternalServerDescription::TYPE_RS_PRIMARY:
                // Staleness check using setVersion / electionId (SDAM spec §3.6).
                $newSetVersion  = $newSd->helloResponse['setVersion']  ?? null;
                $newElectionOid = $newSd->helloResponse['electionId']['$oid'] ?? null;
                $maxWireVersion = (int) ($newSd->helloResponse['maxWireVersion'] ?? 0);
                $postSixDotZero = $maxWireVersion >= 17;

                if (self::isPrimaryStale($newSetVersion, $newElectionOid, $maxSetVersion, $maxElectionId, $postSixDotZero)) {
                    $servers[$address] = $newSd->withType(InternalServerDescription::TYPE_UNKNOWN);
                    $topologyType = self::checkForPrimary($servers);

                    return [$topologyType, $servers, $replicaSetName, $maxSetVersion, $maxElectionId];
                }

                // Update topology-level maximums.
                if ($newElectionOid !== null) {
                    $maxElectionId = $newElectionOid;
                    // In post-6.0 mode, setVersion always tracks the current primary's setVersion.
                    // In pre-6.0 mode, track the highest setVersion seen.
                    $maxSetVersion = $postSixDotZero
                        ? (int) ($newSetVersion ?? 0)
                        : max($maxSetVersion ?? 0, (int) ($newSetVersion ?? 0));
                } elseif ($newSetVersion !== null) {
                    // No electionId: only update maxSetVersion (never downgrade).
                    $maxSetVersion = max($maxSetVersion ?? 0, (int) $newSetVersion);
                }

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
                $servers[$address] = $newSd;
                // Non-primary RS members may advertise new hosts — seed them
                // as Unknown without removing any existing servers.
                $servers = self::addNewRsMembers($servers, $newSd);
                // If the member reports a primary hint via the "primary" field,
                // mark that server as PossiblePrimary if it is currently Unknown.
                $servers = self::applyPrimaryHint($servers, $newSd);
                break;

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

        return [$topologyType, $servers, $replicaSetName, $maxSetVersion, $maxElectionId];
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
        $address  = $primarySd->getAddress();

        // Collect all members reported by the primary.
        $reported = [];
        foreach (['hosts', 'passives', 'arbiters'] as $key) {
            if (! is_array($response[$key] ?? null)) {
                continue;
            }

            foreach ($response[$key] as $addr) {
                $reported[strtolower((string) $addr)] = true;
            }
        }

        // If the connected address is not in the primary's member lists, the
        // primary is invalid at this address — remove it from the topology.
        // We still process the hosts list to seed newly-discovered members.
        $primaryValid = isset($reported[strtolower($address)]);
        if (! $primaryValid) {
            unset($servers[$address]);
        } else {
            // Ensure the primary itself stays in the reported set.
            $reported[strtolower($address)] = true;
        }

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
     * Add newly-advertised RS members from a non-primary member's hosts list.
     *
     * Unlike {@see updateRsMembersFromPrimary}, this method only *adds* servers
     * and never removes existing ones — only the primary has authority to prune.
     *
     * @param array<string, InternalServerDescription> $servers
     *
     * @return array<string, InternalServerDescription>
     */
    private static function addNewRsMembers(
        array $servers,
        InternalServerDescription $sd,
    ): array {
        $response = $sd->helloResponse;

        foreach (['hosts', 'passives', 'arbiters'] as $key) {
            if (! is_array($response[$key] ?? null)) {
                continue;
            }

            foreach ($response[$key] as $addr) {
                $addr = strtolower((string) $addr);
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
        }

        return $servers;
    }

    /**
     * Determine whether a primary hello response is stale based on setVersion/electionId.
     *
     * Post-6.0 (maxWireVersion >= 17): compare (electionId, setVersion) — electionId first.
     *   - New primary with null electionId is always stale if maxElectionId is set.
     *   - Compare electionId first; setVersion is a tiebreaker only when equal.
     *
     * Pre-6.0 (maxWireVersion < 17): compare (setVersion, electionId) — setVersion first.
     *   - New primary with null electionId: compare setVersion only; accept if >=.
     *   - New primary with electionId: compare (setVersion, electionId) tuple.
     */
    private static function isPrimaryStale(
        mixed $newSetVersion,
        ?string $newElectionOid,
        ?int $maxSetVersion,
        ?string $maxElectionId,
        bool $postSixDotZero,
    ): bool {
        if ($maxSetVersion === null && $maxElectionId === null) {
            return false; // Nothing tracked yet.
        }

        $newSv = (int) ($newSetVersion ?? 0);

        if ($postSixDotZero) {
            // Post-6.0: electionId has precedence.
            if ($maxElectionId !== null) {
                if ($newElectionOid === null) {
                    return true; // Old-style primary in a 6.0+ topology.
                }

                if ($newElectionOid < $maxElectionId) {
                    return true;
                }

                if ($newElectionOid === $maxElectionId) {
                    return $newSv < ($maxSetVersion ?? 0);
                }

                return false; // newElectionId > max → not stale.
            }

            // maxElectionId not set yet; fall back to setVersion comparison.
            return $newSv < ($maxSetVersion ?? 0);
        }

        // Pre-6.0: setVersion has precedence.
        if ($newElectionOid === null) {
            // No electionId in pre-6.0: setVersion is ignored — never stale.
            return false;
        }

        // Has electionId: compare (setVersion, electionId) tuple.
        if ($newSv !== ($maxSetVersion ?? 0)) {
            return $newSv < ($maxSetVersion ?? 0);
        }

        // setVersions equal: compare electionId.
        return $newElectionOid < ($maxElectionId ?? '');
    }

    /**
     * If a non-primary RS member advertises a "primary" field hint, mark that
     * server as PossiblePrimary when it is currently Unknown in the topology.
     *
     * @param array<string, InternalServerDescription> $servers
     *
     * @return array<string, InternalServerDescription>
     */
    private static function applyPrimaryHint(
        array $servers,
        InternalServerDescription $sd,
    ): array {
        $hintedAddr = $sd->helloResponse['primary'] ?? null;
        if ($hintedAddr === null) {
            return $servers;
        }

        $hintedAddr = strtolower((string) $hintedAddr);

        // Add as Unknown first if not yet tracked.
        if (! isset($servers[$hintedAddr])) {
            [$h, $p]            = self::splitAddress($hintedAddr);
            $servers[$hintedAddr] = new InternalServerDescription(
                host: $h,
                port: $p,
                type: InternalServerDescription::TYPE_UNKNOWN,
            );
        }

        // Upgrade Unknown → PossiblePrimary.
        if ($servers[$hintedAddr]->type === InternalServerDescription::TYPE_UNKNOWN) {
            $servers[$hintedAddr] = $servers[$hintedAddr]->withType(
                InternalServerDescription::TYPE_POSSIBLE_PRIMARY,
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
     * Apply an application-level error to the topology (SDAM spec §4.1).
     *
     * Handles network errors, command errors with SDAM-triggering codes, and
     * pool generation tracking.  Timeout errors are always ignored.
     *
     * @param TopologyType                             $topologyType          Current topology type.
     * @param array<string, InternalServerDescription> $servers               Current server map.
     * @param string                                   $address               "host:port" of the server that raised the error.
     * @param array                                    $error                 The applicationError spec entry.
     * @param int                                      $currentPoolGeneration Current pool generation for this server.
     * @param string|null                              $replicaSetName        Known replica-set name.
     * @param int|null                                 $maxSetVersion         Highest setVersion seen from a primary.
     * @param string|null                              $maxElectionId         Highest electionId seen.
     *
     * @return array{type: TopologyType, servers: array<string, InternalServerDescription>, setName: string|null, maxSetVersion: int|null, maxElectionId: string|null, clearPool: bool}
     */
    public static function applyApplicationError(
        TopologyType $topologyType,
        array $servers,
        string $address,
        array $error,
        int $currentPoolGeneration,
        ?string $replicaSetName = null,
        ?int $maxSetVersion = null,
        ?string $maxElectionId = null,
    ): array {
        $noOp = static fn (): array => [
            'type'          => $topologyType,
            'servers'       => $servers,
            'setName'       => $replicaSetName,
            'maxSetVersion' => $maxSetVersion,
            'maxElectionId' => $maxElectionId,
            'clearPool'     => false,
        ];

        // Stale generation check: if the error was produced by a connection
        // from an older pool generation, it is stale and must be ignored.
        if (isset($error['generation']) && (int) $error['generation'] < $currentPoolGeneration) {
            return $noOp();
        }

        $type           = $error['type'];
        $maxWireVersion = (int) ($error['maxWireVersion'] ?? 0);

        // Timeout errors never affect the topology (CSOT spec §4.2).
        if ($type === 'timeout') {
            return $noOp();
        }

        $markUnknown        = false;
        $clearPool          = false;
        $helloResponseUpdate = [];

        if ($type === 'network') {
            // Any network error (before or after handshake) marks the server
            // Unknown and bumps the pool generation.
            $markUnknown = true;
            $clearPool   = true;
        } elseif ($type === 'command') {
            $response = $error['response'] ?? [];
            $ok       = (int) ($response['ok'] ?? 1);

            // ok:1 responses (e.g. with writeErrors) are ignored.
            if ($ok !== 1) {
                $code = isset($response['code']) ? (int) $response['code'] : null;

                // SDAM-triggering error codes (prefer code over errmsg).
                // Only act when the numeric code is in this set; errmsg alone is ignored.
                $sdamCodes = [
                    91,    // ShutdownInProgress
                    189,   // PrimarySteppedDown
                    10058, // LegacyNotPrimary
                    10107, // NotWritablePrimary
                    11600, // InterruptedAtShutdown
                    11602, // InterruptedDueToReplStateChange
                    13435, // NotPrimaryNoSecondaryOk
                    13436, // NotPrimaryOrSecondary
                ];

                // "Node is shutting down" codes always clear the pool regardless
                // of wire version — the server is going away and will not push
                // a new hello via the streaming protocol.
                $shutdownCodes = [91, 11600]; // ShutdownInProgress, InterruptedAtShutdown

                if ($code !== null && in_array($code, $sdamCodes, true)) {
                    if ($maxWireVersion < 8) {
                        // Pre-4.2: mark Unknown and bump pool generation.
                        $markUnknown = true;
                        $clearPool   = true;
                    } else {
                        // Post-4.2: use topologyVersion to determine staleness.
                        // Shutdown codes (91, 11600) still respect the staleness check
                        // but bump the pool generation when non-stale.
                        $errorTv  = $response['topologyVersion'] ?? null;
                        $serverTv = ($servers[$address] ?? null)?->helloResponse['topologyVersion'] ?? null;

                        if (! self::isTopologyVersionStale($errorTv, $serverTv)) {
                            $markUnknown = true;
                            // "Node is shutting down" errors clear the pool even in post-4.2:
                            // the server is going away and will not push a new hello.
                            $clearPool = in_array($code, $shutdownCodes, true);
                            if ($errorTv !== null) {
                                $helloResponseUpdate = ['topologyVersion' => $errorTv];
                            }
                        }
                    }
                }
            }
        }

        if ($markUnknown && isset($servers[$address])) {
            $currentSd = $servers[$address];
            $unknownSd = new InternalServerDescription(
                host:          $currentSd->host,
                port:          $currentSd->port,
                type:          InternalServerDescription::TYPE_UNKNOWN,
                helloResponse: $helloResponseUpdate,
            );

            $result = self::applyServerDescription(
                $topologyType,
                $servers,
                $unknownSd,
                $replicaSetName,
                $maxSetVersion,
                $maxElectionId,
            );

            return [
                'type'          => $result['type'],
                'servers'       => $result['servers'],
                'setName'       => $result['setName'],
                'maxSetVersion' => $result['maxSetVersion'],
                'maxElectionId' => $result['maxElectionId'],
                'clearPool'     => $clearPool,
            ];
        }

        return $noOp();
    }

    /**
     * Return true when a command error's topologyVersion is stale compared to
     * the server's current topologyVersion.
     *
     * "Stale" means the error's counter is NOT strictly greater than the
     * server's counter (with the same processId).  A missing topologyVersion
     * in either the error or the server is always treated as non-stale.
     *
     * @param array|null $errorTv  topologyVersion from the error response.
     * @param mixed      $serverTv topologyVersion from the server's hello response.
     */
    private static function isTopologyVersionStale(mixed $errorTv, mixed $serverTv): bool
    {
        if (! is_array($errorTv) || ! is_array($serverTv)) {
            return false; // Missing TV in either → non-stale.
        }

        $errorPid  = $errorTv['processId']['$oid']  ?? null;
        $serverPid = $serverTv['processId']['$oid'] ?? null;

        if ($errorPid !== $serverPid) {
            return false; // Different processId → non-stale (treat as restart).
        }

        $errorCounter  = (int) ($errorTv['counter']['$numberLong']  ?? 0);
        $serverCounter = (int) ($serverTv['counter']['$numberLong'] ?? 0);

        // Stale when errorCounter is not strictly greater than serverCounter.
        return $errorCounter <= $serverCounter;
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
