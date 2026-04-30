<?php

declare(strict_types=1);

namespace MongoDB\Internal\Session;

use MongoDB\BSON\Binary;
use stdClass;

use function array_pop;
use function chr;
use function max;
use function ord;
use function random_bytes;
use function time;

/**
 * Server-session pool.
 *
 * Maintains a list of reusable logical-session IDs (lsids).  Sessions that
 * have not been used within {@see self::$sessionTimeoutMinutes} are considered
 * expired and are discarded on the next {@see self::prune()} call.
 *
 * The pool is intentionally simple: sessions are handed out LIFO (last-in,
 * first-out) so the most-recently-used sessions are preferred, which keeps the
 * server's session table as small as possible.
 *
 * @internal
 */
final class SessionPool
{
    /**
     * Available sessions, each represented as a plain object with two properties:
     *   - lsid:    object  — the logical session identifier (has an `id` Binary field)
     *   - lastUse: int     — Unix timestamp of the last use
     *
     * @var list<object{lsid: object, lastUse: int}>
     */
    private array $pool = [];

    /**
     * Session timeout communicated by the server (default: 30 minutes).
     * Sessions older than this threshold are considered expired.
     */
    private int $sessionTimeoutMinutes = 30;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Acquire a session from the pool, or create a new one if none are available.
     *
     * Returns only the lsid object (not the pool entry), ready to be injected
     * into a command via the `lsid` field.
     */
    public function acquire(): object
    {
        // Pop the most-recently-used session from the end of the list (LIFO).
        while ($this->pool !== []) {
            $entry = array_pop($this->pool);

            if (! $this->isExpired($entry->lastUse)) {
                return $entry->lsid;
            }
            // Discard expired session and try the next one.
        }

        // No reusable session available: generate a fresh lsid.
        return $this->generateLsid();
    }

    /**
     * Return a session to the pool after use.
     *
     * @param object $lsid The lsid object previously returned by {@see self::acquire()}.
     */
    public function release(object $lsid): void
    {
        $entry = new stdClass();
        $entry->lsid    = $lsid;
        $entry->lastUse = time();

        $this->pool[] = $entry;
    }

    /**
     * Update the session-timeout value advertised by the connected server.
     *
     * This should be called whenever a fresh hello/isMaster response is
     * received that contains a `logicalSessionTimeoutMinutes` field.
     */
    public function setSessionTimeoutMinutes(int $minutes): void
    {
        $this->sessionTimeoutMinutes = $minutes;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Generate a brand-new logical session ID as a plain object.
     *
     * The spec requires the `id` field to be a UUID v4 encoded as a BSON Binary
     * with sub-type 4 (UUID).
     */
    private function generateLsid(): object
    {
        // Generate 16 random bytes for the UUID v4.
        $bytes = random_bytes(16);

        // Set version (4) and variant bits per RFC 4122.
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // variant bits

        $lsid     = new stdClass();
        $lsid->id = new Binary($bytes, Binary::TYPE_UUID);

        return $lsid;
    }

    /**
     * Return true when a session has not been used within the timeout window.
     *
     * We subtract one minute from the server-advertised timeout to avoid a
     * race where a session expires on the server while still in our pool.
     */
    private function isExpired(int $lastUse): bool
    {
        $timeoutSeconds = max(0, $this->sessionTimeoutMinutes - 1) * 60;

        return time() - $lastUse >= $timeoutSeconds;
    }
}
