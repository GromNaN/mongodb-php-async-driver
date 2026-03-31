<?php
declare(strict_types=1);

namespace MongoDB\Driver;

final class Query
{
    private array $options;

    public function __construct(private array|object $filter, ?array $queryOptions = null)
    {
        $this->options = $queryOptions ?? [];
    }

    /** @internal */
    public function getFilter(): array|object
    {
        return $this->filter;
    }

    /** @internal */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function __debugInfo(): array
    {
        return [
            'filter'      => $this->filter,
            'options'     => $this->options ?: null,
            'readConcern' => null,
        ];
    }
}
