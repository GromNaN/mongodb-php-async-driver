<?php

declare(strict_types=1);

namespace MongoDB\Internal\Auth;

use MongoDB\Internal\Connection\Connection;
use Normalizer;
use SensitiveParameter;

use function base64_decode;
use function base64_encode;
use function class_exists;
use function explode;
use function function_exists;
use function hash;
use function hash_equals;
use function hash_hmac;
use function hash_pbkdf2;
use function md5;
use function random_bytes;
use function sodium_memzero;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strpos;
use function substr;

/**
 * SCRAM-SHA-1 authentication mechanism (RFC 5802).
 *
 * This is the SHA-1 variant of SCRAM, provided for compatibility with older
 * MongoDB servers (< 4.0) that do not support SCRAM-SHA-256.
 *
 * @internal
 */
final class ScramSha1 implements AuthMechanism
{
    private const MECHANISM   = 'SCRAM-SHA-1';
    private const HASH_ALGO   = 'sha1';
    private const NONCE_LENGTH = 24;

    // -------------------------------------------------------------------------
    // AuthMechanism interface
    // -------------------------------------------------------------------------

    public function getName(): string
    {
        return self::MECHANISM;
    }

    /** @return array<string, mixed> */
    public function getHelloExtensions(): array
    {
        return [];
    }

    /**
     * Perform the full SCRAM-SHA-1 handshake.
     *
     * The protocol flow is identical to SCRAM-SHA-256 but uses SHA-1 for all
     * hashing operations and MD5 for password hashing (MongoDB-specific).
     *
     * @throws AuthenticationException
     */
    public function authenticate(
        Connection $connection,
        string $username,
        #[SensitiveParameter]
        string $password,
        string $authSource,
    ): void {
        // ------------------------------------------------------------------
        // Step 1 — Client first message
        // ------------------------------------------------------------------
        $clientNonce      = $this->generateNonce(self::NONCE_LENGTH);
        $preparedUsername = $this->saslPrep($username);

        $clientFirstMsgBare = $this->clientFirstMessageBare($preparedUsername, $clientNonce);
        $clientFirstMsg     = 'n,,' . $clientFirstMsgBare;

        $saslStartCmd = [
            'saslStart' => 1,
            'mechanism' => self::MECHANISM,
            'payload'   => base64_encode($clientFirstMsg),
            '$db'       => $authSource,
        ];

        $startReply = (array) $connection->sendCommand($authSource, $saslStartCmd);

        $this->assertCommandOk($startReply, 'saslStart');

        $conversationId = $startReply['conversationId']
            ?? throw new AuthenticationException('saslStart reply missing conversationId.');
        $serverFirstMsg = base64_decode((string) ($startReply['payload'] ?? ''));

        // ------------------------------------------------------------------
        // Step 2 — Parse server first message
        // ------------------------------------------------------------------
        $serverFirstParts = $this->parseKvMessage($serverFirstMsg);

        if (! isset($serverFirstParts['r'], $serverFirstParts['s'], $serverFirstParts['i'])) {
            throw new AuthenticationException(
                'Malformed SCRAM server-first-message: missing r, s, or i fields.',
            );
        }

        $serverNonce = $serverFirstParts['r'];
        $salt        = base64_decode($serverFirstParts['s']);
        $iterations  = (int) $serverFirstParts['i'];

        if (! str_starts_with($serverNonce, $clientNonce)) {
            throw new AuthenticationException(
                'Server nonce does not start with client nonce — possible replay attack.',
            );
        }

        if ($iterations < 4096) {
            throw new AuthenticationException(
                sprintf(
                    'SCRAM iteration count %d is below the minimum of 4096.',
                    $iterations,
                ),
            );
        }

        // ------------------------------------------------------------------
        // Step 3 — Compute keys
        // ------------------------------------------------------------------
        // MongoDB SCRAM-SHA-1 uses MD5(username:mongo:password) as the password
        // input to PBKDF2 rather than the raw password (MongoDB-specific extension).
        $mongoPassword  = md5($username . ':mongo:' . $password);
        $saltedPassword = $this->hi($mongoPassword, $salt, $iterations);
        // Zero MD5-hashed password material from memory as soon as PBKDF2 is done.
        if (function_exists('sodium_memzero')) {
            sodium_memzero($mongoPassword);
        }

        $clientKey = $this->hmac($saltedPassword, 'Client Key');
        $storedKey = $this->h($clientKey);
        $serverKey = $this->hmac($saltedPassword, 'Server Key');
        // Zero the derived key once all HMAC sub-keys have been extracted.
        if (function_exists('sodium_memzero')) {
            sodium_memzero($saltedPassword);
        }

        // clientFinalMessageWithoutProof
        $channelBinding             = 'c=' . base64_encode('n,,');
        $clientFinalMsgWithoutProof = $channelBinding . ',r=' . $serverNonce;

        $authMessage = $clientFirstMsgBare
            . ',' . $serverFirstMsg
            . ',' . $clientFinalMsgWithoutProof;

        $clientSignature = $this->hmac($storedKey, $authMessage);
        $clientProof     = $this->xorStrings($clientKey, $clientSignature);
        $serverSignature = $this->hmac($serverKey, $authMessage);

        $clientFinalMsg = $clientFinalMsgWithoutProof . ',p=' . base64_encode($clientProof);

        // ------------------------------------------------------------------
        // Step 4 — Client final message
        // ------------------------------------------------------------------
        $saslContinueCmd = [
            'saslContinue'   => 1,
            'conversationId' => $conversationId,
            'payload'        => base64_encode($clientFinalMsg),
            '$db'            => $authSource,
        ];

        $continueReply = (array) $connection->sendCommand($authSource, $saslContinueCmd);
        $this->assertCommandOk($continueReply, 'saslContinue (step 2)');

        $serverFinalMsg = base64_decode((string) ($continueReply['payload'] ?? ''));

        // ------------------------------------------------------------------
        // Step 5 — Verify server signature
        // ------------------------------------------------------------------
        $serverFinalParts = $this->parseKvMessage($serverFinalMsg);

        if (isset($serverFinalParts['e'])) {
            throw new AuthenticationException(
                sprintf('SCRAM authentication error from server: %s', $serverFinalParts['e']),
            );
        }

        if (! isset($serverFinalParts['v'])) {
            throw new AuthenticationException(
                'SCRAM server-final-message missing server signature (v).',
            );
        }

        $receivedServerSig = base64_decode($serverFinalParts['v']);

        if (! hash_equals($serverSignature, $receivedServerSig)) {
            throw new AuthenticationException(
                'SCRAM server signature verification failed — server identity cannot be confirmed.',
            );
        }

        // ------------------------------------------------------------------
        // Step 6 — Complete the SASL conversation (if server says done=false)
        // ------------------------------------------------------------------
        $done = (bool) ($continueReply['done'] ?? false);

        if ($done) {
            return;
        }

        $finalAckCmd = [
            'saslContinue'   => 1,
            'conversationId' => $conversationId,
            'payload'        => base64_encode(''),
            '$db'            => $authSource,
        ];

        $ackReply = (array) $connection->sendCommand($authSource, $finalAckCmd);
        $this->assertCommandOk($ackReply, 'saslContinue (final ack)');
    }

    // -------------------------------------------------------------------------
    // SCRAM building blocks
    // -------------------------------------------------------------------------

    /**
     * Build the client-first-message-bare component:
     *   n=<escaped_username>,r=<nonce>
     */
    private function clientFirstMessageBare(string $username, string $nonce): string
    {
        $escapedUser = str_replace(['=', ','], ['=3D', '=2C'], $username);

        return 'n=' . $escapedUser . ',r=' . $nonce;
    }

    /**
     * PBKDF2-SHA-1 key derivation (SCRAM Hi() function).
     *
     * @param string $password   Password (already MD5-hashed for SCRAM-SHA-1)
     * @param string $salt       Raw binary salt
     * @param int    $iterations Iteration count
     *
     * @return string Raw binary derived key
     */
    private function hi(string $password, string $salt, int $iterations): string
    {
        return hash_pbkdf2(self::HASH_ALGO, $password, $salt, $iterations, 0, true);
    }

    /**
     * HMAC-SHA-1 over $data using $key.
     * Returns raw binary output.
     */
    private function hmac(string $key, string $data): string
    {
        return hash_hmac(self::HASH_ALGO, $data, $key, true);
    }

    /**
     * SHA-1 hash of $data.
     * Returns raw binary output.
     */
    private function h(string $data): string
    {
        return hash(self::HASH_ALGO, $data, true);
    }

    /**
     * Byte-wise XOR two equal-length strings.
     */
    private function xorStrings(string $a, string $b): string
    {
        return $a ^ $b;
    }

    /**
     * Generate a cryptographically random nonce of $length bytes, base64-encoded.
     */
    private function generateNonce(int $length = self::NONCE_LENGTH): string
    {
        return base64_encode(random_bytes($length));
    }

    // -------------------------------------------------------------------------
    // SASLprep
    // -------------------------------------------------------------------------

    /**
     * Apply SASLprep profile of stringprep (RFC 4013) to a string.
     *
     * Used for username normalisation only; the password goes through the
     * MongoDB-specific MD5 path instead.
     */
    private function saslPrep(string $str): string
    {
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($str, Normalizer::FORM_KC);
            if ($normalized !== false) {
                return $normalized;
            }
        }

        return $str;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Parse a SCRAM key=value message (comma-separated pairs) into an array.
     *
     * @return array<string, string>
     */
    private function parseKvMessage(string $message): array
    {
        $parts = [];
        foreach (explode(',', $message) as $pair) {
            $eqPos = strpos($pair, '=');
            if ($eqPos === false) {
                continue;
            }

            $key         = substr($pair, 0, $eqPos);
            $value       = substr($pair, $eqPos + 1);
            $parts[$key] = $value;
        }

        return $parts;
    }

    /**
     * Assert that a command response contains ok: 1.
     *
     * @param array<string, mixed> $reply
     *
     * @throws AuthenticationException
     */
    private function assertCommandOk(array $reply, string $commandName): void
    {
        $ok = $reply['ok'] ?? 0;

        if ((float) $ok !== 1.0) {
            $errmsg = $reply['errmsg'] ?? 'unknown error';
            $code   = $reply['code']   ?? 0;

            throw new AuthenticationException(
                sprintf(
                    '%s failed (code %d): %s',
                    $commandName,
                    $code,
                    $errmsg,
                ),
            );
        }
    }
}
