<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use JsonSerializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Exception\UnexpectedValueException;
use Stringable;

use function get_debug_type;
use function is_array;
use function is_object;
use function is_string;
use function sprintf;
use function str_contains;

final class Javascript implements JavascriptInterface, JsonSerializable, Type, Stringable
{
    public readonly string $code;
    public readonly ?object $scope;

    public function __construct(string $code, array|object|null $scope = null)
    {
        if (str_contains($code, "\0")) {
            throw new InvalidArgumentException('Code cannot contain null bytes');
        }

        if ($scope instanceof PackedArray) {
            throw new UnexpectedValueException(
                'MongoDB\BSON\PackedArray cannot be serialized as a root document',
            );
        }

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

        if (str_contains($data['code'], "\0")) {
            throw new InvalidArgumentException('Code cannot contain null bytes');
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

        if (str_contains($properties['code'], "\0")) {
            throw new InvalidArgumentException('Code cannot contain null bytes');
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
