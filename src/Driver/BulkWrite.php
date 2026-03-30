<?php declare(strict_types=1);

namespace MongoDB\Driver;

final class BulkWrite implements \Countable
{
    private array $operations = [];
    private array $options;

    public function __construct(?array $options = null)
    {
        $this->options = array_merge(['ordered' => true], $options ?? []);
    }

    public function count(): int
    {
        return count($this->operations);
    }

    public function insert(array|object $document): mixed
    {
        if (is_array($document)) {
            if (!isset($document['_id'])) {
                $document['_id'] = new \MongoDB\BSON\ObjectId();
            }
            $id = $document['_id'];
        } else {
            if (!isset($document->_id)) {
                $document->_id = new \MongoDB\BSON\ObjectId();
            }
            $id = $document->_id;
        }

        $this->operations[] = ['insert', $document, null];

        return $id;
    }

    public function update(array|object $filter, array|object $newObj, ?array $updateOptions = null): void
    {
        $this->operations[] = ['update', $filter, $newObj, $updateOptions ?? []];
    }

    public function delete(array|object $filter, ?array $deleteOptions = null): void
    {
        $this->operations[] = ['delete', $filter, $deleteOptions ?? []];
    }

    /** @internal */
    public function getOperations(): array
    {
        return $this->operations;
    }

    /** @internal */
    public function getOptions(): array
    {
        return $this->options;
    }
}
