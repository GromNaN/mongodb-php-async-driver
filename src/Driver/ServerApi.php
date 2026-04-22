<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use MongoDB\BSON\Serializable;
use stdClass;

use function array_key_exists;
use function in_array;
use function is_bool;
use function is_string;
use function sprintf;

final class ServerApi implements Serializable
{
    public const string V1 = '1';

    public function __construct(private string $version, private ?bool $strict = null, private ?bool $deprecationErrors = null)
    {
        $validVersions = [self::V1];

        if (! in_array($version, $validVersions, true)) {
            throw new Exception\InvalidArgumentException(
                sprintf('Server API version "%s" is not supported in this driver version', $version),
            );
        }
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function isStrict(): ?bool
    {
        return $this->strict;
    }

    public function isDeprecationErrors(): ?bool
    {
        return $this->deprecationErrors;
    }

    public function bsonSerialize(): stdClass
    {
        $doc = new stdClass();
        $doc->version = $this->version;

        if ($this->strict !== null) {
            $doc->strict = $this->strict;
        }

        if ($this->deprecationErrors !== null) {
            $doc->deprecationErrors = $this->deprecationErrors;
        }

        return $doc;
    }

    public function __serialize(): array
    {
        return [
            'version' => $this->version,
            'strict' => $this->strict,
            'deprecationErrors' => $this->deprecationErrors,
        ];
    }

    public function __unserialize(array $data): void
    {
        if (! is_string($data['version'] ?? null)) {
            throw new Exception\InvalidArgumentException(
                'MongoDB\Driver\ServerApi initialization requires "version" field to be string',
            );
        }

        if (array_key_exists('strict', $data) && $data['strict'] !== null && ! is_bool($data['strict'])) {
            throw new Exception\InvalidArgumentException(
                'MongoDB\Driver\ServerApi initialization requires "strict" field to be bool or null',
            );
        }

        if (array_key_exists('deprecationErrors', $data) && $data['deprecationErrors'] !== null && ! is_bool($data['deprecationErrors'])) {
            throw new Exception\InvalidArgumentException(
                'MongoDB\Driver\ServerApi initialization requires "deprecationErrors" field to be bool or null',
            );
        }

        $this->version           = $data['version'];
        $this->strict            = $data['strict'] ?? null;
        $this->deprecationErrors = $data['deprecationErrors'] ?? null;
    }

    public static function __set_state(array $properties): static
    {
        if (! is_string($properties['version'] ?? null)) {
            throw new Exception\InvalidArgumentException(
                'MongoDB\Driver\ServerApi initialization requires "version" field to be string',
            );
        }

        if (array_key_exists('strict', $properties) && $properties['strict'] !== null && ! is_bool($properties['strict'])) {
            throw new Exception\InvalidArgumentException(
                'MongoDB\Driver\ServerApi initialization requires "strict" field to be bool or null',
            );
        }

        if (array_key_exists('deprecationErrors', $properties) && $properties['deprecationErrors'] !== null && ! is_bool($properties['deprecationErrors'])) {
            throw new Exception\InvalidArgumentException(
                'MongoDB\Driver\ServerApi initialization requires "deprecationErrors" field to be bool or null',
            );
        }

        return new static(
            $properties['version'],
            $properties['strict'] ?? null,
            $properties['deprecationErrors'] ?? null,
        );
    }

    public function __debugInfo(): array
    {
        return [
            'version'           => $this->version,
            'strict'            => $this->strict,
            'deprecationErrors' => $this->deprecationErrors,
        ];
    }
}
