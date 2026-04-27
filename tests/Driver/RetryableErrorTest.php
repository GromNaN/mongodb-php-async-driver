<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver;

use InvalidArgumentException;
use MongoDB\Driver\Exception\CommandException;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use MongoDB\Internal\Operation\RetryableError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function sprintf;

class RetryableErrorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // ConnectionException variants
    // -------------------------------------------------------------------------

    public function testConnectionExceptionIsRetryable(): void
    {
        $e = new ConnectionException('connection refused', 0);

        $this->assertTrue(RetryableError::isRetryable($e));
    }

    public function testConnectionTimeoutExceptionIsRetryable(): void
    {
        $e = new ConnectionTimeoutException('timed out', 0);

        $this->assertTrue(RetryableError::isRetryable($e));
    }

    // -------------------------------------------------------------------------
    // CommandException with retryable error codes
    // -------------------------------------------------------------------------

    /** @dataProvider provideRetryableErrorCodes */
    public function testCommandExceptionWithRetryableCodeIsRetryable(int $code): void
    {
        $e = new CommandException('error', $code, (object) ['ok' => 0, 'errmsg' => 'error', 'code' => $code]);

        $this->assertTrue(RetryableError::isRetryable($e), sprintf('Expected code %d to be retryable', $code));
    }

    /** @return array<string, array{int}> */
    public static function provideRetryableErrorCodes(): array
    {
        return [
            'code 6'     => [6],
            'code 7'     => [7],
            'code 89'    => [89],
            'code 91'    => [91],
            'code 134'   => [134],
            'code 189'   => [189],
            'code 262'   => [262],
            'code 9001'  => [9001],
            'code 10107' => [10107],
            'code 11600' => [11600],
            'code 11602' => [11602],
            'code 13435' => [13435],
            'code 13436' => [13436],
        ];
    }

    // -------------------------------------------------------------------------
    // CommandException with non-retryable error codes
    // -------------------------------------------------------------------------

    /** @dataProvider provideNonRetryableErrorCodes */
    public function testCommandExceptionWithNonRetryableCodeIsNotRetryable(int $code): void
    {
        $e = new CommandException('error', $code, (object) ['ok' => 0, 'errmsg' => 'error', 'code' => $code]);

        $this->assertFalse(RetryableError::isRetryable($e), sprintf('Expected code %d to be non-retryable', $code));
    }

    /** @return array<string, array{int}> */
    public static function provideNonRetryableErrorCodes(): array
    {
        return [
            'code 1'   => [1],
            'code 50'  => [50],
            'code 999' => [999],
        ];
    }

    // -------------------------------------------------------------------------
    // CommandException with errorLabels
    // -------------------------------------------------------------------------

    public function testCommandExceptionWithRetryableWriteErrorLabelIsRetryable(): void
    {
        $doc = (object) [
            'ok'          => 0,
            'errmsg'      => 'error',
            'code'        => 999,
            'errorLabels' => ['RetryableWriteError'],
        ];
        $e = new CommandException('error', 999, $doc);

        $this->assertTrue(RetryableError::isRetryable($e));
    }

    public function testCommandExceptionWithRetryableErrorLabelIsRetryable(): void
    {
        $doc = (object) [
            'ok'          => 0,
            'errmsg'      => 'error',
            'code'        => 999,
            'errorLabels' => ['RetryableError'],
        ];
        $e = new CommandException('error', 999, $doc);

        $this->assertTrue(RetryableError::isRetryable($e));
    }

    // -------------------------------------------------------------------------
    // Generic exceptions
    // -------------------------------------------------------------------------

    public function testRuntimeExceptionIsNotRetryable(): void
    {
        $e = new RuntimeException('unexpected error');

        $this->assertFalse(RetryableError::isRetryable($e));
    }

    public function testInvalidArgumentExceptionIsNotRetryable(): void
    {
        $e = new InvalidArgumentException('bad argument');

        $this->assertFalse(RetryableError::isRetryable($e));
    }
}
