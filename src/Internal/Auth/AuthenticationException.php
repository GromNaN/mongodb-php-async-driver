<?php

declare(strict_types=1);

namespace MongoDB\Internal\Auth;

use RuntimeException;

/**
 * Thrown when MongoDB authentication fails.
 *
 * @internal
 */
final class AuthenticationException extends RuntimeException
{
}
