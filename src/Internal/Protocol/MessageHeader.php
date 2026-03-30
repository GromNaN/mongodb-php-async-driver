<?php

declare(strict_types=1);

namespace MongoDB\Internal\Protocol;

use InvalidArgumentException;

/**
 * Represents the 16-byte MongoDB Wire Protocol message header.
 *
 * @internal
 */
final class MessageHeader
{
    public const HEADER_SIZE = 16;
    public const OP_MSG        = 2013;
    public const OP_QUERY      = 2004; // legacy
    public const OP_REPLY      = 1;    // legacy
    public const OP_COMPRESSED = 2012;

    public function __construct(
        public readonly int $messageLength,
        public readonly int $requestId,
        public readonly int $responseTo,
        public readonly int $opCode,
    ) {}

    /**
     * Parse a 16-byte header from raw bytes.
     *
     * @throws InvalidArgumentException if $bytes is not exactly 16 bytes
     */
    public static function fromBytes(string $bytes): self
    {
        if (strlen($bytes) < self::HEADER_SIZE) {
            throw new InvalidArgumentException(
                sprintf(
                    'MessageHeader requires %d bytes, got %d',
                    self::HEADER_SIZE,
                    strlen($bytes)
                )
            );
        }

        /** @var array{1: int, 2: int, 3: int, 4: int} $fields */
        $fields = unpack('V4', substr($bytes, 0, self::HEADER_SIZE));

        return new self(
            messageLength: $fields[1],
            requestId:     $fields[2],
            responseTo:    $fields[3],
            opCode:        $fields[4],
        );
    }

    /**
     * Serialize the header to 16 raw bytes (four little-endian uint32 fields).
     */
    public function toBytes(): string
    {
        return pack('V', $this->messageLength)
             . pack('V', $this->requestId)
             . pack('V', $this->responseTo)
             . pack('V', $this->opCode);
    }
}
