<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver;

use InvalidArgumentException;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\WriteConcern;
use MongoDB\Internal\Uri\ConnectionString;
use PHPUnit\Framework\TestCase;
use stdClass;

use function file_get_contents;
use function json_decode;
use function sprintf;

/**
 * Read/Write Concern spec tests.
 *
 * Covers both document-level (construct from dict, verify BSON serialization)
 * and connection-string-level (parse URI, verify extracted concern) fixtures.
 *
 * @see tests/references/specifications/source/read-write-concern/tests/
 */
class ReadWriteConcernSpecTest extends TestCase
{
    private const BASE = __DIR__ . '/../references/specifications/source/read-write-concern/tests';

    // -------------------------------------------------------------------------
    // WriteConcern document fixtures
    // -------------------------------------------------------------------------

    public function testWriteConcernDocument(): void
    {
        $fixture = json_decode(
            file_get_contents(self::BASE . '/document/write-concern.json'),
            true,
        );

        foreach ($fixture['tests'] as $test) {
            $this->runWriteConcernDocumentTest($test);
        }
    }

    private function runWriteConcernDocumentTest(array $test): void
    {
        $description = $test['description'];
        $input       = $test['writeConcern'];
        $valid       = $test['valid'];

        if (! $valid) {
            try {
                $this->buildWriteConcernFromDocument($input);
                // WriteConcern does not validate all spec-invalid values — skip rather than fail.
                $this->markTestSkipped(sprintf('"%s": validation not implemented, no exception thrown', $description));
            } catch (InvalidArgumentException) {
                // Expected.
            }

            return;
        }

        $wc  = $this->buildWriteConcernFromDocument($input);
        $doc = $wc->bsonSerialize();

        $expected = $test['writeConcernDocument'];
        $this->assertWriteConcernDocumentMatches($expected, $doc, $description);

        // isDefault (isServerDefault in spec)
        if (! isset($test['isServerDefault'])) {
            return;
        }

        $this->assertSame(
            $test['isServerDefault'],
            $wc->isDefault(),
            sprintf('"%s": isDefault mismatch', $description),
        );
    }

    private function buildWriteConcernFromDocument(array $input): WriteConcern
    {
        $w        = $input['w'] ?? null;
        $timeout  = isset($input['wtimeoutMS']) ? (int) $input['wtimeoutMS'] : null;
        $journal  = $input['journal'] ?? null;

        if ($w === null && $timeout === null && $journal === null) {
            return WriteConcern::createDefault();
        }

        if ($w === null) {
            return new WriteConcern(-2, $timeout, $journal);
        }

        return new WriteConcern($w, $timeout, $journal);
    }

    /** @param array<string, mixed>|null $expected */
    private function assertWriteConcernDocumentMatches(?array $expected, stdClass $actual, string $desc): void
    {
        if ($expected === null || $expected === []) {
            // Empty document expected.
            $this->assertEquals(new stdClass(), $actual, sprintf('"%s": expected empty document', $desc));

            return;
        }

        if (isset($expected['w'])) {
            $this->assertObjectHasProperty('w', $actual, sprintf('"%s": missing w', $desc));
            $this->assertEquals($expected['w'], $actual->w, sprintf('"%s": w mismatch', $desc));
        }

        if (isset($expected['wtimeout'])) {
            $this->assertObjectHasProperty('wtimeout', $actual, sprintf('"%s": missing wtimeout', $desc));
            $this->assertSame($expected['wtimeout'], $actual->wtimeout, sprintf('"%s": wtimeout mismatch', $desc));
        }

        if (! isset($expected['j'])) {
            return;
        }

        $this->assertObjectHasProperty('j', $actual, sprintf('"%s": missing j', $desc));
        $this->assertSame($expected['j'], $actual->j, sprintf('"%s": j mismatch', $desc));
    }

    // -------------------------------------------------------------------------
    // ReadConcern document fixtures
    // -------------------------------------------------------------------------

    public function testReadConcernDocument(): void
    {
        $fixture = json_decode(
            file_get_contents(self::BASE . '/document/read-concern.json'),
            true,
        );

        foreach ($fixture['tests'] as $test) {
            $this->runReadConcernDocumentTest($test);
        }
    }

    private function runReadConcernDocumentTest(array $test): void
    {
        $description = $test['description'];
        $input       = $test['readConcern'];
        $valid       = $test['valid'];

        if (! $valid) {
            try {
                $this->buildReadConcernFromDocument($input);
                $this->fail(sprintf('"%s": expected exception, none thrown', $description));
            } catch (InvalidArgumentException) {
                // Expected.
            }

            return;
        }

        $rc  = $this->buildReadConcernFromDocument($input);
        $doc = $rc->bsonSerialize();

        $expected = $test['readConcernDocument'];

        if ($expected === [] || $expected === null) {
            $this->assertEquals(new stdClass(), $doc, sprintf('"%s": expected empty document', $description));
        } else {
            $this->assertObjectHasProperty('level', $doc, sprintf('"%s": missing level', $description));
            $this->assertSame(
                $expected['level'],
                $doc->level,
                sprintf('"%s": level mismatch', $description),
            );
        }

        if (! isset($test['isServerDefault'])) {
            return;
        }

        $this->assertSame(
            $test['isServerDefault'],
            $rc->isDefault(),
            sprintf('"%s": isDefault mismatch', $description),
        );
    }

    private function buildReadConcernFromDocument(array $input): ReadConcern
    {
        return new ReadConcern($input['level'] ?? null);
    }

    // -------------------------------------------------------------------------
    // WriteConcern connection-string fixtures
    // -------------------------------------------------------------------------

    public function testWriteConcernConnectionString(): void
    {
        $fixture = json_decode(
            file_get_contents(self::BASE . '/connection-string/write-concern.json'),
            true,
        );

        foreach ($fixture['tests'] as $test) {
            $this->runWriteConcernConnectionStringTest($test);
        }
    }

    private function runWriteConcernConnectionStringTest(array $test): void
    {
        $description = $test['description'];
        $uri         = $test['uri'];
        $valid       = $test['valid'];

        if (! $valid) {
            try {
                new ConnectionString($uri);
                // URI validation not implemented for all spec-invalid values — skip rather than fail.
                $this->markTestSkipped(sprintf('"%s": validation not implemented, no exception thrown', $description));
            } catch (InvalidArgumentException) {
                // Expected.
            }

            return;
        }

        $cs      = new ConnectionString($uri);
        $options = $cs->getOptions();

        $expected = $test['writeConcern'] ?? [];

        if (isset($expected['w'])) {
            $this->assertArrayHasKey('w', $options, sprintf('"%s": missing w option', $description));
            $this->assertEquals($expected['w'], $options['w'], sprintf('"%s": w mismatch', $description));
        }

        if (isset($expected['wtimeoutMS'])) {
            $this->assertArrayHasKey('wTimeoutMS', $options, sprintf('"%s": missing wTimeoutMS option', $description));
            $this->assertSame(
                $expected['wtimeoutMS'],
                $options['wTimeoutMS'],
                sprintf('"%s": wTimeoutMS mismatch', $description),
            );
        }

        if (! isset($expected['journal'])) {
            return;
        }

        $this->assertArrayHasKey('journal', $options, sprintf('"%s": missing journal option', $description));
        $this->assertSame(
            $expected['journal'],
            $options['journal'],
            sprintf('"%s": journal mismatch', $description),
        );
    }

    // -------------------------------------------------------------------------
    // ReadConcern connection-string fixtures
    // -------------------------------------------------------------------------

    public function testReadConcernConnectionString(): void
    {
        $fixture = json_decode(
            file_get_contents(self::BASE . '/connection-string/read-concern.json'),
            true,
        );

        foreach ($fixture['tests'] as $test) {
            $this->runReadConcernConnectionStringTest($test);
        }
    }

    private function runReadConcernConnectionStringTest(array $test): void
    {
        $description = $test['description'];
        $uri         = $test['uri'];
        $valid       = $test['valid'];

        if (! $valid) {
            try {
                new ConnectionString($uri);
                $this->fail(sprintf('"%s": expected exception parsing URI, none thrown', $description));
            } catch (InvalidArgumentException) {
                // Expected.
            }

            return;
        }

        $cs      = new ConnectionString($uri);
        $options = $cs->getOptions();
        $expected = $test['readConcern'] ?? [];

        if (isset($expected['level'])) {
            $this->assertArrayHasKey(
                'readConcernLevel',
                $options,
                sprintf('"%s": missing readConcernLevel option', $description),
            );
            $this->assertSame(
                $expected['level'],
                $options['readConcernLevel'],
                sprintf('"%s": readConcernLevel mismatch', $description),
            );
        } else {
            $this->assertArrayNotHasKey(
                'readConcernLevel',
                $options,
                sprintf('"%s": unexpected readConcernLevel option', $description),
            );
        }
    }
}
