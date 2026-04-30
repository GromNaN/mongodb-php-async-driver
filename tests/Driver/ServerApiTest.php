<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver;

use MongoDB\Driver\ServerApi;
use MongoDB\Internal\Operation\CommandHelper;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Verifies that CommandHelper::prepareCommand() injects the Stable API fields
 * (apiVersion, apiStrict, apiDeprecationErrors) when a ServerApi object is
 * supplied, and omits them when it is not.
 *
 * The underlying implementation in CommandHelper is already complete; these
 * tests document and lock down the expected behaviour.
 *
 * @see src/Internal/Operation/CommandHelper.php
 */
class ServerApiTest extends TestCase
{
    public function testVersionOnlyInjectsApiVersion(): void
    {
        $serverApi = new ServerApi(ServerApi::V1);

        $result = CommandHelper::prepareCommand(['ping' => 1], 'admin', serverApi: $serverApi);

        $this->assertSame('1', $result['apiVersion']);
        $this->assertArrayNotHasKey('apiStrict', $result);
        $this->assertArrayNotHasKey('apiDeprecationErrors', $result);
    }

    public function testStrictTrueIsInjected(): void
    {
        $serverApi = new ServerApi(ServerApi::V1, strict: true);

        $result = CommandHelper::prepareCommand(['ping' => 1], 'admin', serverApi: $serverApi);

        $this->assertSame('1', $result['apiVersion']);
        $this->assertTrue($result['apiStrict']);
        $this->assertArrayNotHasKey('apiDeprecationErrors', $result);
    }

    public function testStrictFalseIsInjected(): void
    {
        $serverApi = new ServerApi(ServerApi::V1, strict: false);

        $result = CommandHelper::prepareCommand(['ping' => 1], 'admin', serverApi: $serverApi);

        $this->assertSame('1', $result['apiVersion']);
        $this->assertFalse($result['apiStrict']);
    }

    public function testDeprecationErrorsTrueIsInjected(): void
    {
        $serverApi = new ServerApi(ServerApi::V1, deprecationErrors: true);

        $result = CommandHelper::prepareCommand(['ping' => 1], 'admin', serverApi: $serverApi);

        $this->assertSame('1', $result['apiVersion']);
        $this->assertTrue($result['apiDeprecationErrors']);
        $this->assertArrayNotHasKey('apiStrict', $result);
    }

    public function testDeprecationErrorsFalseIsInjected(): void
    {
        $serverApi = new ServerApi(ServerApi::V1, deprecationErrors: false);

        $result = CommandHelper::prepareCommand(['ping' => 1], 'admin', serverApi: $serverApi);

        $this->assertSame('1', $result['apiVersion']);
        $this->assertFalse($result['apiDeprecationErrors']);
    }

    public function testAllFieldsInjectedWhenFullyConfigured(): void
    {
        $serverApi = new ServerApi(ServerApi::V1, strict: true, deprecationErrors: false);

        $result = CommandHelper::prepareCommand(
            ['aggregate' => 'orders', 'pipeline' => [], 'cursor' => new stdClass()],
            'mydb',
            serverApi: $serverApi,
        );

        $this->assertSame('mydb', $result['$db']);
        $this->assertSame('1', $result['apiVersion']);
        $this->assertTrue($result['apiStrict']);
        $this->assertFalse($result['apiDeprecationErrors']);
    }

    public function testNullServerApiOmitsAllApiFields(): void
    {
        $result = CommandHelper::prepareCommand(['ping' => 1], 'admin');

        $this->assertArrayNotHasKey('apiVersion', $result);
        $this->assertArrayNotHasKey('apiStrict', $result);
        $this->assertArrayNotHasKey('apiDeprecationErrors', $result);
    }

    public function testApiFieldsAppearsInCommandDocument(): void
    {
        // Verify that the original command key ('ping') is still present and
        // that api fields are appended — the command structure is not destroyed.
        $serverApi = new ServerApi(ServerApi::V1);

        $result = CommandHelper::prepareCommand(['find' => 'col', 'filter' => []], 'testdb', serverApi: $serverApi);

        $this->assertSame('col', $result['find']);
        $this->assertSame('testdb', $result['$db']);
        $this->assertSame('1', $result['apiVersion']);
    }
}
