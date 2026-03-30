<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

use function bin2hex;
use function hex2bin;
use function pack;
use function preg_match;
use function random_bytes;
use function random_int;
use function sprintf;
use function substr;
use function time;
use function unpack;

final class ObjectId implements ObjectIdInterface, JsonSerializable, Type, Stringable
{
    /** @var int Static counter, initialized to a random value on first use. */
    private static int $counter = -1;

    /** @var string 12 raw bytes representing the ObjectId. */
    private string $bytes;

    /** @throws InvalidArgumentException if $id is not a valid 24-character hex string. */
    public function __construct(?string $id = null)
    {
        if ($id === null) {
            $this->bytes = self::generate();
        } else {
            if (! preg_match('/^[0-9a-fA-F]{24}$/', $id)) {
                throw new InvalidArgumentException(
                    sprintf('"%s" is not a valid 24-character hexadecimal ObjectId string.', $id),
                );
            }

            $this->bytes = hex2bin($id);
        }
    }

    // ------------------------------------------------------------------
    // ObjectIdInterface
    // ------------------------------------------------------------------

    public function getTimestamp(): int
    {
        /** @var array{ts: int} $unpacked */
        $unpacked = unpack('Nts', substr($this->bytes, 0, 4));

        return $unpacked['ts'];
    }

    public function __toString(): string
    {
        return bin2hex($this->bytes);
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    public function jsonSerialize(): mixed
    {
        return ['$oid' => $this->__toString()];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    public function __serialize(): array
    {
        return ['oid' => $this->__toString()];
    }

    public function __unserialize(array $data): void
    {
        $this->bytes = hex2bin($data['oid']);
    }

    public static function __set_state(array $properties): static
    {
        return new static($properties['oid'] ?? bin2hex($properties['bytes'] ?? ''));
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
