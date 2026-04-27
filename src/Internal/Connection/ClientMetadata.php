<?php

declare(strict_types=1);

namespace MongoDB\Internal\Connection;

use Composer\InstalledVersions;
use Throwable;

use const PHP_OS_FAMILY;
use const PHP_VERSION;

/**
 * Builds the `client` metadata document included in the MongoDB hello handshake.
 *
 * The document identifies the driver name/version, OS type, and PHP version to
 * the server. User-supplied driver info (from the `driver` key in driverOptions)
 * is appended with a `/` separator, following the same convention used by the
 * mongodb/mongodb PHP library.
 *
 * @internal
 *
 * @see https://github.com/mongodb/specifications/blob/master/source/mongodb-handshake/handshake.rst
 */
final class ClientMetadata
{
    private const SEPARATOR = '/';
    private const PACKAGE   = 'mongodb/async-driver';
    private const DRIVER_NAME = 'async-driver';

    private static ?string $version = null;

    /**
     * Build the `client` document for the hello command.
     *
     * @param array{name?: string, version?: string, platform?: string} $driverInfo
     *
     * @return array{
     *     application?: array{name: string},
     *     driver: array{name: string, version: string},
     *     os: array{type: string},
     *     platform: string,
     * }
     */
    public static function build(?string $appName, array $driverInfo): array
    {
        $meta = [
            'driver'   => [
                'name'    => self::buildDriverName($driverInfo),
                'version' => self::buildDriverVersion($driverInfo),
            ],
            'os'       => ['type' => PHP_OS_FAMILY],
            'platform' => 'PHP ' . PHP_VERSION,
        ];

        if ($appName !== null && $appName !== '') {
            $meta['application'] = ['name' => $appName];
        }

        if (isset($driverInfo['platform'])) {
            $meta['platform'] .= self::SEPARATOR . $driverInfo['platform'];
        }

        return $meta;
    }

    private static function buildDriverName(array $driverInfo): string
    {
        $name = self::DRIVER_NAME;

        if (isset($driverInfo['name'])) {
            $name .= self::SEPARATOR . $driverInfo['name'];
        }

        return $name;
    }

    private static function buildDriverVersion(array $driverInfo): string
    {
        $version = self::getVersion();

        if (isset($driverInfo['version'])) {
            $version .= self::SEPARATOR . $driverInfo['version'];
        }

        return $version;
    }

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
