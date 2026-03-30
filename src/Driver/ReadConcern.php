<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use MongoDB\BSON\Serializable;
use stdClass;

final class ReadConcern implements Serializable
{
    public const string LINEARIZABLE = 'linearizable';
    public const string LOCAL = 'local';
    public const string MAJORITY = 'majority';
    public const string AVAILABLE = 'available';
    public const string SNAPSHOT = 'snapshot';

    public function __construct(private ?string $level = null)
    {
    }

    public function getLevel(): ?string
    {
        return $this->level;
    }

    public function isDefault(): bool
    {
        return $this->level === null;
    }

    public function bsonSerialize(): stdClass
    {
        $doc = new stdClass();

        if ($this->level !== null) {
            $doc->level = $this->level;
        }

        return $doc;
    }

    public function __serialize(): array
    {
        return ['level' => $this->level];
    }

    public function __unserialize(array $data): void
    {
        $this->level = $data['level'] ?? null;
    }

    public static function __set_state(array $properties): static
    {
        return new static($properties['level'] ?? null);
    }
}
