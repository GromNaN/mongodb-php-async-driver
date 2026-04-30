<?php

declare(strict_types=1);

namespace MongoDB\Benchmark;

use MongoDB\Internal\Connection\ClientMetadata;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use ReflectionProperty;

use function hash;
use function serialize;

/**
 * Benchmark ClientMetadata::build() for 1–4 consecutive calls.
 *
 * Two variants:
 *  - "no cache"   (current code): re-builds every call, only $version is cached.
 *  - "with cache" : adds an in-process static array cache keyed by xxh3(appName+driverInfo).
 *
 * The static $version property is reset before each subject so the first
 * InstalledVersions lookup cost is included in every measurement.
 */
#[Revs(500)]
#[Warmup(2)]
#[Iterations(5)]
final class ClientMetadataBench
{
    private ReflectionProperty $versionProp;

    /** Simulated result cache (mirrors what the old implementation had). */
    private static array $resultCache = [];

    public function __construct()
    {
        $this->versionProp = new ReflectionProperty(ClientMetadata::class, 'version');
    }

    private function resetCache(): void
    {
        $this->versionProp->setValue(null, null);
        self::$resultCache = [];
    }

    // -------------------------------------------------------------------------
    // No cache (current behaviour)
    // -------------------------------------------------------------------------

    public function benchNoCacheBuild1Call(): void
    {
        $this->resetCache();
        ClientMetadata::build(null, []);
    }

    public function benchNoCacheBuild2Calls(): void
    {
        $this->resetCache();
        ClientMetadata::build(null, []);
        ClientMetadata::build('myapp', ['name' => 'lib', 'version' => '1.0']);
    }

    public function benchNoCacheBuild3Calls(): void
    {
        $this->resetCache();
        ClientMetadata::build(null, []);
        ClientMetadata::build('myapp', ['name' => 'lib', 'version' => '1.0']);
        ClientMetadata::build('otherapp', ['name' => 'other', 'platform' => 'Symfony/7.0']);
    }

    public function benchNoCacheBuild4Calls(): void
    {
        $this->resetCache();
        ClientMetadata::build(null, []);
        ClientMetadata::build('myapp', ['name' => 'lib', 'version' => '1.0']);
        ClientMetadata::build('otherapp', ['name' => 'other', 'platform' => 'Symfony/7.0']);
        ClientMetadata::build('fourthapp', []);
    }

    // -------------------------------------------------------------------------
    // With result cache (simulates the old memoised behaviour)
    // -------------------------------------------------------------------------

    private function cachedBuild(?string $appName, array $driverInfo): array
    {
        $key = hash('xxh3', serialize([$appName, $driverInfo]));
        if (! isset(self::$resultCache[$key])) {
            self::$resultCache[$key] = ClientMetadata::build($appName, $driverInfo);
        }

        return self::$resultCache[$key];
    }

    public function benchCachedBuild1Call(): void
    {
        $this->resetCache();
        $this->cachedBuild(null, []);
    }

    public function benchCachedBuild2Calls(): void
    {
        $this->resetCache();
        $this->cachedBuild(null, []);
        $this->cachedBuild('myapp', ['name' => 'lib', 'version' => '1.0']);
    }

    public function benchCachedBuild3Calls(): void
    {
        $this->resetCache();
        $this->cachedBuild(null, []);
        $this->cachedBuild('myapp', ['name' => 'lib', 'version' => '1.0']);
        $this->cachedBuild('otherapp', ['name' => 'other', 'platform' => 'Symfony/7.0']);
    }

    public function benchCachedBuild4Calls(): void
    {
        $this->resetCache();
        $this->cachedBuild(null, []);
        $this->cachedBuild('myapp', ['name' => 'lib', 'version' => '1.0']);
        $this->cachedBuild('otherapp', ['name' => 'other', 'platform' => 'Symfony/7.0']);
        $this->cachedBuild('fourthapp', []);
    }
}
