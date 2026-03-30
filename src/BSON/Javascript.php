<?php

declare(strict_types=1);

namespace MongoDB\BSON;

final class Javascript implements JavascriptInterface, \JsonSerializable, Type, \Stringable
{
    private string  $code;
    private ?object $scope;

    /**
     * @param array|object|null $scope
     */
    public function __construct(string $code, array|object|null $scope = null)
    {
        $this->code = $code;

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
}
