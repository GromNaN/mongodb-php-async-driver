<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use JsonSerializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use Stringable;

use function bin2hex;
use function hex2bin;
use function is_string;
use function pack;
use function preg_match;
use function random_bytes;
use function random_int;
use function sprintf;
use function strlen;
use function strtolower;
use function substr;
use function time;
use function unpack;

final class ObjectId implements ObjectIdInterface, JsonSerializable, Type, Stringable
{
    /** @var int Static counter, initialized to a random value on first use. */
    private static int $counter = -1;

    /** Hex-encoded 12-byte ObjectId. */
    public readonly string $oid;

    /** @throws InvalidArgumentException if $id is not a valid 24-character hex string. */
    final public function __construct(?string $id = null)
    {
        if ($id === null) {
            $this->oid = bin2hex(self::generate());
        } else {
            if (strlen($id) !== 24 || ! preg_match('/^[0-9a-fA-F]{24}$/', $id)) {
                throw new InvalidArgumentException(
                    sprintf('Error parsing ObjectId string: %s', $id),
                );
            }

            $this->oid = strtolower($id);
        }
    }

    // ------------------------------------------------------------------
    // ObjectIdInterface
    // ------------------------------------------------------------------

    final public function getTimestamp(): int
    {
        /** @var array{ts: int} $unpacked */
        $unpacked = unpack('Nts', hex2bin(substr($this->oid, 0, 8)));

        return $unpacked['ts'];
    }

    final public function __toString(): string
    {
        return $this->oid;
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    final public function jsonSerialize(): mixed
    {
        return ['$oid' => $this->oid];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    final public function __serialize(): array
    {
        return ['oid' => $this->oid];
    }

    final public function __unserialize(array $data): void
    {
        if (! is_string($data['oid'] ?? null)) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\ObjectId initialization requires "oid" string field',
            );
        }

        if (! preg_match('/^[0-9a-fA-F]{24}$/', $data['oid'])) {
            throw new InvalidArgumentException(
                sprintf('Error parsing ObjectId string: %s', $data['oid']),
            );
        }

        $this->oid = strtolower($data['oid']);
    }

    final public static function __set_state(array $properties): static
    {
        if (! is_string($properties['oid'] ?? null)) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\ObjectId initialization requires "oid" string field',
            );
        }

        if (! preg_match('/^[0-9a-fA-F]{24}$/', $properties['oid'])) {
            throw new InvalidArgumentException(
                sprintf('Error parsing ObjectId string: %s', $properties['oid']),
            );
        }

        return new static($properties['oid']);
    }

    public function __debugInfo(): array
    {
        return ['oid' => $this->oid];
    }

    // ------------------------------------------------------------------
    // Internal generation
    // ------------------------------------------------------------------

    /**
     * Generate 12 bytes: 4-byte big-endian Unix timestamp +
     *                    5-byte random machine/process id +
     *                    3-byte big-endian incrementing counter.
     */
    private static function generate(): string
    {
        if (self::$counter === -1) {
            self::$counter = random_int(0, 0xFFFFFF);
        }

        $timestamp = pack('N', time());
        $random    = random_bytes(5);
        $counter   = self::$counter = self::$counter + 1 & 0xFFFFFF;
        $counterBytes = pack('N', $counter);
        // We only need the last 3 bytes of the 4-byte packed int.
        $counterBytes = substr($counterBytes, 1, 3);

        return $timestamp . $random . $counterBytes;
    }
}
