<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use MongoDB\BSON\Serializable;
use stdClass;

use function in_array;
use function sprintf;

final class ServerApi implements Serializable
{
    public const string V1 = '1';

    public function __construct(private string $version, private ?bool $strict = null, private ?bool $deprecationErrors = null)
    {
        $validVersions = [self::V1];

        if (! in_array($version, $validVersions, true)) {
            throw new Exception\InvalidArgumentException(
                sprintf('Invalid version "%s" given for ServerApi', $version),
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
        $this->version = $data['version'];
        $this->strict = $data['strict'] ?? null;
        $this->deprecationErrors = $data['deprecationErrors'] ?? null;
    }
}
