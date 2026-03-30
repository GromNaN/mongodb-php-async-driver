<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use RuntimeException;
use Stringable;

use function intdiv;
use function microtime;
use function sprintf;

final class UTCDateTime implements UTCDateTimeInterface, JsonSerializable, Type, Stringable
{
    /** Milliseconds since Unix epoch. */
    private int $milliseconds;

    /**
     * @param int|DateTimeInterface|null $milliseconds
     *   - null  : current time in milliseconds
     *   - int   : milliseconds since epoch
     *   - DateTimeInterface : converted to milliseconds
     */
    public function __construct(int|DateTimeInterface|null $milliseconds = null)
    {
        if ($milliseconds === null) {
            $this->milliseconds = (int) (microtime(true) * 1000);
        } elseif ($milliseconds instanceof DateTimeInterface) {
            // DateTimeInterface stores microseconds; convert to ms.
            $this->milliseconds = (int) ($milliseconds->format('Uv'));
        } else {
            $this->milliseconds = $milliseconds;
        }
    }

    // ------------------------------------------------------------------
    // UTCDateTimeInterface
    // ------------------------------------------------------------------

    public function toDateTime(): DateTimeImmutable
    {
        $seconds      = intdiv($this->milliseconds, 1000);
        $microseconds = ($this->milliseconds % 1000) * 1000;

        $dt = DateTimeImmutable::createFromFormat(
            'U u',
            sprintf('%d %06d', $seconds, $microseconds),
        );

        if ($dt === false) {
            throw new RuntimeException('Failed to create DateTimeImmutable from UTCDateTime.');
        }

        return $dt;
    }

    public function getMilliseconds(): int
    {
        return $this->milliseconds;
    }

    public function __toString(): string
    {
        return (string) $this->milliseconds;
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    public function jsonSerialize(): mixed
    {
        return [
            '$date' => [
                '$numberLong' => (string) $this->milliseconds,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    public function __serialize(): array
    {
        return ['ms' => $this->milliseconds];
    }

    public function __unserialize(array $data): void
    {
        $this->milliseconds = $data['ms'];
    }

    public static function __set_state(array $properties): static
    {
        return new static($properties['ms'] ?? $properties['milliseconds']);
    }
}
