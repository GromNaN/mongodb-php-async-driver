<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use JsonSerializable;
use Stringable;

use function sprintf;

final class Timestamp implements TimestampInterface, JsonSerializable, Type, Stringable
{
    public function __construct(private int $increment, private int $timestamp)
    {
    }

    // ------------------------------------------------------------------
    // TimestampInterface
    // ------------------------------------------------------------------

    public function getIncrement(): int
    {
        return $this->increment;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function __toString(): string
    {
        return sprintf('%d:%d', $this->increment, $this->timestamp);
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    public function jsonSerialize(): mixed
    {
        return [
            '$timestamp' => [
                't' => $this->timestamp,
                'i' => $this->increment,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    public function __serialize(): array
    {
        return [
            'increment' => $this->increment,
            'timestamp' => $this->timestamp,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->increment = $data['increment'];
        $this->timestamp = $data['timestamp'];
    }

    public static function __set_state(array $properties): static
    {
        return new static($properties['increment'], $properties['timestamp']);
    }

    public function __debugInfo(): array
    {
        return [
            'increment' => (string) $this->increment,
            'timestamp' => (string) $this->timestamp,
        ];
    }
}
