<?php

declare(strict_types=1);

namespace MongoDB\Internal\Uri;

use InvalidArgumentException;

/**
 * Parses a MongoDB connection string URI.
 *
 * Supported formats:
 *   mongodb://[username:password@]host1[:port1][,host2[:port2],...][/database][?options]
 *   mongodb+srv://[username:password@]host[/database][?options]
 *
 * @internal
 */
final class ConnectionString
{
    private const SCHEME_STANDARD = 'mongodb';
    private const SCHEME_SRV      = 'mongodb+srv';
    private const DEFAULT_PORT    = 27017;

    /** @var list<array{host: string, port: int}> */
    private array $hosts;

    private ?string $username;
    private ?string $password;
    private ?string $database;

    /** @var array<string, mixed> */
    private array $options;

    // ---------------------------------------------------------------------------
    // Option defaults
    // ---------------------------------------------------------------------------

    /** @var array<string, mixed> */
    private const OPTION_DEFAULTS = [
        'serverSelectionTimeoutMS' => 30000,
        'localThresholdMS'         => 15,
        'heartbeatFrequencyMS'     => 10000,
        'minHeartbeatFrequencyMS'  => 500,
        'maxPoolSize'              => 100,
        'minPoolSize'              => 0,
        'waitQueueTimeoutMS'       => 0,
        'retryWrites'              => true,
        'retryReads'               => true,
        'loadBalanced'             => false,
        'directConnection'         => false,
    ];

    /**
     * Canonical option key spellings (all comparisons are case-insensitive).
     */
    private const OPTION_KEYS = [
        'authmechanism'              => 'authMechanism',
        'authsource'                 => 'authSource',
        'authmechanismproperties'    => 'authMechanismProperties',
        'replicaset'                 => 'replicaSet',
        'connecttimeoutms'           => 'connectTimeoutMS',
        'sockettimeoutms'            => 'socketTimeoutMS',
        'serverselectiontimeoutms'   => 'serverSelectionTimeoutMS',
        'localthresholdms'           => 'localThresholdMS',
        'heartbeatfrequencyms'       => 'heartbeatFrequencyMS',
        'minheartbeatfrequencyms'    => 'minHeartbeatFrequencyMS',
        'maxpoolsize'                => 'maxPoolSize',
        'minpoolsize'                => 'minPoolSize',
        'maxidletimems'              => 'maxIdleTimeMS',
        'waitqueuetimeoutms'         => 'waitQueueTimeoutMS',
        'w'                          => 'w',
        'wtimeoutms'                 => 'wTimeoutMS',
        'journal'                    => 'journal',
        'readpreference'             => 'readPreference',
        'readpreferencetags'         => 'readPreferenceTags',
        'ssl'                        => 'ssl',
        'tls'                        => 'tls',
        'tlscafile'                  => 'tlsCAFile',
        'tlscertificatekeyfile'      => 'tlsCertificateKeyFile',
        'tlsallowinvalidcertificates' => 'tlsAllowInvalidCertificates',
        'tlsallowinvalidhostnames'   => 'tlsAllowInvalidHostnames',
        'compressors'                => 'compressors',
        'zlibcompressionlevel'       => 'zlibCompressionLevel',
        'retrywrites'                => 'retryWrites',
        'retryreads'                 => 'retryReads',
        'loadbalanced'               => 'loadBalanced',
        'directconnection'           => 'directConnection',
        'timeoutms'                  => 'timeoutMS',
    ];

    /** Options whose values are integers */
    private const INT_OPTIONS = [
        'connectTimeoutMS',
        'socketTimeoutMS',
        'serverSelectionTimeoutMS',
        'localThresholdMS',
        'heartbeatFrequencyMS',
        'minHeartbeatFrequencyMS',
        'maxPoolSize',
        'minPoolSize',
        'maxIdleTimeMS',
        'waitQueueTimeoutMS',
        'wTimeoutMS',
        'zlibCompressionLevel',
        'timeoutMS',
    ];

    /** Options whose values are booleans */
    private const BOOL_OPTIONS = [
        'ssl',
        'tls',
        'tlsAllowInvalidCertificates',
        'tlsAllowInvalidHostnames',
        'journal',
        'retryWrites',
        'retryReads',
        'loadBalanced',
        'directConnection',
    ];

    public function __construct(private readonly string $uri)
    {
        $this->parse($uri);
    }

    // ---------------------------------------------------------------------------
    // Public accessors
    // ---------------------------------------------------------------------------

    public function getScheme(): string
    {
        return $this->isSrv() ? self::SCHEME_SRV : self::SCHEME_STANDARD;
    }

    /**
     * @return list<array{host: string, port: int}>
     */
    public function getHosts(): array
    {
        return $this->hosts;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getDatabase(): ?string
    {
        return $this->database;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getAuthMechanism(): ?string
    {
        return isset($this->options['authMechanism'])
            ? (string) $this->options['authMechanism']
            : null;
    }

    public function getAuthSource(): ?string
    {
        return isset($this->options['authSource'])
            ? (string) $this->options['authSource']
            : null;
    }

    public function isSrv(): bool
    {
        // Stored in options as a flag during parsing
        return (bool) ($this->options['__srv'] ?? false);
    }

    public function __toString(): string
    {
        return $this->uri;
    }

    // ---------------------------------------------------------------------------
    // Parsing
    // ---------------------------------------------------------------------------

    private function parse(string $uri): void
    {
        // --- 1. Extract scheme ---
        if (str_starts_with($uri, self::SCHEME_SRV . '://')) {
            $srv      = true;
            $rest     = substr($uri, strlen(self::SCHEME_SRV . '://'));
        } elseif (str_starts_with($uri, self::SCHEME_STANDARD . '://')) {
            $srv      = false;
            $rest     = substr($uri, strlen(self::SCHEME_STANDARD . '://'));
        } else {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid MongoDB URI scheme. Expected "mongodb://" or "mongodb+srv://", got: %s',
                    $uri
                )
            );
        }

        // --- 2. Extract userinfo (optional, ends at last '@' before the first '/') ---
        $this->username = null;
        $this->password = null;

        // Find the '@' that separates credentials from hosts.
        // The host portion may not contain '@', so we look for the last '@'
        // before the first '/' (path separator).
        $slashPos = strpos($rest, '/');
        $hostPart = ($slashPos !== false) ? substr($rest, 0, $slashPos) : $rest;
        $afterHost = ($slashPos !== false) ? substr($rest, $slashPos + 1) : '';

        $atPos = strrpos($hostPart, '@');
        if ($atPos !== false) {
            $userinfo = substr($hostPart, 0, $atPos);
            $hostPart = substr($hostPart, $atPos + 1);

            $colonPos = strpos($userinfo, ':');
            if ($colonPos !== false) {
                $this->username = urldecode(substr($userinfo, 0, $colonPos));
                $this->password = urldecode(substr($userinfo, $colonPos + 1));
            } else {
                $this->username = urldecode($userinfo);
            }
        }

        // --- 3. Parse hosts ---
        $this->hosts = $this->parseHosts($hostPart, $srv);

        // --- 4. SRV validation: exactly one host, no port ---
        if ($srv) {
            if (count($this->hosts) !== 1) {
                throw new InvalidArgumentException(
                    'An SRV URI must contain exactly one host.'
                );
            }
            // We recorded the port as default — that is acceptable; but an
            // explicit port in the URI would have triggered an error inside
            // parseHosts() already.
        }

        // --- 5. Separate database and query string from $afterHost ---
        $this->database = null;
        $queryString    = '';

        $qPos = strpos($afterHost, '?');
        if ($qPos !== false) {
            $dbPart      = substr($afterHost, 0, $qPos);
            $queryString = substr($afterHost, $qPos + 1);
        } else {
            $dbPart = $afterHost;
        }

        if ($dbPart !== '') {
            $this->database = urldecode($dbPart);
        }

        // --- 6. Parse and normalize options ---
        $rawOptions = $this->parseQueryString($queryString);
        $this->options = array_merge(self::OPTION_DEFAULTS, $rawOptions);

        // Store SRV flag as private sentinel so getScheme() / isSrv() work.
        $this->options['__srv'] = $srv;
    }

    /**
     * Parse the comma-separated host list.
     *
     * @return list<array{host: string, port: int}>
     */
    private function parseHosts(string $hostStr, bool $srv): array
    {
        if ($hostStr === '') {
            throw new InvalidArgumentException('No hosts specified in MongoDB URI.');
        }

        $parts = explode(',', $hostStr);
        $hosts = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                throw new InvalidArgumentException(
                    'Empty host entry in MongoDB URI host list.'
                );
            }

            // Handle IPv6 addresses: [::1]:27017 or [::1]
            if (str_starts_with($part, '[')) {
                $closeBracket = strpos($part, ']');
                if ($closeBracket === false) {
                    throw new InvalidArgumentException(
                        sprintf('Malformed IPv6 address in URI host list: "%s"', $part)
                    );
                }
                $host = substr($part, 1, $closeBracket - 1);
                $portStr = substr($part, $closeBracket + 1);

                if ($portStr !== '' && !str_starts_with($portStr, ':')) {
                    throw new InvalidArgumentException(
                        sprintf('Malformed IPv6 host entry in URI: "%s"', $part)
                    );
                }

                $port = $portStr !== ''
                    ? $this->parsePort(substr($portStr, 1), $part, $srv)
                    : self::DEFAULT_PORT;
            } else {
                $colonPos = strpos($part, ':');
                if ($colonPos !== false) {
                    $host = substr($part, 0, $colonPos);
                    $port = $this->parsePort(substr($part, $colonPos + 1), $part, $srv);
                } else {
                    $host = $part;
                    $port = self::DEFAULT_PORT;
                }
            }

            if ($host === '') {
                throw new InvalidArgumentException(
                    sprintf('Empty hostname in MongoDB URI host list entry: "%s"', $part)
                );
            }

            $hosts[] = ['host' => $host, 'port' => $port];
        }

        return $hosts;
    }

    /**
     * Parse and validate a port string. SRV URIs must not specify a port.
     */
    private function parsePort(string $portStr, string $context, bool $srv): int
    {
        if ($srv) {
            throw new InvalidArgumentException(
                sprintf('SRV URIs must not specify a port. Got "%s".', $context)
            );
        }

        if (!ctype_digit($portStr) || $portStr === '') {
            throw new InvalidArgumentException(
                sprintf('Invalid port "%s" in URI host "%s".', $portStr, $context)
            );
        }

        $port = (int) $portStr;

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(
                sprintf('Port %d is out of the valid range 1–65535 in URI.', $port)
            );
        }

        return $port;
    }

    /**
     * Parse a URI query string into a normalized options array.
     *
     * @return array<string, mixed>
     */
    private function parseQueryString(string $queryString): array
    {
        if ($queryString === '') {
            return [];
        }

        $options = [];

        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }

            $eqPos = strpos($pair, '=');
            if ($eqPos === false) {
                throw new InvalidArgumentException(
                    sprintf('Malformed option in URI query string: "%s"', $pair)
                );
            }

            $rawKey   = urldecode(substr($pair, 0, $eqPos));
            $rawValue = urldecode(substr($pair, $eqPos + 1));

            $canonicalKey = $this->normalizeOptionKey($rawKey);

            $options[$canonicalKey] = $this->coerceOptionValue($canonicalKey, $rawValue);
        }

        return $options;
    }

    /**
     * Map a raw (possibly differently-cased) option key to its canonical form.
     */
    private function normalizeOptionKey(string $rawKey): string
    {
        $lower = strtolower($rawKey);

        return self::OPTION_KEYS[$lower] ?? $rawKey;
    }

    /**
     * Coerce a raw string value to the correct PHP type for the given option.
     */
    private function coerceOptionValue(string $key, string $value): mixed
    {
        if (in_array($key, self::INT_OPTIONS, true)) {
            if (!ctype_digit($value) && !(str_starts_with($value, '-') && ctype_digit(substr($value, 1)))) {
                throw new InvalidArgumentException(
                    sprintf('Option "%s" must be an integer, got "%s".', $key, $value)
                );
            }
            return (int) $value;
        }

        if (in_array($key, self::BOOL_OPTIONS, true)) {
            return match (strtolower($value)) {
                'true', '1'  => true,
                'false', '0' => false,
                default      => throw new InvalidArgumentException(
                    sprintf('Option "%s" must be a boolean (true/false), got "%s".', $key, $value)
                ),
            };
        }

        // compressors: parse as array
        if ($key === 'compressors') {
            $allowed = ['zlib', 'snappy', 'zstd'];
            $compressors = array_filter(array_map('trim', explode(',', $value)));
            foreach ($compressors as $c) {
                if (!in_array($c, $allowed, true)) {
                    throw new InvalidArgumentException(
                        sprintf('Unknown compressor "%s". Allowed: %s.', $c, implode(', ', $allowed))
                    );
                }
            }
            return array_values($compressors);
        }

        // readPreferenceTags: accumulate as array of tag sets
        if ($key === 'readPreferenceTags') {
            return $value === '' ? [] : array_map('trim', explode(',', $value));
        }

        // authMechanismProperties: parse "key:value,key:value" into associative array
        if ($key === 'authMechanismProperties') {
            return $this->parseAuthMechanismProperties($value);
        }

        return $value;
    }

    /**
     * Parse authMechanismProperties string "key:value,key:value" into an array.
     *
     * @return array<string, string>
     */
    private function parseAuthMechanismProperties(string $value): array
    {
        $props = [];
        foreach (explode(',', $value) as $prop) {
            $prop = trim($prop);
            if ($prop === '') {
                continue;
            }
            $colonPos = strpos($prop, ':');
            if ($colonPos === false) {
                throw new InvalidArgumentException(
                    sprintf('Malformed authMechanismProperties entry: "%s"', $prop)
                );
            }
            $props[trim(substr($prop, 0, $colonPos))] = trim(substr($prop, $colonPos + 1));
        }
        return $props;
    }
}
