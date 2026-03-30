<?php

declare(strict_types=1);

namespace MongoDB\BSON;

final class Binary implements BinaryInterface, \JsonSerializable, Type, \Stringable
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

    private string $data;
    private int    $type;

    public function __construct(string $data, int $type = self::TYPE_GENERIC)
    {
        if ($type < 0 || $type > 255) {
            throw new \InvalidArgumentException(
                sprintf('Binary type must be between 0 and 255, %d given.', $type),
            );
        }

        $this->data = $data;
        $this->type = $type;
    }

    // ------------------------------------------------------------------
    // BinaryInterface
    // ------------------------------------------------------------------

    public function getData(): string
    {
        return $this->data;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function __toString(): string
    {
        return $this->data;
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    public function jsonSerialize(): mixed
    {
        return [
            '$binary' => [
                'base64'  => base64_encode($this->data),
                'subType' => sprintf('%02x', $this->type),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    public function __serialize(): array
    {
        return [
            'data' => base64_encode($this->data),
            'type' => $this->type,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->data = base64_decode($data['data']);
        $this->type = $data['type'];
    }

    public static function __set_state(array $properties): static
    {
        return new static($properties['data'], $properties['type']);
    }
}
