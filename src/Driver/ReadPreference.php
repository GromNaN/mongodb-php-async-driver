<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use MongoDB\BSON\Serializable;
use stdClass;

use function array_filter;
use function in_array;
use function sprintf;

final class ReadPreference implements Serializable
{
    public const string PRIMARY = 'primary';
    public const string PRIMARY_PREFERRED = 'primaryPreferred';
    public const string SECONDARY = 'secondary';
    public const string SECONDARY_PREFERRED = 'secondaryPreferred';
    public const string NEAREST = 'nearest';

    public const int NO_MAX_STALENESS = -1;
    public const int SMALLEST_MAX_STALENESS_SECONDS = 90;

    private array $tagSets;
    private int $maxStalenessSeconds;
    private ?object $hedge;

    public function __construct(private string $mode, ?array $tagSets = null, ?array $options = null)
    {
        $validModes = [
            self::PRIMARY,
            self::PRIMARY_PREFERRED,
            self::SECONDARY,
            self::SECONDARY_PREFERRED,
            self::NEAREST,
        ];

        if (! in_array($mode, $validModes, true)) {
            throw new Exception\InvalidArgumentException(
                sprintf('Invalid mode "%s" given for ReadPreference', $mode),
            );
        }

        $this->tagSets = $tagSets ?? [];
        $this->maxStalenessSeconds = $options['maxStalenessSeconds'] ?? self::NO_MAX_STALENESS;
        $this->hedge = isset($options['hedge']) ? (object) $options['hedge'] : null;
    }

    public function getModeString(): string
    {
        return $this->mode;
    }

    public function getTagSets(): array
    {
        return $this->tagSets;
    }

    public function getMaxStalenessSeconds(): int
    {
        return $this->maxStalenessSeconds;
    }

    public function getHedge(): ?object
    {
        return $this->hedge;
    }

    public function bsonSerialize(): stdClass
    {
        $doc = new stdClass();
        $doc->mode = $this->mode;

        if ($this->tagSets !== []) {
            $doc->tags = $this->tagSets;
        }

        if ($this->maxStalenessSeconds !== self::NO_MAX_STALENESS) {
            $doc->maxStalenessSeconds = $this->maxStalenessSeconds;
        }

        if ($this->hedge !== null) {
            $doc->hedge = $this->hedge;
        }

        return $doc;
    }

    public function __serialize(): array
    {
        return [
            'mode' => $this->mode,
            'tagSets' => $this->tagSets,
            'maxStalenessSeconds' => $this->maxStalenessSeconds,
            'hedge' => $this->hedge,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->mode = $data['mode'];
        $this->tagSets = $data['tagSets'] ?? [];
        $this->maxStalenessSeconds = $data['maxStalenessSeconds'] ?? self::NO_MAX_STALENESS;
        $this->hedge = $data['hedge'] ?? null;
    }

    public static function __set_state(array $properties): static
    {
        $instance = new static(
            $properties['mode'],
            $properties['tagSets'] ?? null,
            array_filter([
                'maxStalenessSeconds' => $properties['maxStalenessSeconds'] ?? null,
                'hedge' => $properties['hedge'] ?? null,
            ], static fn ($v) => $v !== null),
        );

        return $instance;
    }

    public function __debugInfo(): array
    {
        return [
            'mode'                => $this->mode,
            'tags'                => $this->tagSets !== [] ? $this->tagSets : null,
            'maxStalenessSeconds' => $this->maxStalenessSeconds !== self::NO_MAX_STALENESS ? $this->maxStalenessSeconds : null,
            'hedge'               => $this->hedge,
        ];
    }
}
