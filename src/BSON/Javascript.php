<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use JsonSerializable;
use Stringable;

use function is_array;

final class Javascript implements JavascriptInterface, JsonSerializable, Type, Stringable
{
    private ?object $scope;

    public function __construct(private string $code, array|object|null $scope = null)
    {
        if (is_array($scope)) {
            $this->scope = (object) $scope;
        } else {
            $this->scope = $scope;
        }
    }

    // ------------------------------------------------------------------
    // JavascriptInterface
    // ------------------------------------------------------------------

    public function getCode(): string
    {
        return $this->code;
    }

    public function getScope(): ?object
    {
        return $this->scope;
    }

    public function __toString(): string
    {
        return $this->code;
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    public function jsonSerialize(): mixed
    {
        if ($this->scope === null) {
            return ['$code' => $this->code];
        }

        return [
            '$code'  => $this->code,
            '$scope' => $this->scope,
        ];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    public function __serialize(): array
    {
        return [
            'code'  => $this->code,
            'scope' => $this->scope,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->code  = $data['code'];
        $this->scope = $data['scope'];
    }

    public static function __set_state(array $properties): static
    {
        return new static($properties['code'], $properties['scope'] ?? null);
    }

    public function __debugInfo(): array
    {
        return [
            'code'  => $this->code,
            'scope' => $this->scope,
        ];
    }
}
