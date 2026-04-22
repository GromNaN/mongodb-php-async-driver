<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use JsonSerializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use Stringable;

use function implode;
use function is_string;
use function sort;
use function sprintf;
use function str_contains;
use function str_split;

final class Regex implements RegexInterface, JsonSerializable, Type, Stringable
{
    public readonly string $pattern;
    public readonly string $flags;

    final public function __construct(string $pattern, string $flags = '')
    {
        if (str_contains($pattern, "\0")) {
            throw new InvalidArgumentException('Pattern cannot contain null bytes');
        }

        if (str_contains($flags, "\0")) {
            throw new InvalidArgumentException('Flags cannot contain null bytes');
        }

        $this->pattern = $pattern;
        $this->flags   = self::sortFlags($flags);
    }

    // ------------------------------------------------------------------
    // RegexInterface
    // ------------------------------------------------------------------

    final public function getPattern(): string
    {
        return $this->pattern;
    }

    final public function getFlags(): string
    {
        return $this->flags;
    }

    final public function __toString(): string
    {
        return sprintf('/%s/%s', $this->pattern, $this->flags);
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    final public function jsonSerialize(): mixed
    {
        return [
            '$regex'   => $this->pattern,
            '$options' => $this->flags,
        ];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    final public function __serialize(): array
    {
        return [
            'pattern' => $this->pattern,
            'flags'   => $this->flags,
        ];
    }

    final public function __unserialize(array $data): void
    {
        self::validateInitFields($data);

        if (str_contains($data['pattern'], "\0")) {
            throw new InvalidArgumentException('Pattern cannot contain null bytes');
        }

        if (str_contains($data['flags'], "\0")) {
            throw new InvalidArgumentException('Flags cannot contain null bytes');
        }

        $this->pattern = $data['pattern'];
        $this->flags   = self::sortFlags($data['flags']);
    }

    final public static function __set_state(array $properties): static
    {
        self::validateInitFields($properties);

        return new static($properties['pattern'], $properties['flags']);
    }

    public function __debugInfo(): array
    {
        return [
            'pattern' => $this->pattern,
            'flags'   => $this->flags,
        ];
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private static function validateInitFields(array $data): void
    {
        if (
            ! isset($data['pattern'], $data['flags']) ||
            ! is_string($data['pattern']) ||
            ! is_string($data['flags'])
        ) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\Regex initialization requires "pattern" and "flags" string fields',
            );
        }
    }

    private static function sortFlags(string $flags): string
    {
        if ($flags === '') {
            return '';
        }

        $chars = str_split($flags);
        sort($chars);

        return implode('', $chars);
    }
}
