<?php

declare(strict_types=1);

namespace MongoDB\Tests\Integration;

use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function getenv;
use function iterator_to_array;
use function str_contains;

/**
 * Functional tests for TLS connections.
 *
 * Requires a TLS-enabled MongoDB server and certificate files.
 * Configure via environment variables:
 *   MONGODB_TLS_URI     — MongoDB URI (defaults to MONGODB_URI or localhost:27017)
 *   MONGODB_TLS_CA_FILE — path to CA certificate (required to enable the tests)
 *
 * Example:
 *   MONGODB_TLS_URI="mongodb://localhost:27017,localhost:27018,localhost:27019/?replicaSet=rs" \
 *   MONGODB_TLS_CA_FILE="/path/to/ca.pem" \
 *   tests/run-phpunit.sh --filter TlsConnection
 *
 * @group integration
 */
class TlsConnectionTest extends TestCase
{
    private string $baseUri;
    private string $caFile;

    protected function setUp(): void
    {
        parent::setUp();

        $caFile = getenv('MONGODB_TLS_CA_FILE') ?: '';

        if ($caFile === '' || ! file_exists($caFile)) {
            $this->markTestSkipped(
                'Set MONGODB_TLS_CA_FILE (and optionally MONGODB_TLS_URI) to enable TLS tests.',
            );
        }

        $this->caFile   = $caFile;
        $this->baseUri  = getenv('MONGODB_TLS_URI') ?: (getenv('MONGODB_URI') ?: 'mongodb://127.0.0.1:27017');
    }

    private function makeTlsManager(string $extraOptions = ''): Manager
    {
        $uri = $this->baseUri;

        $separator = str_contains($uri, '?') ? '&' : '/?';
        $uri      .= $separator . 'tls=true&tlsCAFile=' . $this->caFile;

        if ($extraOptions !== '') {
            $uri .= '&' . $extraOptions;
        }

        return new Manager($uri);
    }

    public function testPingOverTls(): void
    {
        $manager = $this->makeTlsManager();
        $cursor  = $manager->executeCommand('admin', new Command(['ping' => 1]));
        $results = iterator_to_array($cursor);

        $this->assertNotEmpty($results);
        $first = (array) $results[0];
        $this->assertSame(1.0, (float) ($first['ok'] ?? 0));
    }

    public function testTlsWithInvalidCertificateAllowed(): void
    {
        $manager = $this->makeTlsManager('tlsAllowInvalidCertificates=true');
        $cursor  = $manager->executeCommand('admin', new Command(['ping' => 1]));
        $results = iterator_to_array($cursor);

        $this->assertNotEmpty($results);
        $first = (array) $results[0];
        $this->assertSame(1.0, (float) ($first['ok'] ?? 0));
    }
}
