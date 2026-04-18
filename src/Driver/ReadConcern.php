<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use AllowDynamicProperties;
use MongoDB\BSON\Serializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use stdClass;

use function is_string;

/**
 * The "level" dynamic property is set only when non-null, making var_export
 * produce an empty array for the default (no-level) case.  __debugInfo()
 * provides the same view for var_dump.
 */
#[AllowDynamicProperties]
final class ReadConcern implements Serializable
{
    public const string LINEARIZABLE = 'linearizable';
    public const string LOCAL = 'local';
    public const string MAJORITY = 'majority';
    public const string AVAILABLE = 'available';
    public const string SNAPSHOT = 'snapshot';

    public function __construct(?string $level = null)
    {
        if ($level === null) {
            return;
        }

        $this->level = $level;
    }

    public function getLevel(): ?string
    {
        return $this->level ?? null;
    }

    public function isDefault(): bool
    {
        return ! isset($this->level);
    }

    public function bsonSerialize(): stdClass
    {
        $doc = new stdClass();

        if (isset($this->level)) {
            $doc->level = $this->level;
        }

        return $doc;
    }

    public function __serialize(): array
    {
        if (! isset($this->level)) {
            return [];
        }

        return ['level' => $this->level];
    }

    public function __unserialize(array $data): void
    {
        if (! isset($data['level'])) {
            return;
        }

        if (! is_string($data['level'])) {
            throw new InvalidArgumentException('MongoDB\Driver\ReadConcern initialization requires "level" string field');
        }

        $this->level = $data['level'];
    }

    public static function __set_state(array $properties): static
    {
        if (isset($properties['level'])) {
            if (! is_string($properties['level'])) {
                throw new InvalidArgumentException('MongoDB\Driver\ReadConcern initialization requires "level" string field');
            }

            return new static($properties['level']);
        }

        return new static(null);
    }

    public function __debugInfo(): array
    {
        if (! isset($this->level)) {
            return [];
        }

        return ['level' => $this->level];
    }
}
