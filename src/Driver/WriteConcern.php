<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use MongoDB\BSON\Serializable;
use stdClass;

use function is_int;

final class WriteConcern implements Serializable
{
    public const string MAJORITY = 'majority';

    private int $wtimeout;
    private bool $isDefaultConcern = false;

    public function __construct(private string|int $w, ?int $wtimeout = null, private ?bool $journal = null)
    {
        if (is_int($w) && $w < -1) {
            throw new Exception\InvalidArgumentException('w cannot be less than -1');
        }

        if ($wtimeout !== null && $wtimeout < 0) {
            throw new Exception\InvalidArgumentException('wtimeout cannot be negative');
        }

        $this->wtimeout = $wtimeout ?? 0;
    }

    /** @internal Create a WriteConcern that reports isDefault() = true (driver default, not user-specified). */
    public static function createDefault(): self
    {
        $wc = new self(1);
        $wc->isDefaultConcern = true;

        return $wc;
    }

    public function getW(): string|int|null
    {
        return $this->w;
    }

    public function getWtimeout(): int
    {
        return $this->wtimeout;
    }

    public function getJournal(): ?bool
    {
        return $this->journal;
    }

    public function isDefault(): bool
    {
        return $this->isDefaultConcern;
    }

    public function bsonSerialize(): stdClass
    {
        $doc = new stdClass();
        $doc->w = $this->w;

        if ($this->wtimeout !== 0) {
            $doc->wtimeout = $this->wtimeout;
        }

        if ($this->journal !== null) {
            $doc->j = $this->journal;
        }

        return $doc;
    }

    public function __serialize(): array
    {
        return [
            'w' => $this->w,
            'wtimeout' => $this->wtimeout,
            'journal' => $this->journal,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->w = $data['w'];
        $this->wtimeout = $data['wtimeout'] ?? 0;
        $this->journal = $data['journal'] ?? null;
    }

    public static function __set_state(array $properties): static
    {
        return new static(
            $properties['w'],
            $properties['wtimeout'] ?? null,
            $properties['journal'] ?? null,
        );
    }
}
