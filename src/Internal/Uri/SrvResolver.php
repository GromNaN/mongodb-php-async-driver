<?php

declare(strict_types=1);

namespace MongoDB\Internal\Uri;

use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Exception\RuntimeException;

use function array_shift;
use function array_slice;
use function count;
use function dns_get_record;
use function explode;
use function implode;
use function in_array;
use function rtrim;
use function shuffle;
use function sprintf;
use function str_ends_with;
use function strpos;
use function strtolower;
use function substr;
use function trim;

use const DNS_SRV;
use const DNS_TXT;

/**
 * Resolves mongodb+srv:// hostnames via DNS SRV and TXT lookups.
 *
 * Implements the Initial DNS Seedlist Discovery spec:
 * https://www.mongodb.com/docs/manual/reference/connection-string/#dns-seedlist-connection-format
 *
 * @internal
 */
final class SrvResolver
{
    /** @var list<array{host: string, port: int}> */
    public readonly array $hosts;

    /** @var array<string, mixed> Options from TXT record (authSource, replicaSet, loadBalanced) */
    public readonly array $txtOptions;

    private function __construct(array $hosts, array $txtOptions)
    {
        $this->hosts      = $hosts;
        $this->txtOptions = $txtOptions;
    }

    /**
     * Perform SRV + TXT DNS lookups for an mongodb+srv:// hostname.
     *
     * @param string $hostname       The single hostname from the SRV URI (e.g. cluster0.abc.mongodb.net)
     * @param string $srvServiceName The SRV service name (default "mongodb")
     * @param int    $srvMaxHosts    Max number of hosts to use (0 = unlimited)
     *
     * @throws InvalidArgumentException on DNS failure or validation error
     */
    public static function resolve(
        string $hostname,
        string $srvServiceName = 'mongodb',
        int $srvMaxHosts = 0,
    ): self {
        $srvFqdn    = sprintf('_%s._tcp.%s', $srvServiceName, $hostname);
        $srvRecords = @dns_get_record($srvFqdn, DNS_SRV);

        if ($srvRecords === false || count($srvRecords) === 0) {
            throw new RuntimeException(
                sprintf('Failed to resolve SRV record for "%s". No records returned.', $srvFqdn),
            );
        }

        $domainname = self::extractDomainname($hostname);
        $hosts      = [];

        foreach ($srvRecords as $record) {
            $target = rtrim((string) $record['target'], '.');
            self::validateHostDomain($target, $domainname, $hostname);
            $hosts[] = ['host' => $target, 'port' => (int) $record['port']];
        }

        // Apply srvMaxHosts limit using Fisher-Yates (PHP shuffle)
        if ($srvMaxHosts > 0 && count($hosts) > $srvMaxHosts) {
            shuffle($hosts);
            $hosts = array_slice($hosts, 0, $srvMaxHosts);
        }

        $txtOptions = self::resolveTxt($hostname);

        return new self($hosts, $txtOptions);
    }

    /**
     * Extract the domainname portion of a hostname for SRV host validation.
     *
     * - 3+ dot-separated parts: strip first part (subdomain)
     * - 1 or 2 parts: full hostname is the domainname
     */
    private static function extractDomainname(string $hostname): string
    {
        $parts = explode('.', $hostname);

        if (count($parts) >= 3) {
            array_shift($parts);

            return implode('.', $parts);
        }

        return $hostname;
    }

    /**
     * Ensure a returned SRV target belongs to the expected domain.
     *
     * - For hostnames with 3+ parts: target must end with .{domainname}
     * - For shorter hostnames (1-2 parts): target must have at least one more level
     *   (i.e. also end with .{domainname})
     */
    private static function validateHostDomain(string $target, string $domainname, string $srvHostname): void
    {
        $targetLower = strtolower($target);
        $domainLower = strtolower($domainname);

        if (! str_ends_with($targetLower, '.' . $domainLower)) {
            throw new InvalidArgumentException(
                sprintf(
                    'SRV record target "%s" is not a subdomain of "%s".',
                    $target,
                    $srvHostname,
                ),
            );
        }
    }

    /**
     * Query TXT records for the SRV hostname and parse allowed URI options.
     *
     * Only authSource, replicaSet, and loadBalanced are permitted.
     *
     * @return array<string, mixed>
     */
    private static function resolveTxt(string $hostname): array
    {
        $txtRecords = @dns_get_record($hostname, DNS_TXT);

        if ($txtRecords === false || count($txtRecords) === 0) {
            return [];
        }

        if (count($txtRecords) > 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Multiple TXT records found for "%s". Only one TXT record is allowed.',
                    $hostname,
                ),
            );
        }

        // A TXT record may contain multiple strings; concatenate them in order.
        $entries   = $txtRecords[0]['entries'] ?? [$txtRecords[0]['txt'] ?? ''];
        $txtString = implode('', $entries);

        if ($txtString === '') {
            return [];
        }

        $allowed = ['authsource', 'replicaset', 'loadbalanced'];
        $options = [];

        foreach (explode('&', $txtString) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $eqPos = strpos($part, '=');

            if ($eqPos === false) {
                throw new InvalidArgumentException(
                    sprintf('Invalid TXT record option "%s" for host "%s".', $part, $hostname),
                );
            }

            $rawKey   = strtolower(substr($part, 0, $eqPos));
            $rawValue = substr($part, $eqPos + 1);

            if (! in_array($rawKey, $allowed, true)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'TXT record for "%s" contains unsupported option "%s". '
                        . 'Only authSource, replicaSet, and loadBalanced are allowed.',
                        $hostname,
                        $rawKey,
                    ),
                );
            }

            $canonicalKey = match ($rawKey) {
                'authsource'   => 'authSource',
                'replicaset'   => 'replicaSet',
                'loadbalanced' => 'loadBalanced',
            };

            // Coerce loadBalanced to bool; keep the others as strings.
            if ($canonicalKey === 'loadBalanced') {
                $lower = strtolower($rawValue);

                if ($lower !== 'true' && $lower !== 'false') {
                    throw new InvalidArgumentException(
                        sprintf('Invalid value "%s" for TXT record option "loadBalanced".', $rawValue),
                    );
                }

                $options[$canonicalKey] = $lower === 'true';
            } else {
                $options[$canonicalKey] = $rawValue;
            }
        }

        return $options;
    }
}
