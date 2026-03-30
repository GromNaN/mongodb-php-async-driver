<?php declare(strict_types=1);

namespace MongoDB\Driver;

final class Query
{
    private array|object $filter;
    private array $options;

    public function __construct(array|object $filter, ?array $queryOptions = null)
    {
        $this->filter = $filter;
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
}
