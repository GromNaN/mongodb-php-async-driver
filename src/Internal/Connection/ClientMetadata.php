<?php

declare(strict_types=1);

namespace MongoDB\Internal\Connection;

use Composer\InstalledVersions;
use Throwable;

use function file_exists;
use function filter_var;
use function getenv;
use function is_array;
use function is_int;
use function is_string;
use function php_uname;
use function str_starts_with;
use function strlen;
use function substr;

use const FILTER_VALIDATE_INT;
use const PHP_OS_FAMILY;
use const PHP_VERSION;

/**
 * Builds the `client` metadata document included in the MongoDB hello handshake.
 *
 * Implements the full MongoDB Handshake spec:
 * - driver name/version with user-supplied append (separated by '/')
 * - os.type, os.name, os.version, os.architecture
 * - platform (PHP version + optional user platform)
 * - env: FaaS detection (aws.lambda, azure.func, gcp.func, vercel) and
 *        container detection (docker, kubernetes)
 * - 512-byte BSON limit enforced by progressive field omission
 *
 * @internal
 *
 * @see https://github.com/mongodb/specifications/blob/master/source/mongodb-handshake/handshake.md
 */
final class ClientMetadata
{
    private const SEPARATOR   = '/';
    private const PACKAGE     = 'mongodb/async-driver';
    private const DRIVER_NAME = 'async-driver';
    private const MAX_SIZE    = 512;

    private static ?string $version = null;

    /**
     * Build the `client` document for the hello command.
     *
     * Results are memoised in a static cache keyed by an xxh3 hash of
     * $appName and $driverInfo, so repeated calls (e.g. per new connection)
     * return the pre-built document without recomputing.
     *
     * @param array{name?: string, version?: string, platform?: string} $driverInfo
     *
     * @return array{
     *     application?: array{name: string},
     *     driver: array{name: string, version: string},
     *     os: array{type: string, name?: string, version?: string, architecture?: string},
     *     platform?: string,
     *     env?: array{name?: string, timeout_sec?: int, memory_mb?: int, region?: string, container?: array{runtime?: string, orchestrator?: string}},
     * }
     */
    public static function build(?string $appName, array $driverInfo): array
    {
        $meta = [
            'driver'   => [
                'name'    => self::buildDriverName($driverInfo),
                'version' => self::buildDriverVersion($driverInfo),
            ],
            'os'       => self::buildOs(),
            'platform' => self::buildPlatform($driverInfo),
        ];

        if ($appName !== null && $appName !== '') {
            $meta['application'] = ['name' => $appName];
        }

        $env = self::buildEnv();
        if ($env !== null) {
            $meta['env'] = $env;
        }

        return self::enforceLimit($meta);
    }

    // -------------------------------------------------------------------------
    // Builder helpers
    // -------------------------------------------------------------------------

    private static function buildDriverName(array $driverInfo): string
    {
        $name = self::DRIVER_NAME;

        if (isset($driverInfo['name']) && $driverInfo['name'] !== '') {
            $name .= self::SEPARATOR . $driverInfo['name'];
        }

        return $name;
    }

    private static function buildDriverVersion(array $driverInfo): string
    {
        $version = self::getVersion();

        if (isset($driverInfo['version']) && $driverInfo['version'] !== '') {
            $version .= self::SEPARATOR . $driverInfo['version'];
        }

        return $version;
    }

    /** @return array{type: string, name?: string, version?: string, architecture?: string} */
    private static function buildOs(): array
    {
        $os = ['type' => PHP_OS_FAMILY];

        $name = php_uname('s');
        if ($name !== '') {
            $os['name'] = $name;
        }

        $version = php_uname('r');
        if ($version !== '') {
            $os['version'] = $version;
        }

        $arch = php_uname('m');
        if ($arch !== '') {
            $os['architecture'] = $arch;
        }

        return $os;
    }

    private static function buildPlatform(array $driverInfo): string
    {
        $platform = 'PHP ' . PHP_VERSION;

        if (isset($driverInfo['platform']) && $driverInfo['platform'] !== '') {
            $platform .= self::SEPARATOR . $driverInfo['platform'];
        }

        return $platform;
    }

    /**
     * Detect FaaS environment and container runtime per the handshake spec.
     *
     * FaaS: aws.lambda, azure.func, gcp.func, vercel — mutually exclusive
     * (vercel takes precedence over aws.lambda; any other conflict → omit FaaS).
     * Container: docker (/.dockerenv) + kubernetes (KUBERNETES_SERVICE_HOST).
     *
     * Returns null when neither FaaS nor container is detected.
     *
     * @return array{name?: string, timeout_sec?: int, memory_mb?: int, region?: string, container?: array{runtime?: string, orchestrator?: string}}|null
     */
    private static function buildEnv(): ?array
    {
        $faas      = self::detectFaas();
        $container = self::detectContainer();

        if ($faas === null && $container === null) {
            return null;
        }

        $env = [];

        if ($faas !== null) {
            $env = $faas;
        }

        if ($container !== null) {
            $env['container'] = $container;
        }

        return $env !== [] ? $env : null;
    }

    /** @return array{name: string, timeout_sec?: int, memory_mb?: int, region?: string}|null */
    private static function detectFaas(): ?array
    {
        // Detect which FaaS providers are present.
        // AWS_EXECUTION_ENV must start with "AWS_Lambda_" to qualify.
        $awsExecEnv = (string) getenv('AWS_EXECUTION_ENV');
        $isAws      = (str_starts_with($awsExecEnv, 'AWS_Lambda_') && $awsExecEnv !== '')
            || ((string) getenv('AWS_LAMBDA_RUNTIME_API')) !== '';
        $isVercel   = (string) getenv('VERCEL') !== '';
        $isAzure    = (string) getenv('FUNCTIONS_WORKER_RUNTIME') !== '';
        $isGcp      = (string) getenv('K_SERVICE') !== ''
            || ((string) getenv('FUNCTION_NAME')) !== '';

        // Vercel takes precedence over aws.lambda.
        // If more than one distinct provider category is detected, omit all FaaS.
        $awsOrVercel = $isAws || $isVercel ? 1 : 0;
        $detected    = $awsOrVercel + ($isAzure ? 1 : 0) + ($isGcp ? 1 : 0);

        if ($detected !== 1) {
            // None or ambiguous → omit FaaS entirely
            return null;
        }

        if ($isVercel) {
            $env = ['name' => 'vercel'];
            self::setEnvString($env, 'region', 'VERCEL_REGION');

            return $env;
        }

        if ($isAws) {
            $env = ['name' => 'aws.lambda'];
            self::setEnvString($env, 'region', 'AWS_REGION');
            self::setEnvInt($env, 'memory_mb', 'AWS_LAMBDA_FUNCTION_MEMORY_SIZE');

            return $env;
        }

        if ($isGcp) {
            $env = ['name' => 'gcp.func'];
            self::setEnvString($env, 'region', 'FUNCTION_REGION');
            self::setEnvInt($env, 'memory_mb', 'FUNCTION_MEMORY_MB');
            self::setEnvInt($env, 'timeout_sec', 'FUNCTION_TIMEOUT_SEC');

            return $env;
        }

        // Azure: name only, no additional fields
        return ['name' => 'azure.func'];
    }

    /** @return array{runtime?: string, orchestrator?: string}|null */
    private static function detectContainer(): ?array
    {
        $container = [];

        if (file_exists('/.dockerenv')) {
            $container['runtime'] = 'docker';
        }

        if ((string) getenv('KUBERNETES_SERVICE_HOST') !== '') {
            $container['orchestrator'] = 'kubernetes';
        }

        return $container !== [] ? $container : null;
    }

    // -------------------------------------------------------------------------
    // 512-byte limit enforcement (spec §Limitations)
    // -------------------------------------------------------------------------

    /**
     * Progressively strip optional fields until the estimated BSON size of the
     * client document fits within 512 bytes, following the spec-mandated order:
     *
     * 1. Omit fields from `env` except `env.name`
     * 2. Omit fields from `os` except `os.type`
     * 3. Omit `env` entirely
     * 4. Truncate `platform`
     */
    private static function enforceLimit(array $meta): array
    {
        if (self::estimateBsonSize($meta) <= self::MAX_SIZE) {
            return $meta;
        }

        // 1. Keep only env.name (drop region, memory_mb, timeout_sec, container)
        if (isset($meta['env'])) {
            $meta['env'] = isset($meta['env']['name'])
                ? ['name' => $meta['env']['name']]
                : [];
            if ($meta['env'] === []) {
                unset($meta['env']);
            }
        }

        if (self::estimateBsonSize($meta) <= self::MAX_SIZE) {
            return $meta;
        }

        // 2. Keep only os.type
        if (isset($meta['os'])) {
            $meta['os'] = ['type' => $meta['os']['type']];
        }

        if (self::estimateBsonSize($meta) <= self::MAX_SIZE) {
            return $meta;
        }

        // 3. Omit env entirely
        unset($meta['env']);

        if (self::estimateBsonSize($meta) <= self::MAX_SIZE) {
            return $meta;
        }

        // 4. Truncate platform
        if (isset($meta['platform'])) {
            $excess = self::estimateBsonSize($meta) - self::MAX_SIZE;
            $newLen = strlen($meta['platform']) - $excess;
            if ($newLen > 0) {
                $meta['platform'] = substr($meta['platform'], 0, $newLen);
            } else {
                unset($meta['platform']);
            }
        }

        return $meta;
    }

    /**
     * Estimate the BSON encoding size of a document (including the 5-byte
     * document overhead).  Sufficient for the 512-byte limit check.
     */
    private static function estimateBsonSize(array $doc): int
    {
        $size = 5; // int32 length prefix + null terminator

        foreach ($doc as $key => $value) {
            $size += 1 + strlen((string) $key) + 1; // type byte + key + null

            if (is_string($value)) {
                $size += 4 + strlen($value) + 1; // int32 string length + data + null
            } elseif (is_int($value)) {
                $size += 4; // int32
            } elseif (is_array($value)) {
                $size += self::estimateBsonSize($value); // nested document
            }
        }

        return $size;
    }

    // -------------------------------------------------------------------------
    // Env field helpers
    // -------------------------------------------------------------------------

    private static function setEnvString(array &$env, string $field, string $envVar): void
    {
        $val = (string) getenv($envVar);
        if ($val === '') {
            return;
        }

        $env[$field] = $val;
    }

    private static function setEnvInt(array &$env, string $field, string $envVar): void
    {
        $raw = (string) getenv($envVar);
        if ($raw === '') {
            return;
        }

        // Parse strictly: must be an integer string with no trailing garbage,
        // within int32 range [-2^31, 2^31-1].
        $val = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => -2_147_483_648, 'max_range' => 2_147_483_647]]);
        if ($val === false) {
            return;
        }

        $env[$field] = $val;
    }

    // -------------------------------------------------------------------------
    // Version cache
    // -------------------------------------------------------------------------

    private static function getVersion(): string
    {
        if (self::$version === null) {
            try {
                self::$version = InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'unknown';
            } catch (Throwable) {
                self::$version = 'unknown';
            }
        }

        return self::$version;
    }
}
