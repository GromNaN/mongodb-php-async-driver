<?php

declare(strict_types=1);

namespace MongoDB\Internal\Auth;

use MongoDB\Internal\Connection\Connection;

/**
 * Represents a MongoDB authentication mechanism.
 *
 * Implementations carry out the full authentication handshake for a specific
 * SASL mechanism (e.g. SCRAM-SHA-256, SCRAM-SHA-1, MONGODB-X509, etc.).
 *
 * @internal
 */
interface AuthMechanism
{
    /**
     * The wire-protocol name of this mechanism (e.g. "SCRAM-SHA-256").
     */
    public function getName(): string;

    /**
     * Return additional fields to embed in the initial hello/isMaster command.
     *
     * For most mechanisms this is an empty array.  Mechanisms such as
     * SCRAM-SHA-256 / SCRAM-SHA-1 may ask the server to include
     * saslSupportedMechs in the hello response by returning, for example:
     *
     *   ['saslSupportedMechs' => "$authSource.$username"]
     *
     * @return array<string, mixed>
     */
    public function getHelloExtensions(): array;

    /**
     * Execute the full authentication sequence on an established connection.
     *
     * This method drives the complete multi-step SASL exchange (or a single
     * round-trip for simpler mechanisms), sending commands via
     * {@see Connection::sendCommand()} and validating every server response.
     *
     * @param Connection $connection The connection on which to authenticate.
     * @param string     $username   Plain-text username (pre-decoded from URI).
     * @param string     $password   Plain-text password (pre-decoded from URI).
     * @param string     $authSource The database against which to authenticate.
     *
     * @throws AuthenticationException If authentication fails for any reason
     *                                  (wrong credentials, SASL error, network
     *                                  error, server signature mismatch, …).
     */
    public function authenticate(
        Connection $connection,
        string $username,
        string $password,
        string $authSource,
    ): void;
}
