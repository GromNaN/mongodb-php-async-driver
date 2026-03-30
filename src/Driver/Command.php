<?php declare(strict_types=1);

namespace MongoDB\Driver;

final class Command
{
    private array|object $document;
    private array $options;

    public function __construct(array|object $document, ?array $commandOptions = null)
    {
        $this->document = $document;
        $this->options = $commandOptions ?? [];
    }

    /** @internal */
    public function getDocument(): array|object
    {
        return $this->document;
    }

    /** @internal */
    public function getOptions(): array
    {
        return $this->options;
    }
}
