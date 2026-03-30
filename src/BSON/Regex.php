<?php

declare(strict_types=1);

namespace MongoDB\BSON;

final class Regex implements RegexInterface, \JsonSerializable, Type, \Stringable
{
    private string $pattern;
    private string $flags;

    public function __construct(string $pattern, string $flags = '')
    {
        $this->pattern = $pattern;
        $this->flags   = $flags;
    }

    // ------------------------------------------------------------------
    // RegexInterface
    // ------------------------------------------------------------------

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function getFlags(): string
    {
        return $this->flags;
    }

    public function __toString(): string
    {
        return sprintf('/%s/%s', $this->pattern, $this->flags);
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    public function jsonSerialize(): mixed
    {
        return [
            '$regularExpression' => [
                'pattern' => $this->pattern,
                'options' => $this->flags,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    public function __serialize(): array
    {
        return [
            'pattern' => $this->pattern,
            'flags'   => $this->flags,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->pattern = $data['pattern'];
        $this->flags   = $data['flags'];
    }

    public static function __set_state(array $properties): static
    {
        return new static($properties['pattern'], $properties['flags'] ?? '');
    }
}
