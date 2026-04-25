<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use AllowDynamicProperties;
use MongoDB\BSON\PackedArray;
use MongoDB\BSON\Serializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Exception\UnexpectedValueException;
use stdClass;

use function array_map;
use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function sprintf;
use function strtolower;
use function trigger_error;

use const E_USER_DEPRECATED;

/**
 * Dynamic-property approach: only non-default properties are set, so
 * var_dump / var_export produce minimal output.
 *
 *   $this->mode                — string (always set)
 *   $this->tags                — array of stdClass (only when non-empty)
 *   $this->maxStalenessSeconds — int (only when != -1)
 *   $this->hedge               — stdClass (only when set)
 */
#[AllowDynamicProperties]
final class ReadPreference implements Serializable
{
    public const string PRIMARY = 'primary';
    public const string PRIMARY_PREFERRED = 'primaryPreferred';
    public const string SECONDARY = 'secondary';
    public const string SECONDARY_PREFERRED = 'secondaryPreferred';
    public const string NEAREST = 'nearest';

    public const int NO_MAX_STALENESS = -1;
    public const int SMALLEST_MAX_STALENESS_SECONDS = 90;

    private const MODE_MAP = [
        'primary'            => self::PRIMARY,
        'primarypreferred'   => self::PRIMARY_PREFERRED,
        'secondary'          => self::SECONDARY,
        'secondarypreferred' => self::SECONDARY_PREFERRED,
        'nearest'            => self::NEAREST,
    ];

    public function __construct(string $mode, ?array $tagSets = null, ?array $options = null)
    {
        $canonicalMode = self::MODE_MAP[strtolower($mode)] ?? null;
        if ($canonicalMode === null) {
            throw new InvalidArgumentException(sprintf("Unsupported readPreference value: '%s'", $mode));
        }

        $mode = $canonicalMode;

        // Validate and convert tagSets
        $normalizedTagSets = null;
        if ($tagSets !== null && $tagSets !== []) {
            // First validate each item's type
            foreach ($tagSets as $tagSet) {
                if (! is_array($tagSet) && ! is_object($tagSet)) {
                    throw new InvalidArgumentException(
                        'Read preference tags must be an array of zero or more documents',
                    );
                }
            }

            // Then check primary mode restriction
            if ($mode === self::PRIMARY) {
                throw new InvalidArgumentException('Primary read preference mode conflicts with tags');
            }

            // Convert array items to stdClass (without modifying original)
            $normalizedTagSets = [];
            foreach ($tagSets as $tagSet) {
                $normalizedTagSets[] = is_array($tagSet) ? (object) $tagSet : $tagSet;
            }
        }

        // Validate maxStalenessSeconds
        $maxStalenessSeconds = self::NO_MAX_STALENESS;
        if (isset($options['maxStalenessSeconds'])) {
            $ms = $options['maxStalenessSeconds'];
            if ($mode === self::PRIMARY) {
                throw new InvalidArgumentException('Primary read preference mode conflicts with maxStalenessSeconds');
            }

            if ($ms > 2147483647) {
                throw new InvalidArgumentException(
                    'Expected maxStalenessSeconds to be <= 2147483647, ' . $ms . ' given',
                );
            }

            if ($ms !== self::NO_MAX_STALENESS && $ms < self::SMALLEST_MAX_STALENESS_SECONDS) {
                throw new InvalidArgumentException(
                    'Expected maxStalenessSeconds to be >= 90, ' . $ms . ' given',
                );
            }

            $maxStalenessSeconds = $ms;
        }

        // Handle hedge option
        $hedge = null;
        if (isset($options['hedge'])) {
            $hedgeVal = $options['hedge'];
            trigger_error(
                'MongoDB\Driver\ReadPreference::__construct(): The "hedge" option is deprecated as of MongoDB 8.0 and will be removed in a future release',
                E_USER_DEPRECATED,
            );

            if ($hedgeVal instanceof PackedArray) {
                throw UnexpectedValueException::documentRequiredAsRoot();
            }

            if (! is_array($hedgeVal) && ! is_object($hedgeVal)) {
                throw new InvalidArgumentException('hedge must be an array or object');
            }

            if ($mode === self::PRIMARY) {
                throw new InvalidArgumentException('hedge may not be used with primary mode');
            }

            $hedgeObj = (object) $hedgeVal;
            $hedge    = ((array) $hedgeObj) !== [] ? $hedgeObj : null;
        }

        $this->applyState($mode, $normalizedTagSets, $maxStalenessSeconds, $hedge);
    }

    public function getModeString(): string
    {
        return $this->mode;
    }

    public function __debugInfo(): array
    {
        $info = ['mode' => $this->mode];

        if (isset($this->tags)) {
            $info['tags'] = $this->tags;
        }

        if (isset($this->maxStalenessSeconds)) {
            $info['maxStalenessSeconds'] = $this->maxStalenessSeconds;
        }

        if (isset($this->hedge)) {
            $info['hedge'] = $this->hedge;
        }

        return $info;
    }

    public function getTagSets(): array
    {
        if (! isset($this->tags)) {
            return [];
        }

        return array_map(static fn (object $t) => (array) $t, $this->tags);
    }

    public function getMaxStalenessSeconds(): int
    {
        return $this->maxStalenessSeconds ?? self::NO_MAX_STALENESS;
    }

    public function getHedge(): ?object
    {
        trigger_error(
            'Method MongoDB\Driver\ReadPreference::getHedge() is deprecated',
            E_USER_DEPRECATED,
        );

        return $this->hedge ?? null;
    }

    public function bsonSerialize(): stdClass
    {
        $doc       = new stdClass();
        $doc->mode = $this->mode;

        if (isset($this->tags)) {
            $doc->tags = $this->tags;
        }

        if (isset($this->maxStalenessSeconds)) {
            $doc->maxStalenessSeconds = $this->maxStalenessSeconds;
        }

        if (isset($this->hedge)) {
            $doc->hedge = $this->hedge;
        }

        return $doc;
    }

    public function __serialize(): array
    {
        $data = ['mode' => $this->mode];

        if (isset($this->tags)) {
            $data['tags'] = $this->tags;
        }

        if (isset($this->maxStalenessSeconds)) {
            $data['maxStalenessSeconds'] = $this->maxStalenessSeconds;
        }

        if (isset($this->hedge)) {
            $data['hedge'] = $this->hedge;
        }

        return $data;
    }

    public function __unserialize(array $data): void
    {
        $mode = $data['mode'] ?? null;

        if (! is_string($mode)) {
            throw new InvalidArgumentException(
                'MongoDB\Driver\ReadPreference initialization requires "mode" field to be string',
            );
        }

        if (! in_array($mode, self::MODE_MAP, true)) {
            throw new InvalidArgumentException(
                'MongoDB\Driver\ReadPreference initialization requires specific values for "mode" string field',
            );
        }

        $tags               = $data['tags'] ?? null;
        $maxStalenessSeconds = $data['maxStalenessSeconds'] ?? self::NO_MAX_STALENESS;
        $hedge              = $data['hedge'] ?? null;

        if ($hedge !== null) {
            trigger_error(
                'MongoDB\Driver\ReadPreference::__unserialize(): The "hedge" option is deprecated as of MongoDB 8.0 and will be removed in a future release',
                E_USER_DEPRECATED,
            );
        }

        $this->applyState($mode, $tags, $maxStalenessSeconds, $hedge);
    }

    public static function __set_state(array $properties): static
    {
        $mode = $properties['mode'] ?? null;

        // Validate mode type
        if (! is_string($mode)) {
            throw new InvalidArgumentException(
                'MongoDB\Driver\ReadPreference initialization requires "mode" field to be string',
            );
        }

        // Validate mode value
        if (! in_array($mode, self::MODE_MAP, true)) {
            throw new InvalidArgumentException(
                'MongoDB\Driver\ReadPreference initialization requires specific values for "mode" string field',
            );
        }

        // Validate tags
        $tags = null;
        if (isset($properties['tags'])) {
            if (! is_array($properties['tags'])) {
                throw new InvalidArgumentException(
                    'MongoDB\Driver\ReadPreference initialization requires "tags" field to be array',
                );
            }

            foreach ($properties['tags'] as $tagSet) {
                if (! is_array($tagSet) && ! is_object($tagSet)) {
                    throw new InvalidArgumentException(
                        'MongoDB\Driver\ReadPreference initialization requires "tags" array field to have zero or more documents',
                    );
                }
            }

            if ($properties['tags'] !== [] && $mode === self::PRIMARY) {
                throw new InvalidArgumentException(
                    'MongoDB\Driver\ReadPreference initialization requires "tags" array field to not be present with "primary" mode',
                );
            }

            // Convert arrays to stdClass
            $normalizedTags = [];
            foreach ($properties['tags'] as $tagSet) {
                $normalizedTags[] = is_array($tagSet) ? (object) $tagSet : $tagSet;
            }

            $tags = $normalizedTags !== [] ? $normalizedTags : null;
        }

        // Validate maxStalenessSeconds
        $maxStalenessSeconds = self::NO_MAX_STALENESS;
        if (isset($properties['maxStalenessSeconds'])) {
            $ms = $properties['maxStalenessSeconds'];
            if ($mode === self::PRIMARY) {
                throw new InvalidArgumentException(
                    'MongoDB\Driver\ReadPreference initialization requires "maxStalenessSeconds" field to not be present with "primary" mode',
                );
            }

            if ($ms > 2147483647) {
                throw new InvalidArgumentException(
                    'MongoDB\Driver\ReadPreference initialization requires "maxStalenessSeconds" integer field to be <= 2147483647',
                );
            }

            if ($ms < self::SMALLEST_MAX_STALENESS_SECONDS && $ms !== self::NO_MAX_STALENESS) {
                throw new InvalidArgumentException(
                    'MongoDB\Driver\ReadPreference initialization requires "maxStalenessSeconds" integer field to be >= 90',
                );
            }

            $maxStalenessSeconds = $ms;
        }

        // Validate hedge
        $hedge = null;
        if (isset($properties['hedge'])) {
            $hedgeVal = $properties['hedge'];

            if (! is_array($hedgeVal) && ! is_object($hedgeVal)) {
                throw new InvalidArgumentException(
                    'MongoDB\Driver\ReadPreference initialization requires "hedge" field to be an array or object',
                );
            }

            trigger_error(
                'MongoDB\Driver\ReadPreference::__set_state(): The "hedge" option is deprecated as of MongoDB 8.0 and will be removed in a future release',
                E_USER_DEPRECATED,
            );

            if ($hedgeVal instanceof PackedArray) {
                throw UnexpectedValueException::documentRequiredAsRoot();
            }

            if ($mode === self::PRIMARY) {
                throw new InvalidArgumentException(
                    'MongoDB\Driver\ReadPreference initialization requires "hedge" field to not be present with "primary" mode',
                );
            }

            $hedgeObj = (object) $hedgeVal;
            $hedge    = ((array) $hedgeObj) !== [] ? $hedgeObj : null;
        }

        $obj = new static($mode);
        $obj->applyState($mode, $tags, $maxStalenessSeconds, $hedge);

        return $obj;
    }

    private function applyState(string $mode, ?array $tags, int $maxStalenessSeconds, ?object $hedge): void
    {
        unset($this->mode, $this->tags, $this->maxStalenessSeconds, $this->hedge);

        $this->mode = $mode;

        if ($tags !== null && $tags !== []) {
            $this->tags = $tags;
        }

        if ($maxStalenessSeconds !== self::NO_MAX_STALENESS) {
            $this->maxStalenessSeconds = $maxStalenessSeconds;
        }

        if ($hedge === null) {
            return;
        }

        $this->hedge = $hedge;
    }
}
