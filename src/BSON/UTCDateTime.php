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
    public readonly string $milliseconds;

    /**
     * @param int|DateTimeInterface|null $milliseconds
     *   - null  : current time in milliseconds
     *   - int   : milliseconds since epoch
     *   - DateTimeInterface : converted to milliseconds
     */
    public function __construct(int|DateTimeInterface|null $milliseconds = null)
    {
        if ($milliseconds === null) {
            $this->milliseconds = (string) (microtime(true) * 1000);
        } elseif ($milliseconds instanceof DateTimeInterface) {
            // DateTimeInterface stores microseconds; convert to ms.
            $this->milliseconds = (string) ($milliseconds->format('Uv'));
        } else {
            $this->milliseconds = (string) $milliseconds;
        }
    }

    // ------------------------------------------------------------------
    // UTCDateTimeInterface
    // ------------------------------------------------------------------

    public function toDateTime(): DateTimeImmutable
    {
        $seconds      = intdiv((int) $this->milliseconds, 1000);
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
        return (int) $this->milliseconds;
    }

    public function __toString(): string
    {
        return $this->milliseconds;
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    public function jsonSerialize(): mixed
    {
        return [
            '$date' => [
                '$numberLong' => $this->milliseconds,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    public function __serialize(): array
    {
        return ['ms' => (int) $this->milliseconds];
    }

    public function __unserialize(array $data): void
    {
        $this->milliseconds = (string) $data['ms'];
    }

    public static function __set_state(array $properties): static
    {
        return new static($properties['ms'] ?? $properties['milliseconds']);
    }

    public function __debugInfo(): array
    {
        return ['milliseconds' => $this->milliseconds];
    }
}
