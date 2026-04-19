<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use JsonSerializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use Stringable;

use function get_debug_type;
use function is_array;
use function is_object;
use function is_string;
use function sprintf;

final class Javascript implements JavascriptInterface, JsonSerializable, Type, Stringable
{
    public readonly ?object $scope;

    public function __construct(public readonly string $code, array|object|null $scope = null)
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
        if (! isset($data['code']) || ! is_string($data['code'])) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\Javascript initialization requires "code" string field',
            );
        }

        $scope = $data['scope'] ?? null;

        if ($scope !== null && ! is_array($scope) && ! is_object($scope)) {
            throw new InvalidArgumentException(
                sprintf('Expected scope to be array or object, %s given', get_debug_type($scope)),
            );
        }

        $this->code  = $data['code'];
        $this->scope = is_array($scope) ? (object) $scope : $scope;
    }

    public static function __set_state(array $properties): static
    {
        if (! isset($properties['code']) || ! is_string($properties['code'])) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\Javascript initialization requires "code" string field',
            );
        }

        $scope = $properties['scope'] ?? null;

        if ($scope !== null && ! is_array($scope) && ! is_object($scope)) {
            throw new InvalidArgumentException(
                sprintf('Expected scope to be array or object, %s given', get_debug_type($scope)),
            );
        }

        return new static($properties['code'], $scope);
    }

    public function __debugInfo(): array
    {
        return [
            'code'  => $this->code,
            'scope' => $this->scope,
        ];
    }
}
