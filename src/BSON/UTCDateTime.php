<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use RuntimeException;
use Stringable;

use function get_debug_type;
use function intdiv;
use function is_int;
use function is_object;
use function is_string;
use function microtime;
use function preg_match;
use function sprintf;

final class UTCDateTime implements UTCDateTimeInterface, JsonSerializable, Type, Stringable
{
    /** Milliseconds since Unix epoch, stored as a string integer. */
    public readonly string $milliseconds;

    /**
     * @param int|Int64|DateTimeInterface|null $milliseconds
     *   - null             : current time in milliseconds
     *   - int              : milliseconds since epoch
     *   - Int64            : milliseconds since epoch (extracted as int)
     *   - DateTimeInterface: converted to milliseconds
     */
    public function __construct(mixed $milliseconds = null)
    {
        if ($milliseconds === null) {
            $this->milliseconds = (string) (int) (microtime(as_float: true) * 1000);

            return;
        }

        if ($milliseconds instanceof Int64) {
            $this->milliseconds = (string) (int) (string) $milliseconds;

            return;
        }

        if ($milliseconds instanceof DateTimeInterface) {
            // Compute ms since epoch as sec * 1000 + ms_part.
            // String concatenation via format('Uv') is wrong for pre-epoch dates.
            $this->milliseconds = (string) ((int) $milliseconds->format('U') * 1000 + (int) $milliseconds->format('v'));

            return;
        }

        if (is_int($milliseconds)) {
            $this->milliseconds = (string) $milliseconds;

            return;
        }

        if (is_object($milliseconds)) {
            throw new InvalidArgumentException(
                sprintf('Expected instance of DateTimeInterface or MongoDB\BSON\Int64, %s given', get_debug_type($milliseconds)),
            );
        }

        throw new InvalidArgumentException(
            sprintf('Expected integer or object, %s given', get_debug_type($milliseconds)),
        );
    }

    // ------------------------------------------------------------------
    // UTCDateTimeInterface
    // ------------------------------------------------------------------

    public function toDateTime(): DateTime
    {
        [$seconds, $microseconds] = self::msToSecondsAndMicros((int) $this->milliseconds);

        $dt = DateTime::createFromFormat(
            'U u',
            sprintf('%d %06d', $seconds, $microseconds),
        );

        if ($dt === false) {
            // @codeCoverageIgnore
            throw new RuntimeException('Failed to create DateTime from UTCDateTime.');
        }

        return $dt;
    }

    public function toDateTimeImmutable(): DateTimeImmutable
    {
        [$seconds, $microseconds] = self::msToSecondsAndMicros((int) $this->milliseconds);

        $dt = DateTimeImmutable::createFromFormat(
            'U u',
            sprintf('%d %06d', $seconds, $microseconds),
        );

        if ($dt === false) {
            // @codeCoverageIgnore
            throw new RuntimeException('Failed to create DateTimeImmutable from UTCDateTime.');
        }

        return $dt;
    }

    public function getMilliseconds(): int|Int64
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
        return ['milliseconds' => $this->milliseconds];
    }

    public function __unserialize(array $data): void
    {
        $this->milliseconds = self::parseInitMilliseconds($data, 'MongoDB\BSON\UTCDateTime');
    }

    public static function __set_state(array $properties): static
    {
        return new static(
            (int) self::parseInitMilliseconds($properties, 'MongoDB\BSON\UTCDateTime'),
        );
    }

    public function __debugInfo(): array
    {
        return ['milliseconds' => $this->milliseconds];
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /** @return array{int, int}  [$seconds, $microseconds] */
    private static function msToSecondsAndMicros(int $ms): array
    {
        $seconds   = intdiv($ms, 1000);
        $remainder = $ms % 1000;

        // PHP's % for negative values rounds toward zero; adjust to always have
        // a non-negative remainder (floor division semantics)
        if ($remainder < 0) {
            $seconds--;
            $remainder += 1000;
        }

        return [$seconds, $remainder * 1000];
    }

    private static function parseInitMilliseconds(array $data, string $context): string
    {
        $value = $data['milliseconds'] ?? null;

        if ($value === null || (! is_int($value) && ! is_string($value))) {
            throw new InvalidArgumentException(
                $context . ' initialization requires "milliseconds" integer or numeric string field',
            );
        }

        if (is_int($value)) {
            return (string) $value;
        }

        // Validate: integer string, no decimal, within 64-bit signed range
        if (! preg_match('/^-?\d+$/', $value)) {
            throw new InvalidArgumentException(
                sprintf('Error parsing "%s" as 64-bit integer for %s initialization', $value, $context),
            );
        }

        $int = (int) $value;
        if ((string) $int !== $value) {
            throw new InvalidArgumentException(
                sprintf('Error parsing "%s" as 64-bit integer for %s initialization', $value, $context),
            );
        }

        return $value;
    }
}
