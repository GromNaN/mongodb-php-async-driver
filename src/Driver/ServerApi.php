<?php declare(strict_types=1);

namespace MongoDB\Driver;

final class ServerApi implements \MongoDB\BSON\Serializable
{
    public const string V1 = '1';

    private string $version;
    private ?bool $strict;
    private ?bool $deprecationErrors;

    public function __construct(string $version, ?bool $strict = null, ?bool $deprecationErrors = null)
    {
        $validVersions = [self::V1];

        if (!in_array($version, $validVersions, true)) {
            throw new Exception\InvalidArgumentException(
                sprintf('Invalid version "%s" given for ServerApi', $version)
            );
        }

        $this->version = $version;
        $this->strict = $strict;
        $this->deprecationErrors = $deprecationErrors;
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

    public function bsonSerialize(): \stdClass
    {
        $doc = new \stdClass();
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
