<?php

declare(strict_types=1);

namespace MongoDB\Internal\Uri;

use InvalidArgumentException;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_merge;
use function array_search;
use function array_values;
use function count;
use function ctype_digit;
use function ctype_xdigit;
use function explode;
use function filter_var;
use function implode;
use function in_array;
use function rawurldecode;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use function trim;
use function urldecode;

use const FILTER_VALIDATE_INT;

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
    /**
     * Maps canonical camelCase option keys → their lowercase equivalents.
     * Fast-path: isset($key) checks canonical casing in O(1).
     * Slow-path: array_search(strtolower($key)) resolves any other casing.
     */
    private const OPTION_KEYS = [
        'authMechanism'                      => 'authmechanism',
        'authSource'                         => 'authsource',
        'authMechanismProperties'            => 'authmechanismproperties',
        'replicaSet'                         => 'replicaset',
        'connectTimeoutMS'                   => 'connecttimeoutms',
        'socketTimeoutMS'                    => 'sockettimeoutms',
        'serverSelectionTimeoutMS'           => 'serverselectiontimeoutms',
        'localThresholdMS'                   => 'localthresholdms',
        'heartbeatFrequencyMS'               => 'heartbeatfrequencyms',
        'minHeartbeatFrequencyMS'            => 'minheartbeatfrequencyms',
        'maxPoolSize'                        => 'maxpoolsize',
        'minPoolSize'                        => 'minpoolsize',
        'maxIdleTimeMS'                      => 'maxidletimems',
        'waitQueueTimeoutMS'                 => 'waitqueuetimeoutms',
        'w'                                  => 'w',
        'wTimeoutMS'                         => 'wtimeoutms',
        'journal'                            => 'journal',
        'readPreference'                     => 'readpreference',
        'readPreferenceTags'                 => 'readpreferencetags',
        'readConcernLevel'                   => 'readconcernlevel',
        'maxStalenessSeconds'                => 'maxstalenessseconds',
        'ssl'                                => 'ssl',
        'tls'                                => 'tls',
        'tlsCAFile'                          => 'tlscafile',
        'tlsCertificateKeyFile'              => 'tlscertificatekeyfile',
        'tlsAllowInvalidCertificates'        => 'tlsallowinvalidcertificates',
        'tlsAllowInvalidHostnames'           => 'tlsallowinvalidhostnames',
        'compressors'                        => 'compressors',
        'zlibCompressionLevel'               => 'zlibcompressionlevel',
        'retryWrites'                        => 'retrywrites',
        'retryReads'                         => 'retryreads',
        'loadBalanced'                       => 'loadbalanced',
        'directConnection'                   => 'directconnection',
        'timeoutMS'                          => 'timeoutms',
        'safe'                               => 'safe',
        'appname'                            => 'appname',
        'srvMaxHosts'                        => 'srvmaxhosts',
        'srvServiceName'                     => 'srvservicename',
        'tlsInsecure'                        => 'tlsinsecure',
        'tlsDisableOCSPEndpointCheck'        => 'tlsdisableocspendpointcheck',
        'tlsDisableCertificateRevocationCheck' => 'tlsdisablecertificaterevocationcheck',
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
        'maxStalenessSeconds',
        'srvMaxHosts',
    ];

    /** Options whose values are booleans */
    private const BOOL_OPTIONS = [
        'ssl',
        'tls',
        'tlsAllowInvalidCertificates',
        'tlsAllowInvalidHostnames',
        'tlsInsecure',
        'tlsDisableOCSPEndpointCheck',
        'tlsDisableCertificateRevocationCheck',
        'journal',
        'retryWrites',
        'retryReads',
        'loadBalanced',
        'directConnection',
        'safe',
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

    /** @return list<array{host: string, port: int}> */
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

    /** @return array<string, mixed> */
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
                    'Failed to parse MongoDB URI: \'%s\'. Invalid URI, no scheme part specified.',
                    $uri,
                ),
            );
        }

        // --- 2. Extract userinfo (optional, ends at last '@' before the first '/') ---
        $this->username = null;
        $this->password = null;

        // Find the boundary between host list and path/query-string.
        // The slash separating hosts from database is optional when there is no
        // database but query options are present: e.g. mongodb://host?tls=true.
        $slashPos = strpos($rest, '/');
        $qMarkPos = strpos($rest, '?');

        if ($slashPos !== false && ($qMarkPos === false || $slashPos < $qMarkPos)) {
            // Normal case: '/' appears before '?' (or no '?').
            $hostPart  = substr($rest, 0, $slashPos);
            $afterHost = substr($rest, $slashPos + 1);
        } elseif ($qMarkPos !== false) {
            // No '/' before '?': treat '?' as the start of afterHost with no db.
            $hostPart  = substr($rest, 0, $qMarkPos);
            $afterHost = '?' . substr($rest, $qMarkPos + 1);
        } else {
            $hostPart  = $rest;
            $afterHost = '';
        }

        $atPos = strrpos($hostPart, '@');

        // If no '@' was found before the first '/' but one appears in afterHost,
        // the userinfo contains an unescaped '/' which is disallowed.
        if ($atPos === false && strpos($afterHost, '@') !== false) {
            throw new InvalidArgumentException(
                sprintf(
                    "Failed to parse MongoDB URI: '%s'. Userinfo contains unescaped '/' character.",
                    $uri,
                ),
            );
        }

        if ($atPos !== false) {
            $userinfo = substr($hostPart, 0, $atPos);
            $hostPart = substr($hostPart, $atPos + 1);

            // Unescaped '@' characters in userinfo are disallowed — they must be percent-encoded.
            if (strpos($userinfo, '@') !== false) {
                throw new InvalidArgumentException(
                    sprintf(
                        "Failed to parse MongoDB URI: '%s'. Userinfo contains unescaped '@' character.",
                        $uri,
                    ),
                );
            }

            $colonPos = strpos($userinfo, ':');
            if ($colonPos !== false) {
                $rawUser     = substr($userinfo, 0, $colonPos);
                $rawPassword = substr($userinfo, $colonPos + 1);

                // MongoDB requires colons in passwords to be percent-encoded.
                if (strpos($rawPassword, ':') !== false) {
                    throw new InvalidArgumentException(
                        sprintf(
                            "Failed to parse MongoDB URI: '%s'. Password contains unescaped colon.",
                            $uri,
                        ),
                    );
                }

                $this->validatePercentEncoding($rawUser, 'username', $uri);
                $this->validatePercentEncoding($rawPassword, 'password', $uri);
                $this->username = rawurldecode($rawUser);
                $this->password = rawurldecode($rawPassword);
            } else {
                $this->validatePercentEncoding($userinfo, 'username', $uri);
                $this->username = rawurldecode($userinfo);
            }
        }

        // --- 3. Parse hosts ---
        $this->hosts = $this->parseHosts($hostPart, $srv);

        // --- 4. SRV validation: exactly one host, no port ---
        if ($srv) {
            if (count($this->hosts) !== 1) {
                throw new InvalidArgumentException(
                    'An SRV URI must contain exactly one host.',
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
            $this->database = rawurldecode($dbPart);
        }

        // --- 6. Parse and normalize options ---
        $rawOptions = $this->parseQueryString($queryString);
        $this->options = array_merge(self::OPTION_DEFAULTS, $rawOptions);

        // Store SRV flag as private sentinel so getScheme() / isSrv() work.
        $this->options['__srv'] = $srv;

        // --- 7. Semantic validation of URI structure vs options ---
        $this->validateUriStructuralConstraints($srv);
    }

    /**
     * Validate constraints that require both host list and options to be known.
     */
    private function validateUriStructuralConstraints(bool $srv): void
    {
        $directConnection = $this->options['directConnection'] ?? false;
        $loadBalanced     = $this->options['loadBalanced'] ?? false;

        if ($directConnection) {
            if (count($this->hosts) > 1) {
                throw new InvalidArgumentException(
                    sprintf(
                        "Failed to parse MongoDB URI: '%s'. Multiple seeds not allowed with directConnection option.",
                        $this->uri,
                    ),
                );
            }

            if ($srv) {
                throw new InvalidArgumentException(
                    sprintf(
                        "Failed to parse MongoDB URI: '%s'. SRV URI not allowed with directConnection option.",
                        $this->uri,
                    ),
                );
            }
        }

        if ($loadBalanced) {
            if (count($this->hosts) > 1) {
                throw new InvalidArgumentException(
                    sprintf(
                        "Failed to parse MongoDB URI: '%s'. URI with \"loadbalanced\" enabled must not contain more than one host.",
                        $this->uri,
                    ),
                );
            }

            if (isset($this->options['replicaSet'])) {
                throw new InvalidArgumentException(
                    sprintf(
                        "Failed to parse MongoDB URI: '%s'. URI with \"loadbalanced\" enabled must not contain option \"replicaset\".",
                        $this->uri,
                    ),
                );
            }

            if ($directConnection) {
                throw new InvalidArgumentException(
                    sprintf(
                        "Failed to parse MongoDB URI: '%s'. URI with \"loadbalanced\" enabled must not contain option \"directconnection\" enabled.",
                        $this->uri,
                    ),
                );
            }
        }

        // srvMaxHosts validation
        if (isset($this->options['srvMaxHosts'])) {
            if (! $srv) {
                throw new InvalidArgumentException(
                    sprintf(
                        "Failed to parse MongoDB URI: '%s'. srvmaxhosts must not be specified with a non-SRV URI.",
                        $this->uri,
                    ),
                );
            }

            if (isset($this->options['replicaSet'])) {
                throw new InvalidArgumentException(
                    sprintf(
                        "Failed to parse MongoDB URI: '%s'. srvmaxhosts must not be specified with replicaset.",
                        $this->uri,
                    ),
                );
            }

            if ($loadBalanced) {
                throw new InvalidArgumentException(
                    sprintf(
                        "Failed to parse MongoDB URI: '%s'. srvmaxhosts must not be specified with loadbalanced=true.",
                        $this->uri,
                    ),
                );
            }
        }

        // srvServiceName validation
        if (isset($this->options['srvServiceName']) && ! $srv) {
            throw new InvalidArgumentException(
                sprintf(
                    "Failed to parse MongoDB URI: '%s'. srvservicename must not be specified with a non-SRV URI.",
                    $this->uri,
                ),
            );
        }

        // TLS conflict: tlsInsecure cannot be combined with other TLS options
        if (array_key_exists('tlsInsecure', $this->options)) {
            $tlsConflicts = ['tlsAllowInvalidCertificates', 'tlsAllowInvalidHostnames', 'tlsDisableOCSPEndpointCheck', 'tlsDisableCertificateRevocationCheck'];
            foreach ($tlsConflicts as $conflictKey) {
                if (array_key_exists($conflictKey, $this->options)) {
                    throw new InvalidArgumentException(
                        sprintf(
                            "Failed to parse MongoDB URI: '%s'. tlsinsecure may not be specified with tlsallowinvalidcertificates, tlsallowinvalidhostnames, tlsdisableocspendpointcheck, or tlsdisablecertificaterevocationcheck.",
                            $this->uri,
                        ),
                    );
                }
            }
        }

        // TLS conflict: tlsAllowInvalidCertificates cannot be combined with OCSP/revocation options
        if (! array_key_exists('tlsAllowInvalidCertificates', $this->options)) {
            return;
        }

        $tlsConflicts = ['tlsDisableOCSPEndpointCheck', 'tlsDisableCertificateRevocationCheck'];
        foreach ($tlsConflicts as $conflictKey) {
            if (array_key_exists($conflictKey, $this->options)) {
                throw new InvalidArgumentException(
                    sprintf(
                        "Failed to parse MongoDB URI: '%s'. tlsallowinvalidcertificates may not be specified with tlsdisableocspendpointcheck or tlsdisablecertificaterevocationcheck.",
                        $this->uri,
                    ),
                );
            }
        }
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
                    'Empty host entry in MongoDB URI host list.',
                );
            }

            // Handle IPv6 addresses: [::1]:27017 or [::1]
            if (str_starts_with($part, '[')) {
                $closeBracket = strpos($part, ']');
                if ($closeBracket === false) {
                    throw new InvalidArgumentException(
                        sprintf('Malformed IPv6 address in URI host list: "%s"', $part),
                    );
                }

                $host = substr($part, 1, $closeBracket - 1);
                $portStr = substr($part, $closeBracket + 1);

                if ($portStr !== '' && ! str_starts_with($portStr, ':')) {
                    throw new InvalidArgumentException(
                        sprintf('Malformed IPv6 host entry in URI: "%s"', $part),
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
                    sprintf('Empty hostname in MongoDB URI host list entry: "%s"', $part),
                );
            }

            // Decode percent-encoded characters (e.g. %2F in Unix socket paths).
            $host = rawurldecode($host);

            $hosts[] = ['host' => $host, 'port' => $port];
        }

        return $hosts;
    }

    /**
     * Validate that a string contains only well-formed percent-encoded sequences.
     * Every '%' must be followed by exactly two hexadecimal digits.
     */
    private function validatePercentEncoding(string $value, string $context, string $uri): void
    {
        $offset = 0;
        while (($pos = strpos($value, '%', $offset)) !== false) {
            $hex = substr($value, $pos + 1, 2);
            if (strlen($hex) < 2 || ! ctype_xdigit($hex)) {
                throw new InvalidArgumentException(
                    sprintf(
                        "Failed to parse MongoDB URI: '%s'. Invalid percent-encoding in %s.",
                        $uri,
                        $context,
                    ),
                );
            }

            $offset = $pos + 3;
        }
    }

    /**
     * Parse and validate a port string. SRV URIs must not specify a port.
     */
    private function parsePort(string $portStr, string $context, bool $srv): int
    {
        if ($srv) {
            throw new InvalidArgumentException(
                sprintf('SRV URIs must not specify a port. Got "%s".', $context),
            );
        }

        $port = filter_var($portStr, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

        if ($port === false) {
            throw new InvalidArgumentException(
                sprintf('Invalid port "%s" in URI host "%s".', $portStr, $context),
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
                    sprintf('Malformed option in URI query string: "%s"', $pair),
                );
            }

            $rawKey   = urldecode(substr($pair, 0, $eqPos));
            $rawValue = urldecode(substr($pair, $eqPos + 1));

            $canonicalKey = $this->normalizeOptionKey($rawKey);

            try {
                $coercedValue = $this->coerceOptionValue($canonicalKey, $rawKey, $rawValue);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException(
                    sprintf("Failed to parse MongoDB URI: '%s'. %s", $this->uri, $e->getMessage()),
                );
            }

            // null means the option was silently dropped (e.g., empty integer value).
            if ($coercedValue === null) {
                continue;
            }

            // readPreferenceTags may appear multiple times; accumulate into an array of tag sets
            if ($canonicalKey === 'readPreferenceTags') {
                $options[$canonicalKey][] = $coercedValue;
            } else {
                $options[$canonicalKey] = $coercedValue;
            }
        }

        // Validate journal-w conflict in URI
        if (
            isset($options['journal']) && $options['journal'] === true &&
            isset($options['w']) && $options['w'] === 0
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    "Failed to parse MongoDB URI: '%s'. Error while parsing the 'w' URI option: Journal conflicts with w value [w=0].",
                    $this->uri,
                ),
            );
        }

        // Validate readPreference value is a valid mode string
        if (isset($options['readPreference'])) {
            $validModes = ['primary', 'primarypreferred', 'secondary', 'secondarypreferred', 'nearest'];
            if (! in_array(strtolower((string) $options['readPreference']), $validModes, true)) {
                throw new InvalidArgumentException(
                    sprintf(
                        "Failed to parse MongoDB URI: '%s'. Error while assigning URI read preference: Unsupported readPreference value [readPreference=%s].",
                        $this->uri,
                        $options['readPreference'],
                    ),
                );
            }
        }

        // Determine if mode is primary (explicit or default)
        $isPrimary = ! isset($options['readPreference']) ||
            strtolower((string) $options['readPreference']) === 'primary';

        // Validate primary mode + non-empty readPreferenceTags conflict
        if (
            $isPrimary &&
            isset($options['readPreferenceTags']) &&
            ! empty($options['readPreferenceTags']) &&
            ! empty($options['readPreferenceTags'][0])
        ) {
            throw new InvalidArgumentException(
                sprintf("Failed to parse MongoDB URI: '%s'. Invalid readPreferences.", $this->uri),
            );
        }

        // Validate maxStalenessSeconds cannot be used with primary mode (default or explicit)
        if (isset($options['maxStalenessSeconds']) && $isPrimary) {
            throw new InvalidArgumentException(
                sprintf("Failed to parse MongoDB URI: '%s'. Invalid readPreferences.", $this->uri),
            );
        }

        return $options;
    }

    public static function normalizeOptionKey(string $rawKey): string
    {
        // Fast path: $rawKey is already the canonical camelCase key.
        if (isset(self::OPTION_KEYS[$rawKey])) {
            return $rawKey;
        }

        // Slow path: resolve any alternative casing via the lowercase values.
        return array_search(strtolower($rawKey), self::OPTION_KEYS, true) ?: $rawKey;
    }

    /**
     * Coerce a raw string value to the correct PHP type for the given option.
     */
    private function coerceOptionValue(string $key, string $rawKey, string $value): mixed
    {
        if (in_array($key, self::INT_OPTIONS, true)) {
            if ($value === '') {
                // Silently ignore empty-value integer options (spec: emit warning, not error).
                return null;
            }

            if (! ctype_digit($value) && ! (str_starts_with($value, '-') && ctype_digit(substr($value, 1)))) {
                throw new InvalidArgumentException(
                    sprintf('Unsupported value for "%s": "%s".', strtolower($rawKey), $value),
                );
            }

            $intValue = (int) $value;

            // maxStalenessSeconds must fit in 32 bits
            if ($key === 'maxStalenessSeconds' && $intValue > 2147483647) {
                throw new InvalidArgumentException(
                    sprintf('Unsupported value for "%s": "%s".', strtolower($rawKey), $value),
                );
            }

            return $intValue;
        }

        if (in_array($key, self::BOOL_OPTIONS, true)) {
            return match (strtolower($value)) {
                'true', '1', 'yes', 'y', 't'  => true,
                'false', '0', '-1', 'no', 'n', 'f' => false,
                default      => throw new InvalidArgumentException(
                    sprintf('Unsupported value for "%s": "%s".', strtolower($rawKey), $value),
                ),
            };
        }

        // compressors: parse as array
        if ($key === 'compressors') {
            $allowed = ['zlib', 'snappy', 'zstd'];
            $compressors = array_filter(array_map('trim', explode(',', $value)));
            foreach ($compressors as $c) {
                if (! in_array($c, $allowed, true)) {
                    throw new InvalidArgumentException(
                        sprintf('Unknown compressor "%s". Allowed: %s.', $c, implode(', ', $allowed)),
                    );
                }
            }

            return array_values($compressors);
        }

        // readPreferenceTags: parse "key:value,key:value" into associative array (one tag set)
        if ($key === 'readPreferenceTags') {
            if ($value === '') {
                return [];
            }

            $tagSet = [];
            foreach (explode(',', $value) as $pair) {
                $pair = trim($pair);
                if ($pair === '') {
                    continue;
                }

                $colonPos = strpos($pair, ':');
                if ($colonPos === false) {
                    throw new InvalidArgumentException(
                        sprintf('Unsupported value for "%s": "%s".', $rawKey, $value),
                    );
                }

                $tagSet[substr($pair, 0, $colonPos)] = substr($pair, $colonPos + 1);
            }

            return $tagSet;
        }

        // authSource may not be empty
        if ($key === 'authSource' && $value === '') {
            throw new InvalidArgumentException(
                'authSource may not be specified as an empty string.',
            );
        }

        // replicaSet may not be empty
        if ($key === 'replicaSet' && $value === '') {
            throw new InvalidArgumentException(
                'Value for URI option "replicaSet" cannot be empty string.',
            );
        }

        // authMechanismProperties: parse "key:value,key:value" into associative array
        if ($key === 'authMechanismProperties') {
            return $this->parseAuthMechanismProperties($value);
        }

        // w: numeric strings become int, non-numeric strings stay as string
        if ($key === 'w') {
            if (ctype_digit($value) || (str_starts_with($value, '-') && ctype_digit(substr($value, 1)))) {
                return (int) $value;
            }

            return $value;
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
                    sprintf('Malformed authMechanismProperties entry: "%s"', $prop),
                );
            }

            $props[trim(substr($prop, 0, $colonPos))] = trim(substr($prop, $colonPos + 1));
        }

        return $props;
    }
}
