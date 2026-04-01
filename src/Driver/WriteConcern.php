<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use AllowDynamicProperties;
use MongoDB\BSON\Serializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use stdClass;

use function ctype_digit;
use function get_debug_type;
use function is_bool;
use function is_int;
use function is_string;
use function str_starts_with;
use function substr;

/**
 * Dynamic-property approach: only "meaningful" properties (w, j, wtimeout)
 * are set as dynamic properties, so var_dump / var_export output only the
 * fields that differ from defaults.
 *
 * Internal state:
 *   $this->w        — the effective w value: string|int (absent when w=-2/isDefault)
 *   $this->j        — journal flag (absent when null)
 *   $this->wtimeout — timeout in ms (absent when 0)
 */
#[AllowDynamicProperties]
final class WriteConcern implements Serializable
{
    public const string MAJORITY = 'majority';

    public function __construct(mixed $w, mixed $wtimeout = null, mixed $journal = null)
    {
        // Validate w type
        if (! is_int($w) && ! is_string($w)) {
            throw new InvalidArgumentException(
                'Expected w to be integer or string, ' . get_debug_type($w) . ' given',
            );
        }

        // Validate w range
        if (is_int($w) && $w < -3) {
            throw new InvalidArgumentException(
                'Expected w to be >= -3, ' . $w . ' given',
            );
        }

        // Validate wtimeout type and range
        if ($wtimeout !== null) {
            if (! is_int($wtimeout)) {
                throw new InvalidArgumentException(
                    'Expected wtimeout to be >= 0, ' . get_debug_type($wtimeout) . ' given',
                );
            }

            if ($wtimeout < 0) {
                throw new InvalidArgumentException(
                    'Expected wtimeout to be >= 0, ' . $wtimeout . ' given',
                );
            }
        }

        // Validate journal: cannot use j=true with w=0
        if ($journal !== null) {
            $journal = (bool) $journal;
        }

        if ($journal === true && $w === 0) {
            throw new InvalidArgumentException('Journal conflicts with w value: 0');
        }

        // Map special integer w values
        if ($w === -2) {
            // isDefault — no w property set
            $effectiveW = null;
        } elseif ($w === -3) {
            $effectiveW = self::MAJORITY;
        } else {
            $effectiveW = $w;
        }

        $this->applyState($effectiveW, $wtimeout ?? 0, $journal);
    }

    /** @internal Create a WriteConcern that reports isDefault() = true */
    public static function createDefault(): self
    {
        return new self(-2);
    }

    public function getW(): string|int|null
    {
        return $this->w ?? null;
    }

    public function getWtimeout(): int
    {
        return isset($this->wtimeout) ? (int) $this->wtimeout : 0;
    }

    public function getJournal(): ?bool
    {
        return $this->j ?? null;
    }

    public function isDefault(): bool
    {
        return ! isset($this->w);
    }

    public function __debugInfo(): array
    {
        $info = [];
        if (isset($this->w)) {
            $info['w'] = $this->w;
        }

        if (isset($this->j)) {
            $info['j'] = $this->j;
        }

        if (isset($this->wtimeout)) {
            $info['wtimeout'] = $this->wtimeout;
        }

        return $info;
    }

    public function bsonSerialize(): stdClass
    {
        $doc = new stdClass();

        if (isset($this->w)) {
            $doc->w = $this->w;
        }

        if (isset($this->j)) {
            $doc->j = $this->j;
        }

        if (isset($this->wtimeout)) {
            $doc->wtimeout = $this->wtimeout;
        }

        return $doc;
    }

    public function __serialize(): array
    {
        $data = [];
        if (isset($this->w)) {
            $data['w'] = $this->w;
        }

        if (isset($this->j)) {
            $data['j'] = $this->j;
        }

        if (isset($this->wtimeout)) {
            $data['wtimeout'] = $this->wtimeout;
        }

        return $data;
    }

    public function __unserialize(array $data): void
    {
        $w        = $data['w'] ?? null;
        $wtimeout = $data['wtimeout'] ?? 0;
        $journal  = isset($data['j']) ? (bool) $data['j'] : null;

        if ($journal === true && $w === 0) {
            throw new InvalidArgumentException('Journal conflicts with w value: 0');
        }

        $this->applyState($w, is_string($wtimeout) ? $wtimeout : (int) $wtimeout, $journal);
    }

    public static function __set_state(array $properties): static
    {
        $w        = $properties['w'] ?? null;
        $journal  = $properties['j'] ?? null;
        $wtimeout = $properties['wtimeout'] ?? null;

        // Validate w
        if ($w !== null) {
            if (! is_int($w) && ! is_string($w)) {
                throw new InvalidArgumentException(
                    'MongoDB\Driver\WriteConcern initialization requires "w" field to be integer or string',
                );
            }

            if (is_int($w) && $w < -3) {
                throw new InvalidArgumentException(
                    'MongoDB\Driver\WriteConcern initialization requires "w" integer field to be >= -3',
                );
            }
        }

        // Validate wtimeout
        if ($wtimeout !== null) {
            if (is_string($wtimeout)) {
                // Accept numeric strings for 64-bit values — keep as string for serialization
                $positive = str_starts_with($wtimeout, '-') ? substr($wtimeout, 1) : $wtimeout;
                if (! ctype_digit($positive) || $positive === '') {
                    throw new InvalidArgumentException(
                        'Error parsing "' . $wtimeout . '" as 64-bit value for MongoDB\Driver\WriteConcern initialization',
                    );
                }

                // Negative string → same error as integer < 0
                if (str_starts_with($wtimeout, '-')) {
                    throw new InvalidArgumentException(
                        'MongoDB\Driver\WriteConcern initialization requires "wtimeout" integer field to be >= 0',
                    );
                }
                // Keep as string to preserve 64-bit value in serialization
            } elseif (! is_int($wtimeout)) {
                throw new InvalidArgumentException(
                    'MongoDB\Driver\WriteConcern initialization requires "wtimeout" field to be integer or string',
                );
            }

            if ($wtimeout < 0) {
                throw new InvalidArgumentException(
                    'MongoDB\Driver\WriteConcern initialization requires "wtimeout" integer field to be >= 0',
                );
            }
        }

        // Validate journal
        if ($journal !== null && ! is_bool($journal)) {
            throw new InvalidArgumentException(
                'MongoDB\Driver\WriteConcern initialization requires "j" field to be boolean',
            );
        }

        // Map w=-3 to 'majority', w=-2 to null (isDefault)
        if ($w === -3) {
            $w = self::MAJORITY;
        } elseif ($w === -2) {
            $w = null;
        }

        $obj = new static(-2); // Start with isDefault
        $obj->applyState($w, $wtimeout ?? 0, $journal);

        return $obj;
    }

    private function applyState(string|int|null $w, int|string $wtimeout, ?bool $journal): void
    {
        // Clear any existing dynamic properties first (for __set_state reuse)
        unset($this->w, $this->j, $this->wtimeout);

        if ($w !== null) {
            $this->w = $w;
        }

        if ($journal !== null) {
            $this->j = $journal;
        }

        if ($wtimeout === 0 || $wtimeout === '0') {
            return;
        }

        $this->wtimeout = $wtimeout;
    }
}
