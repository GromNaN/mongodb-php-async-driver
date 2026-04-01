<?php

declare(strict_types=1);

namespace MongoDB\Driver;

use Countable;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\InvalidArgumentException;

use function array_is_list;
use function array_key_first;
use function count;
use function is_array;
use function str_starts_with;

final class BulkWriteCommand implements Countable
{
    /** @var list<array{ns: string}> */
    private array $nsInfo = [];

    /** @var list<array> */
    private array $ops = [];

    /** @var array<int, mixed> Map of op index → inserted _id */
    private array $insertedIds = [];

    private array $options;

    public function __construct(?array $options = null)
    {
        $this->options = $options ?? [];
    }

    public function count(): int
    {
        return count($this->ops);
    }

    /**
     * Adds an insertOne operation and returns the document's _id.
     */
    public function insertOne(string $namespace, array|object $document): mixed
    {
        if (is_array($document)) {
            if (! isset($document['_id'])) {
                $document['_id'] = new ObjectId();
            }

            $id = $document['_id'];
        } else {
            if (! isset($document->_id)) {
                $document->_id = new ObjectId();
            }

            $id = $document->_id;
        }

        $idx = count($this->ops);
        $this->insertedIds[$idx] = $id;
        $this->ops[] = ['insert' => $this->getNsIndex($namespace), 'document' => $document];

        return $id;
    }

    public function deleteOne(string $namespace, array|object $filter, ?array $options = null): void
    {
        $op = ['delete' => $this->getNsIndex($namespace), 'filter' => $filter, 'multi' => false];
        $this->applyDeleteOptions($op, $options);
        $this->ops[] = $op;
    }

    public function deleteMany(string $namespace, array|object $filter, ?array $options = null): void
    {
        $op = ['delete' => $this->getNsIndex($namespace), 'filter' => $filter, 'multi' => true];
        $this->applyDeleteOptions($op, $options);
        $this->ops[] = $op;
    }

    public function replaceOne(
        string $namespace,
        array|object $filter,
        array|object $replacement,
        ?array $options = null,
    ): void {
        $this->validateReplacement($replacement);
        $op = ['update' => $this->getNsIndex($namespace), 'filter' => $filter, 'updateMods' => $replacement, 'multi' => false];
        $this->applyUpdateOptions($op, $options);
        $this->ops[] = $op;
    }

    public function updateOne(
        string $namespace,
        array|object $filter,
        array|object $update,
        ?array $options = null,
    ): void {
        $this->validateUpdate($update);
        $op = ['update' => $this->getNsIndex($namespace), 'filter' => $filter, 'updateMods' => $update, 'multi' => false];
        $this->applyUpdateOptions($op, $options);
        $this->ops[] = $op;
    }

    public function updateMany(
        string $namespace,
        array|object $filter,
        array|object $update,
        ?array $options = null,
    ): void {
        $this->validateUpdate($update);
        $op = ['update' => $this->getNsIndex($namespace), 'filter' => $filter, 'updateMods' => $update, 'multi' => true];
        $this->applyUpdateOptions($op, $options);
        $this->ops[] = $op;
    }

    /** @internal */
    public function getNsInfo(): array
    {
        return $this->nsInfo;
    }

    /** @internal */
    public function getOps(): array
    {
        return $this->ops;
    }

    /** @internal */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** @internal */
    public function getInsertedIds(): array
    {
        return $this->insertedIds;
    }

    // -------------------------------------------------------------------------

    private function getNsIndex(string $namespace): int
    {
        foreach ($this->nsInfo as $i => $entry) {
            if ($entry['ns'] === $namespace) {
                return $i;
            }
        }

        $this->nsInfo[] = ['ns' => $namespace];

        return count($this->nsInfo) - 1;
    }

    private function applyDeleteOptions(array &$op, ?array $options): void
    {
        if (isset($options['collation'])) {
            $op['collation'] = $options['collation'];
        }

        if (! isset($options['hint'])) {
            return;
        }

        $op['hint'] = $options['hint'];
    }

    private function applyUpdateOptions(array &$op, ?array $options): void
    {
        foreach (['arrayFilters', 'collation', 'hint', 'sort'] as $key) {
            if (! isset($options[$key])) {
                continue;
            }

            $op[$key] = $options[$key];
        }

        if (! isset($options['upsert'])) {
            return;
        }

        $op['upsert'] = (bool) $options['upsert'];
    }

    private function validateUpdate(array|object $update): void
    {
        // Pipelines (list arrays) are not validated — the server checks them.
        if (is_array($update) && array_is_list($update)) {
            return;
        }

        $arr      = is_array($update) ? $update : (array) $update;
        $firstKey = (string) (array_key_first($arr) ?? '');

        if ($firstKey === '') {
            throw new InvalidArgumentException('Update document must not be empty');
        }

        if (! str_starts_with($firstKey, '$')) {
            throw new InvalidArgumentException(
                'First key in update document must be an update operator; use replaceOne() for replacements',
            );
        }
    }

    private function validateReplacement(array|object $replacement): void
    {
        $arr      = is_array($replacement) ? $replacement : (array) $replacement;
        $firstKey = (string) (array_key_first($arr) ?? '');

        if ($firstKey !== '' && str_starts_with($firstKey, '$')) {
            throw new InvalidArgumentException(
                'Replacement document keys must not start with "$"; use updateOne() or updateMany() for updates',
            );
        }
    }
}
