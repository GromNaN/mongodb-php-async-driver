<?php

declare(strict_types=1);

namespace MongoDB\Internal\Uri;

use Closure;
use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Exception\UnexpectedValueException as DriverUnexpectedValueException;

use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_map;
use function array_values;
use function explode;
use function get_debug_type;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function mb_check_encoding;
use function sprintf;
use function strrpos;
use function substr;
use function trigger_error;

use const E_USER_WARNING;

/**
 * Validated, strongly-typed DTO for MongoDB URI options.
 *
 * All properties are populated via {@see UriOptions::fromArray()}.
 * Properties that have no default and were not supplied remain unset
 * (PHP 8.4 uninitialized readonly) — callers should use isset() or
 * rely on the nullable typed ones.
 *
 * @internal
 */
final class UriOptions
{
    // -------------------------------------------------------------------------
    // String options
    // -------------------------------------------------------------------------

    /** MongoDB username */
    public readonly string $username;

    /** MongoDB password (sensitive — not logged) */
    public readonly string $password;

    /** Application name sent in the client metadata handshake */
    public readonly string $appname;

    /** Replica-set name */
    public readonly string $replicaSet;

    /** SRV service name (default "mongodb") */
    public readonly string $srvServiceName;

    /** Auth mechanism name (e.g. SCRAM-SHA-256) */
    public readonly string $authMechanism;

    /** Database used for authentication */
    public readonly string $authSource;

    /** Read preference mode */
    public readonly string $readPreference;

    /** TLS CA certificate file path */
    public readonly string $tlsCAFile;

    /** TLS certificate/key file path */
    public readonly string $tlsCertificateKeyFile;

    /** TLS certificate/key file password */
    public readonly string $tlsCertificateKeyFilePassword;

    // -------------------------------------------------------------------------
    // Integer options — with defaults
    // -------------------------------------------------------------------------

    public readonly int $serverSelectionTimeoutMS;  // default 30000
    public readonly int $localThresholdMS;           // default 15
    public readonly int $heartbeatFrequencyMS;        // default 10000
    public readonly int $minHeartbeatFrequencyMS;     // default 500
    public readonly int $maxPoolSize;                 // default 100
    public readonly int $minPoolSize;                 // default 0
    public readonly int $maxConnecting;               // default 2
    public readonly int $waitQueueTimeoutMS;          // default 0

    // -------------------------------------------------------------------------
    // Integer options — optional (no spec default)
    // -------------------------------------------------------------------------

    public readonly int $connectTimeoutMS;
    public readonly int $socketTimeoutMS;
    public readonly int $maxIdleTimeMS;
    public readonly int $srvMaxHosts;
    public readonly int $wTimeoutMS;
    public readonly int $zlibCompressionLevel;

    // -------------------------------------------------------------------------
    // Nullable integer options
    // -------------------------------------------------------------------------

    public readonly ?int $timeoutMS;

    // -------------------------------------------------------------------------
    // Boolean options — with defaults
    // -------------------------------------------------------------------------

    public readonly bool $retryWrites;       // default true
    public readonly bool $retryReads;        // default true
    public readonly bool $loadBalanced;      // default false
    public readonly bool $directConnection;  // default false

    // -------------------------------------------------------------------------
    // Boolean options — optional
    // -------------------------------------------------------------------------

    public readonly bool $ssl;
    public readonly bool $tls;
    public readonly bool $tlsAllowInvalidCertificates;
    public readonly bool $tlsAllowInvalidHostnames;
    public readonly bool $journal;

    // -------------------------------------------------------------------------
    // Mixed / complex options
    // -------------------------------------------------------------------------

    /** Write concern w value — string (majority/tag) or int */
    public readonly string|int $w;

    /** @var list<string> */
    public readonly array $readPreferenceTags;

    /** @var list<string> Ordered list of enabled compressors */
    public readonly array $compressors;

    /** @var array<string, string> */
    public readonly array $authMechanismProperties;

    /**
     * Pre-built `client` metadata document for the hello handshake.
     * Not a URI option — injected by Manager after construction.
     *
     * @var array{
     *     application?: array{name: string},
     *     driver: array{name: string, version: string},
     *     os: array{type: string},
     *     platform: string,
     * }|null
     */
    public readonly ?array $clientMetadata;

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Build a UriOptions instance from a raw options array (as returned by
     * {@see ConnectionString::getOptions()}).
     *
     * Only recognised keys are mapped; unknown keys are silently ignored so
     * that driver-internal sentinel values (like __srv) cause no errors.
     */
    public static function fromArray(array $options): self
    {
        $self = new self();

        // ----- String options -------------------------------------------------
        foreach (
            [
                'appname'                       => 'appname',
                'username'                      => 'username',
                'password'                      => 'password',
                'replicaSet'                    => 'replicaSet',
                'srvServiceName'                => 'srvServiceName',
                'authMechanism'                 => 'authMechanism',
                'authSource'                    => 'authSource',
                'readPreference'                => 'readPreference',
                'tlsCAFile'                     => 'tlsCAFile',
                'tlsCertificateKeyFile'         => 'tlsCertificateKeyFile',
                'tlsCertificateKeyFilePassword' => 'tlsCertificateKeyFilePassword',
            ] as $key => $prop
        ) {
            if (! isset($options[$key])) {
                continue;
            }

            self::assertString($key, $options[$key]);

            if ($key === 'replicaSet' && $options[$key] === '') {
                throw new InvalidArgumentException(
                    'Value for URI option "replicaSet" cannot be empty string.',
                );
            }

            // @phpstan-ignore-next-line (dynamic readonly assignment via Closure trick)
            self::assignReadonly($self, $prop, (string) $options[$key]);
        }

        // ----- Integer options with defaults ----------------------------------
        $intDefaults = [
            'serverSelectionTimeoutMS' => 30000,
            'localThresholdMS'         => 15,
            'heartbeatFrequencyMS'     => 10000,
            'minHeartbeatFrequencyMS'  => 500,
            'maxPoolSize'              => 100,
            'minPoolSize'              => 0,
            'maxConnecting'            => 2,
            'waitQueueTimeoutMS'       => 0,
        ];
        foreach ($intDefaults as $key => $default) {
            $value = $options[$key] ?? $default;
            self::assertNonNegativeInt($key, $value);
            self::assignReadonly($self, $key, (int) $value);
        }

        // ----- Optional integer options (only set when present) ---------------
        // socketCheckIntervalMS is validated but not stored (no declared property).
        foreach (
            [
                'connectTimeoutMS',
                'socketTimeoutMS',
                'socketCheckIntervalMS',
                'maxIdleTimeMS',
                'srvMaxHosts',
                'zlibCompressionLevel',
            ] as $key
        ) {
            if (! isset($options[$key])) {
                continue;
            }

            self::assertNonNegativeInt($key, $options[$key]);

            if ($key === 'socketCheckIntervalMS') {
                continue;
            }

            self::assignReadonly($self, $key, (int) $options[$key]);
        }

        // ----- wTimeoutMS (specific error messages) ---------------------------
        if (isset($options['wTimeoutMS'])) {
            $wTimeout = $options['wTimeoutMS'];
            if (! is_int($wTimeout)) {
                throw new InvalidArgumentException(
                    sprintf('Expected integer for "wTimeoutMS" URI option, %s given', self::phpTypeName($wTimeout)),
                );
            }

            if ($wTimeout < 0) {
                throw new InvalidArgumentException(
                    sprintf('Expected wtimeoutMS to be >= 0, %d given', $wTimeout),
                );
            }

            self::assignReadonly($self, 'wTimeoutMS', $wTimeout);
        }

        // ----- Nullable integer options ---------------------------------------
        if (array_key_exists('timeoutMS', $options) && $options['timeoutMS'] !== null) {
            self::assertNonNegativeInt('timeoutMS', $options['timeoutMS']);
            self::assignReadonly($self, 'timeoutMS', (int) $options['timeoutMS']);
        } else {
            self::assignReadonly($self, 'timeoutMS', null);
        }

        // ----- Boolean options with defaults ----------------------------------
        $boolDefaults = [
            'retryWrites'      => true,
            'retryReads'       => true,
            'loadBalanced'     => false,
            'directConnection' => false,
        ];
        foreach ($boolDefaults as $key => $default) {
            $value = $options[$key] ?? $default;
            self::assertBool($key, $value);
            self::assignReadonly($self, $key, (bool) $value);
        }

        // ----- Optional boolean options ---------------------------------------
        foreach (
            [
                'ssl',
                'tls',
                'tlsAllowInvalidCertificates',
                'tlsAllowInvalidHostnames',
                'tlsDisableCertificateRevocationCheck',
                'tlsDisableOCSPEndpointCheck',
                'tlsInsecure',
                'serverSelectionTryOnce',
                'journal',
            ] as $key
        ) {
            if (! isset($options[$key])) {
                continue;
            }

            self::assertBool($key, $options[$key]);
            self::assignReadonly($self, $key, (bool) $options[$key]);
        }

        // ----- w (string or int) ----------------------------------------------
        if (isset($options['w'])) {
            $w = $options['w'];
            if (! is_string($w) && ! is_int($w)) {
                throw new InvalidArgumentException(
                    sprintf('Expected 32-bit integer or string for "w" URI option, %s given', self::phpTypeName($w)),
                );
            }

            if (is_int($w) && $w > 2147483647) {
                throw new InvalidArgumentException(
                    'Expected 32-bit integer or string for "w" URI option, 64-bit integer given',
                );
            }

            if (is_int($w) && $w < 0) {
                throw new InvalidArgumentException(
                    sprintf('Unsupported w value: %d', $w),
                );
            }

            self::assignReadonly($self, 'w', $w);
        }

        // ----- readPreferenceTags (array) -------------------------------------
        if (isset($options['readPreferenceTags'])) {
            $tags = $options['readPreferenceTags'];
            if (! is_array($tags)) {
                throw new InvalidArgumentException(
                    sprintf('Expected array for "readPreferenceTags" URI option, %s given', self::phpTypeName($tags)),
                );
            }

            self::assignReadonly($self, 'readPreferenceTags', array_values($tags));
        } else {
            self::assignReadonly($self, 'readPreferenceTags', []);
        }

        // ----- compressors (string only from PHP API) -------------------------
        if (isset($options['compressors'])) {
            $compressors = $options['compressors'];

            if (! is_string($compressors)) {
                throw new InvalidArgumentException(
                    sprintf('Expected string for "compressors" URI option, %s given', self::phpTypeName($compressors)),
                );
            }

            if (! mb_check_encoding($compressors, 'UTF-8')) {
                throw new DriverUnexpectedValueException(
                    sprintf('Detected invalid UTF-8 for field path "compressors": %s', $compressors),
                );
            }

            $compressors = array_filter(
                array_map('trim', explode(',', $compressors)),
                static fn ($s) => $s !== '',
            );

            $supported        = ['snappy', 'zlib', 'zstd'];
            $validCompressors = [];
            foreach ($compressors as $c) {
                if (! in_array($c, $supported, true)) {
                    trigger_error(sprintf("WARNING > Unsupported compressor: '%s'", $c), E_USER_WARNING);
                } else {
                    $validCompressors[] = $c;
                }
            }

            self::assignReadonly($self, 'compressors', $validCompressors);
        } else {
            self::assignReadonly($self, 'compressors', []);
        }

        // ----- authMechanismProperties (associative array or object) ----------
        if (isset($options['authMechanismProperties'])) {
            $props = $options['authMechanismProperties'];
            // Only associative arrays (documents) are accepted; list arrays, scalars,
            // and objects (including BSON types) are rejected to match ext-mongodb.
            if (! is_array($props) || array_is_list($props)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Expected array or object for "authMechanismProperties" URI option, %s given',
                        self::phpTypeName($props),
                    ),
                );
            }

            self::assignReadonly($self, 'authMechanismProperties', $props);
        } else {
            self::assignReadonly($self, 'authMechanismProperties', []);
        }

        // ----- clientMetadata (injected by Manager, not a URI option) ---------
        self::assignReadonly($self, 'clientMetadata', $options['clientMetadata'] ?? null);

        return $self;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Assign a value to a readonly property by temporarily binding a Closure
     * to the object's scope, which is the only portable way in PHP 8.x to
     * initialise uninitialized readonly properties from outside the constructor.
     */
    private static function assignReadonly(self $obj, string $property, mixed $value): void
    {
        $assign = Closure::bind(
            static function (self $o, string $p, mixed $v): void {
                $o->$p = $v;
            },
            null,
            self::class,
        );

        $assign($obj, $property, $value);
    }

    private static function assertString(string $key, mixed $value): void
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('Expected string for "%s" URI option, %s given', $key, self::phpTypeName($value)),
            );
        }
    }

    private static function assertNonNegativeInt(string $key, mixed $value): void
    {
        if (! is_int($value)) {
            throw new InvalidArgumentException(
                sprintf('Expected 32-bit integer for "%s" URI option, %s given', $key, self::phpTypeName($value)),
            );
        }

        if ($value < 0) {
            throw new InvalidArgumentException(
                sprintf('Expected 32-bit integer for "%s" URI option, negative number given', $key),
            );
        }
    }

    private static function assertBool(string $key, mixed $value): void
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException(
                sprintf('Expected boolean for "%s" URI option, %s given', $key, self::phpTypeName($value)),
            );
        }
    }

    /**
     * Return a human-readable PHP type name compatible with ext-mongodb error messages.
     *
     * Maps PHP 8 type names to the names used by libmongoc/ext-mongodb:
     *   float        → "double"
     *   int          → "32-bit integer"
     *   assoc array  → "document"
     *   BSON class   → short class name without namespace (e.g. "ObjectId")
     */

    /** @internal */
    public static function phpTypeName(mixed $value): string
    {
        $type = get_debug_type($value);

        return match ($type) {
            'float' => 'double',
            'int'   => '32-bit integer',
            'bool'  => 'boolean',
            'array' => array_is_list($value) ? 'array' : 'document',
            default => (($pos = strrpos($type, '\\')) !== false) ? substr($type, $pos + 1) : $type,
        };
    }
}
