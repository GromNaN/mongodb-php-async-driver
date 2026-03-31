<?php
declare(strict_types=1);

namespace MongoDB\Driver;

final class Command
{
    private array $options;

    public function __construct(private array|object $document, ?array $commandOptions = null)
    {
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

    public function __debugInfo(): array
    {
        return ['command' => $this->document];
    }
}
