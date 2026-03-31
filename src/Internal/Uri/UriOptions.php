<?php

declare(strict_types=1);

namespace MongoDB\Internal\Uri;

use Closure;
use InvalidArgumentException;
use MongoDB\Driver\Exception\UnexpectedValueException as DriverUnexpectedValueException;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;
use function ctype_digit;
use function explode;
use function get_debug_type;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function mb_check_encoding;
use function sprintf;
use function trigger_error;

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

    /** Replica-set name */
    public readonly string $replicaSet;

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

    // -------------------------------------------------------------------------
    // Integer options — with defaults
    // -------------------------------------------------------------------------

    public readonly int $serverSelectionTimeoutMS;  // default 30000
    public readonly int $localThresholdMS;           // default 15
    public readonly int $heartbeatFrequencyMS;        // default 10000
    public readonly int $minHeartbeatFrequencyMS;     // default 500
    public readonly int $maxPoolSize;                 // default 100
    public readonly int $minPoolSize;                 // default 0
    public readonly int $waitQueueTimeoutMS;          // default 0

    // -------------------------------------------------------------------------
    // Integer options — optional (no spec default)
    // -------------------------------------------------------------------------

    public readonly int $connectTimeoutMS;
    public readonly int $socketTimeoutMS;
    public readonly int $maxIdleTimeMS;
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
                'replicaSet'            => 'replicaSet',
                'authMechanism'         => 'authMechanism',
                'authSource'            => 'authSource',
                'readPreference'        => 'readPreference',
                'tlsCAFile'             => 'tlsCAFile',
                'tlsCertificateKeyFile' => 'tlsCertificateKeyFile',
            ] as $key => $prop
        ) {
            if (! isset($options[$key])) {
                continue;
            }

            self::assertString($key, $options[$key]);
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
            'waitQueueTimeoutMS'       => 0,
        ];
        foreach ($intDefaults as $key => $default) {
            $value = $options[$key] ?? $default;
            self::assertNonNegativeInt($key, $value);
            self::assignReadonly($self, $key, (int) $value);
        }

        // ----- Optional integer options (only set when present) ---------------
        foreach (
            [
                'connectTimeoutMS',
                'socketTimeoutMS',
                'maxIdleTimeMS',
                'wTimeoutMS',
                'zlibCompressionLevel',
            ] as $key
        ) {
            if (! isset($options[$key])) {
                continue;
            }

            self::assertNonNegativeInt($key, $options[$key]);
            self::assignReadonly($self, $key, (int) $options[$key]);
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
                    sprintf('Option "w" must be a string or integer, got %s.', get_debug_type($w)),
                );
            }

            self::assignReadonly($self, 'w', $w);
        }

        // ----- readPreferenceTags (array) -------------------------------------
        if (isset($options['readPreferenceTags'])) {
            $tags = $options['readPreferenceTags'];
            if (! is_array($tags)) {
                throw new InvalidArgumentException(
                    sprintf('Option "readPreferenceTags" must be an array, got %s.', get_debug_type($tags)),
                );
            }

            self::assignReadonly($self, 'readPreferenceTags', array_values($tags));
        } else {
            self::assignReadonly($self, 'readPreferenceTags', []);
        }

        // ----- compressors (string or array) ----------------------------------
        if (isset($options['compressors'])) {
            $compressors = $options['compressors'];

            if (is_string($compressors)) {
                if (! mb_check_encoding($compressors, 'UTF-8')) {
                    throw new DriverUnexpectedValueException(
                        sprintf('Detected invalid UTF-8 for field path "compressors": %s', $compressors),
                    );
                }

                $compressors = array_filter(
                    array_map('trim', explode(',', $compressors)),
                    static fn ($s) => $s !== '',
                );
            } elseif (! is_array($compressors)) {
                throw new InvalidArgumentException(
                    sprintf('Option "compressors" must be a string or array, got %s.', get_debug_type($compressors)),
                );
            }

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

        // ----- authMechanismProperties (array) --------------------------------
        if (isset($options['authMechanismProperties'])) {
            $props = $options['authMechanismProperties'];
            if (! is_array($props)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Option "authMechanismProperties" must be an array, got %s.',
                        get_debug_type($props),
                    ),
                );
            }

            self::assignReadonly($self, 'authMechanismProperties', $props);
        } else {
            self::assignReadonly($self, 'authMechanismProperties', []);
        }

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
                sprintf('Option "%s" must be a string, got %s.', $key, get_debug_type($value)),
            );
        }
    }

    private static function assertNonNegativeInt(string $key, mixed $value): void
    {
        if (! is_int($value) && ! ctype_digit((string) $value)) {
            throw new InvalidArgumentException(
                sprintf('Option "%s" must be a non-negative integer, got %s.', $key, get_debug_type($value)),
            );
        }

        if ((int) $value < 0) {
            throw new InvalidArgumentException(
                sprintf('Option "%s" must be >= 0, got %d.', $key, (int) $value),
            );
        }
    }

    private static function assertBool(string $key, mixed $value): void
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException(
                sprintf('Option "%s" must be a boolean, got %s.', $key, get_debug_type($value)),
            );
        }
    }
}
