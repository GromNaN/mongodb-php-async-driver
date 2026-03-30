<?php

declare(strict_types=1);

namespace MongoDB\Tests\Auth;

use MongoDB\Internal\Auth\ScramSha256;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests SCRAM-SHA-256 building blocks using RFC 7677 test vectors where
 * applicable.  Because the cryptographic helpers are private, each test
 * obtains access via ReflectionMethod.
 *
 * RFC 7677 test vector (section 3):
 *   User:       user
 *   Password:   pencil
 *   Client nonce: rOprNGfwEbeRWgbNEkqO
 *   Salt (b64):   W22ZaJ0SNY7soEsUEjb6gQ==
 *   Iterations:   4096
 */
class ScramSha256Test extends TestCase
{
    private ScramSha256 $scram;

    protected function setUp(): void
    {
        $this->scram = new ScramSha256();
    }

    // -------------------------------------------------------------------------
    // Helper: call a private method via reflection
    // -------------------------------------------------------------------------

    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $rm = new ReflectionMethod(ScramSha256::class, $method);
        $rm->setAccessible(true);
        return $rm->invoke($this->scram, ...$args);
    }

    // -------------------------------------------------------------------------
    // RFC 7677 test vector: Hi() == PBKDF2-SHA-256
    // -------------------------------------------------------------------------

    public function testHi(): void
    {
        // RFC 7677 §3 test vector values
        $password   = 'pencil';       // SASLprep('pencil') = 'pencil' for ASCII
        $saltBase64 = 'W22ZaJ0SNY7soEsUEjb6gQ==';
        $salt       = base64_decode($saltBase64);
        $iterations = 4096;

        // Hi() is PBKDF2-SHA-256
        $expected = hash_pbkdf2('sha256', $password, $salt, $iterations, 0, true);

        /** @var string $actual */
        $actual = $this->callPrivate('hi', $password, $salt, $iterations);

        $this->assertSame($expected, $actual);
    }

    // -------------------------------------------------------------------------
    // Client-first-message format
    // -------------------------------------------------------------------------

    public function testClientFirstMessage(): void
    {
        // clientFirstMessageBare returns: n=<user>,r=<nonce>
        // The full client-first-message adds the GS2 header: 'n,,' prefix
        $username = 'user';
        $nonce    = 'rOprNGfwEbeRWgbNEkqO';

        /** @var string $bare */
        $bare = $this->callPrivate('clientFirstMessageBare', $username, $nonce);

        $clientFirst = 'n,,' . $bare;

        $this->assertStringStartsWith('n,,n=', $clientFirst);
        $this->assertStringContainsString('r=' . $nonce, $clientFirst);
        $this->assertSame('n,,n=user,r=rOprNGfwEbeRWgbNEkqO', $clientFirst);
    }

    // -------------------------------------------------------------------------
    // HMAC-SHA-256
    // -------------------------------------------------------------------------

    public function testHmacSha256(): void
    {
        $key      = 'secret-key';
        $data     = 'test-data';
        $expected = hash_hmac('sha256', $data, $key, true);

        /** @var string $actual */
        $actual = $this->callPrivate('hmac', $key, $data);

        $this->assertSame($expected, $actual);
    }

    // -------------------------------------------------------------------------
    // Nonce quality
    // -------------------------------------------------------------------------

    public function testNonceIsBase64(): void
    {
        /** @var string $nonce */
        $nonce = $this->callPrivate('generateNonce', 24);

        // A valid base64 string must decode without error and must not be empty.
        $decoded = base64_decode($nonce, strict: true);
        $this->assertNotFalse($decoded, 'Nonce must be valid base64.');
        $this->assertNotEmpty($nonce);

        // The nonce must not contain characters that are problematic in SCRAM
        // messages (comma or equals are used as field separators).
        // Standard base64 uses [A-Za-z0-9+/=]; SCRAM-SHA-256 uses base64 directly
        // so we just assert the string matches the base64 alphabet.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $nonce);
    }
}
