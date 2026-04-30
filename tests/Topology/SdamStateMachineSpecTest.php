<?php

declare(strict_types=1);

namespace MongoDB\Tests\Topology;

use MongoDB\Internal\Topology\InternalServerDescription;
use MongoDB\Internal\Topology\SdamStateMachine;
use MongoDB\Internal\Topology\TopologyType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_fill_keys;
use function array_key_exists;
use function array_keys;
use function basename;
use function count;
use function explode;
use function file_get_contents;
use function glob;
use function json_decode;
use function parse_str;
use function parse_url;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * SDAM state-machine spec tests.
 *
 * Drives all JSON fixtures from the Server Discovery and Monitoring spec to
 * verify that SdamStateMachine::applyServerDescription() and
 * SdamStateMachine::applyApplicationError() produce the correct topology state
 * after processing each phase's hello responses or application errors.
 *
 * @see tests/references/specifications/source/server-discovery-and-monitoring/tests/
 */
class SdamStateMachineSpecTest extends TestCase
{
    private const TOPOLOGY_MAP = [
        'Unknown'                => TopologyType::Unknown,
        'Single'                 => TopologyType::Single,
        'Sharded'                => TopologyType::Sharded,
        'ReplicaSetWithPrimary'  => TopologyType::ReplicaSetWithPrimary,
        'ReplicaSetNoPrimary'    => TopologyType::ReplicaSetNoPrimary,
        'LoadBalanced'           => TopologyType::LoadBalanced,
    ];

    /** @return array<string, array{string}> */
    public static function provideSpecFixtures(): array
    {
        $cases = [];
        $base  = __DIR__ . '/../references/specifications/source/server-discovery-and-monitoring/tests';

        foreach (glob($base . '/**/*.json') as $file) {
            // Skip monitoring/ and unified/ sub-directories (different format).
            if (str_contains($file, '/monitoring/') || str_contains($file, '/unified/')) {
                continue;
            }

            if (preg_match('@/tests/([^/]+)/([^/]+)\.json$@', $file, $m)) {
                $name = sprintf('%s/%s', $m[1], $m[2]);
            } else {
                $name = basename($file, '.json');
            }

            $cases[$name] = [$file];
        }

        return $cases;
    }

    #[DataProvider('provideSpecFixtures')]
    public function testSdamStateMachineSpec(string $fixtureFile): void
    {
        $data = json_decode(file_get_contents($fixtureFile), true);

        // Determine initial topology type and seeds from the URI.
        [$topologyType, $servers, $setName] = $this->initFromUri($data['uri']);
        $maxSetVersion   = null;
        $maxElectionId   = null;
        $poolGenerations = array_fill_keys(array_keys($servers), 0);

        foreach ($data['phases'] as $phase) {
            if (array_key_exists('responses', $phase)) {
                foreach ($phase['responses'] as [$address, $helloDoc]) {
                    if (str_starts_with($address, '[')) {
                        // IPv6 bracket notation: [::1]:27017
                        $close = strpos($address, ']');
                        $host  = substr($address, 0, $close + 1);
                        $port  = (int) substr($address, $close + 2);
                    } else {
                        [$host, $portStr] = explode(':', $address);
                        $port = (int) $portStr;
                    }

                    // A null or ok:0 response marks the server Unknown.
                    if (! $helloDoc || ($helloDoc['ok'] ?? 1) !== 1) {
                        $sd = new InternalServerDescription(
                            host: $host,
                            port: $port,
                            type: InternalServerDescription::TYPE_UNKNOWN,
                        );
                    } else {
                        $sd = InternalServerDescription::fromHello($host, $port, $helloDoc, 0);
                    }

                    $result        = SdamStateMachine::applyServerDescription($topologyType, $servers, $sd, $setName, $maxSetVersion, $maxElectionId);
                    $topologyType  = $result['type'];
                    $servers       = $result['servers'];
                    $setName       = $result['setName'];
                    $maxSetVersion = $result['maxSetVersion'];
                    $maxElectionId = $result['maxElectionId'];
                }
            } elseif (array_key_exists('applicationErrors', $phase)) {
                foreach ($phase['applicationErrors'] as $error) {
                    $address    = strtolower($error['address']);
                    $currentGen = $poolGenerations[$address] ?? 0;

                    $result = SdamStateMachine::applyApplicationError(
                        $topologyType,
                        $servers,
                        $address,
                        $error,
                        $currentGen,
                        $setName,
                        $maxSetVersion,
                        $maxElectionId,
                    );

                    $topologyType  = $result['type'];
                    $servers       = $result['servers'];
                    $setName       = $result['setName'];
                    $maxSetVersion = $result['maxSetVersion'];
                    $maxElectionId = $result['maxElectionId'];

                    if (! $result['clearPool']) {
                        continue;
                    }

                    $poolGenerations[$address] = $currentGen + 1;
                }
            }

            // Initialize pool generation counter for newly-discovered servers.
            foreach (array_keys($servers) as $addr) {
                if (isset($poolGenerations[$addr])) {
                    continue;
                }

                $poolGenerations[$addr] = 0;
            }

            $outcome = $phase['outcome'];

            // Assert topology type.
            $expectedType = self::TOPOLOGY_MAP[$outcome['topologyType']] ?? null;
            if ($expectedType !== null) {
                $this->assertSame(
                    $expectedType,
                    $topologyType,
                    sprintf('%s: topologyType', basename($fixtureFile)),
                );
            }

            // Assert set name.
            if (array_key_exists('setName', $outcome)) {
                $this->assertSame(
                    $outcome['setName'],
                    $setName,
                    sprintf('%s: setName', basename($fixtureFile)),
                );
            }

            // Assert individual server types and pool generations.
            foreach ($outcome['servers'] as $addr => $expectedServer) {
                $this->assertArrayHasKey(
                    $addr,
                    $servers,
                    sprintf('%s: server %s should exist in topology', basename($fixtureFile), $addr),
                );
                $this->assertSame(
                    $expectedServer['type'],
                    $servers[$addr]->type,
                    sprintf('%s: server %s type', basename($fixtureFile), $addr),
                );
                if (! isset($expectedServer['pool']['generation'])) {
                    continue;
                }

                $this->assertSame(
                    $expectedServer['pool']['generation'],
                    $poolGenerations[$addr] ?? 0,
                    sprintf('%s: server %s pool.generation', basename($fixtureFile), $addr),
                );
            }
        }

        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Parse the fixture URI to determine the initial topology type and seed list.
     *
     * @return array{TopologyType, array<string, InternalServerDescription>, string|null}
     */
    private function initFromUri(string $uri): array
    {
        $parsed = parse_url($uri);

        // Extract query string options.
        $query   = $parsed['query'] ?? '';
        $options = [];
        parse_str($query, $options);

        // Normalise option keys to lowercase.
        $opts = [];
        foreach ($options as $k => $v) {
            $opts[strtolower($k)] = $v;
        }

        $replicaSet   = $opts['replicaset']    ?? null;
        $loadBalanced = strtolower((string) ($opts['loadbalanced'] ?? 'false')) === 'true';

        // Multiple hosts may be comma-separated in the raw URI.
        $hostsRaw = $this->extractHosts($uri);

        // For a LoadBalanced topology the spec mandates that seed servers are
        // immediately set to LoadBalancer type (no hello response required).
        $initialServerType = $loadBalanced
            ? InternalServerDescription::TYPE_LOAD_BALANCER
            : InternalServerDescription::TYPE_UNKNOWN;

        $servers = [];
        foreach ($hostsRaw as $hostPort) {
            $hostPort = trim($hostPort);

            if (str_starts_with($hostPort, '[')) {
                // IPv6 bracket notation: [::1] or [::1]:27017
                $closeBracket = strpos($hostPort, ']');
                $h = substr($hostPort, 0, $closeBracket + 1);
                $p = isset($hostPort[$closeBracket + 1]) && $hostPort[$closeBracket + 1] === ':'
                    ? substr($hostPort, $closeBracket + 2)
                    : '27017';
            } elseif (str_contains($hostPort, ':')) {
                [$h, $p] = explode(':', $hostPort, 2);
            } else {
                $h = $hostPort;
                $p = '27017';
            }

            $h                = strtolower($h);
            $address          = sprintf('%s:%s', $h, $p);
            $servers[$address] = new InternalServerDescription(
                host: $h,
                port: (int) $p,
                type: $initialServerType,
            );
        }

        $directConnection = $opts['directconnection'] ?? null;

        // Determine initial topology type.
        // Priority: loadBalanced > directConnection=true > replicaSet > directConnection=false > seeds count.
        if ($loadBalanced) {
            $type = TopologyType::LoadBalanced;
        } elseif ($directConnection === 'true') {
            $type = TopologyType::Single;
        } elseif ($replicaSet !== null) {
            $type = TopologyType::ReplicaSetNoPrimary;
        } elseif ($directConnection === 'false') {
            // directConnection=false without replicaSet → Unknown (even with single host).
            $type = TopologyType::Unknown;
        } elseif (count($servers) === 1) {
            $type = TopologyType::Single;
        } else {
            $type = TopologyType::Unknown;
        }

        return [$type, $servers, $replicaSet];
    }

    /**
     * Extract the comma-separated host list from a mongodb:// URI.
     *
     * @return string[]
     */
    private function extractHosts(string $uri): array
    {
        // Strip scheme and credentials.
        $withoutScheme = (string) preg_replace('~^mongodb://([^@]+@)?~', '', $uri);
        // Strip path and query.
        $authority = explode('/', $withoutScheme, 2)[0];
        $authority = explode('?', $authority, 2)[0];

        return explode(',', $authority);
    }
}
