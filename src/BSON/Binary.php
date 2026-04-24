<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use JsonSerializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Exception\LogicException;
use Stringable;

use function base64_encode;
use function is_int;
use function is_string;
use function ord;
use function sprintf;
use function strlen;

final class Binary implements BinaryInterface, JsonSerializable, Type, Stringable
{
    public const TYPE_GENERIC      = 0;
    public const TYPE_FUNCTION     = 1;
    public const TYPE_OLD_BINARY   = 2;
    public const TYPE_OLD_UUID     = 3;
    public const TYPE_UUID         = 4;
    public const TYPE_MD5          = 5;
    public const TYPE_ENCRYPTED    = 6;
    public const TYPE_COLUMN       = 7;
    public const TYPE_SENSITIVE    = 8;
    public const TYPE_VECTOR       = 9;
    public const TYPE_USER_DEFINED = 128;

    final public function __construct(public readonly string $data, public readonly int $type = self::TYPE_GENERIC)
    {
        if ($type < 0 || $type > 255) {
            throw new InvalidArgumentException(
                sprintf('Expected type to be an unsigned 8-bit integer, %d given', $type),
            );
        }

        if ($type === self::TYPE_OLD_UUID || $type === self::TYPE_UUID) {
            $len = strlen($data);
            if ($len !== 16) {
                throw new InvalidArgumentException(
                    sprintf('Expected UUID length to be 16 bytes, %d given', $len),
                );
            }
        }

        if ($type !== self::TYPE_VECTOR) {
            return;
        }

        self::validateVectorData($data);
    }

    // ------------------------------------------------------------------
    // Static factories
    // ------------------------------------------------------------------

    final public static function fromVector(array $vector, VectorType $vectorType): Binary
    {
        $data = $vectorType->encode($vector);

        return new self($data, self::TYPE_VECTOR);
    }

    // ------------------------------------------------------------------
    // BinaryInterface
    // ------------------------------------------------------------------

    final public function getData(): string
    {
        return $this->data;
    }

    final public function getType(): int
    {
        return $this->type;
    }

    final public function getVectorType(): VectorType
    {
        if ($this->type !== self::TYPE_VECTOR) {
            throw new LogicException(
                sprintf('Expected Binary of type vector (9) but it is %d', $this->type),
            );
        }

        return VectorType::fromDtypeByte(ord($this->data[0]));
    }

    final public function toArray(): array
    {
        if ($this->type !== self::TYPE_VECTOR) {
            throw new LogicException(
                sprintf('Expected Binary of type vector (9) but it is %d', $this->type),
            );
        }

        return VectorType::fromDtypeByte(ord($this->data[0]))->decode($this->data);
    }

    final public function __toString(): string
    {
        return $this->data;
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    final public function jsonSerialize(): mixed
    {
        return [
            '$binary' => base64_encode($this->data),
            '$type'   => sprintf('%02x', $this->type),
        ];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    final public function __serialize(): array
    {
        return [
            'data' => $this->data,
            'type' => $this->type,
        ];
    }

    final public function __unserialize(array $data): void
    {
        self::validateInitFields($data);
        self::validateTypeRange($data['type']);

        if ($data['type'] === self::TYPE_OLD_UUID || $data['type'] === self::TYPE_UUID) {
            $len = strlen($data['data']);
            if ($len !== 16) {
                throw new InvalidArgumentException(
                    sprintf('Expected UUID length to be 16 bytes, %d given', $len),
                );
            }
        }

        if ($data['type'] === self::TYPE_VECTOR) {
            self::validateVectorData($data['data']);
        }

        $this->data = $data['data'];
        $this->type = $data['type'];
    }

    final public static function __set_state(array $properties): static
    {
        self::validateInitFields($properties);
        self::validateTypeRange($properties['type']);

        if ($properties['type'] === self::TYPE_OLD_UUID || $properties['type'] === self::TYPE_UUID) {
            $len = strlen($properties['data']);
            if ($len !== 16) {
                throw new InvalidArgumentException(
                    sprintf('Expected UUID length to be 16 bytes, %d given', $len),
                );
            }
        }

        if ($properties['type'] === self::TYPE_VECTOR) {
            self::validateVectorData($properties['data']);
        }

        return new static($properties['data'], $properties['type']);
    }

    public function __debugInfo(): array
    {
        $info = [
            'data' => base64_encode($this->data),
            'type' => $this->type,
        ];

        if ($this->type === self::TYPE_VECTOR && strlen($this->data) >= 2) {
            $info['vector']     = $this->toArray();
            $info['vectorType'] = $this->getVectorType();
        }

        return $info;
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private static function validateInitFields(array $data): void
    {
        if (
            ! is_string($data['data'] ?? null) ||
            ! isset($data['type']) || ! is_int($data['type'])
        ) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\Binary initialization requires "data" string and "type" integer fields',
            );
        }
    }

    private static function validateTypeRange(int $type): void
    {
        if ($type < 0 || $type > 255) {
            throw new InvalidArgumentException(
                sprintf('Expected type to be an unsigned 8-bit integer, %d given', $type),
            );
        }
    }

    private static function validateVectorData(string $data): void
    {
        if (strlen($data) < 2) {
            throw new InvalidArgumentException('Binary vector data is invalid');
        }

        $dtype   = ord($data[0]);
        $padding = ord($data[1]);

        // PackedBit (dtype=0x10): padded bits in the last byte must be zero
        if ($dtype !== 0x10 || $padding <= 0 || strlen($data) <= 2) {
            return;
        }

        $lastByte   = ord($data[strlen($data) - 1]);
        $paddedMask = (1 << $padding) - 1;
        if (($lastByte & $paddedMask) !== 0) {
            throw new InvalidArgumentException('Binary vector data is invalid');
        }
    }
}
