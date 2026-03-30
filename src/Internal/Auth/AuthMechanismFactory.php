<?php

declare(strict_types=1);

namespace MongoDB\Internal\Auth;

use InvalidArgumentException;

use function in_array;
use function is_array;
use function sprintf;

/**
 * Factory for creating {@see AuthMechanism} instances.
 *
 * @internal
 */
final class AuthMechanismFactory
{
    /**
     * Create an AuthMechanism for an explicitly-specified mechanism name.
     *
     * @param string $mechanism The wire-protocol name of the mechanism.
     *                          Case-sensitive (e.g. 'SCRAM-SHA-256').
     *
     * @throws InvalidArgumentException When the mechanism name is unsupported.
     */
    public static function create(string $mechanism): AuthMechanism
    {
        return match ($mechanism) {
            'SCRAM-SHA-256' => new ScramSha256(),
            'SCRAM-SHA-1'   => new ScramSha1(),
            default         => throw new InvalidArgumentException(
                sprintf(
                    'Unsupported authentication mechanism "%s". Supported: SCRAM-SHA-256, SCRAM-SHA-1.',
                    $mechanism,
                ),
            ),
        };
    }

    /**
     * Automatically select the best available mechanism for the given connection.
     *
     * Selection logic (mirrors the MongoDB driver specification):
     *
     * 1. If the server hello response contains `saslSupportedMechs`, use
     *    SCRAM-SHA-256 when it is listed, otherwise fall back to SCRAM-SHA-1.
     * 2. If `saslSupportedMechs` is absent (older server), default to
     *    SCRAM-SHA-256 when both username and authSource are provided, or
     *    SCRAM-SHA-1 for maximum compatibility.
     *
     * @param array<string, mixed> $helloResponse The decoded hello/isMaster response document.
     * @param string|null          $username      The username being authenticated (may be null
     *                                             for mechanisms that do not require one).
     * @param string|null          $authSource    The authentication database (may be null).
     */
    public static function detect(
        array $helloResponse,
        ?string $username,
        ?string $authSource,
    ): AuthMechanism {
        // If the server advertises supported mechanisms, honour its list.
        if (isset($helloResponse['saslSupportedMechs']) && is_array($helloResponse['saslSupportedMechs'])) {
            $supported = $helloResponse['saslSupportedMechs'];

            if (in_array('SCRAM-SHA-256', $supported, true)) {
                return new ScramSha256();
            }

            if (in_array('SCRAM-SHA-1', $supported, true)) {
                return new ScramSha1();
            }

            // The server responded with a list but neither mechanism is in it.
            // Fall through to the default below; the auth attempt will likely
            // fail but the error from the server will be more informative.
        }

        // Default: prefer SCRAM-SHA-256 (MongoDB 4.0+).
        return new ScramSha256();
    }
}
