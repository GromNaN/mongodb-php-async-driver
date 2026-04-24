<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use Closure;
use MongoDB\BSON\Int64;
use MongoDB\BSON\Unserializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Exception\LogicException;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Internal\BSON\TypeMapper;
use ReflectionClass;
use stdClass;
use Throwable;

use function array_is_list;
use function array_key_exists;
use function class_exists;
use function count;
use function interface_exists;
use function is_array;
use function is_string;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strpos;
use function substr;

final class Cursor implements CursorInterface
{
    /** Raw-decoded items in the current batch (default BsonDecoder output: stdClass/arrays). */
    private array $items = [];

    /** Position within the current batch (0-indexed). */
    private int $position = 0;

    /** Total number of items from all previous batches (for global key tracking). */
    private int $globalOffset = 0;

    /** Current BSON type map for decoding items. */
    private array $typeMap = [];

    /** Server-side cursor ID (0 means no more batches available). */
    private int $cursorId = 0;

    /** The server this cursor is bound to. */
    private ?Server $server = null;

    /** Namespace string ("db.collection") for getMore commands. */
    private string $namespace = '';

    /**
     * Closure to fetch the next batch.
     * Signature: fn(int $cursorId, string $ns): array{0: list<array|object>, 1: int}
     */
    private ?Closure $getMoreFn = null;

    /** True when all batches have been fetched and the cursor cannot fetch more. */
    private bool $exhausted = false;

    /** Last exception that caused abnormal exhaustion; re-thrown on subsequent next() calls. */
    private ?Throwable $lastError = null;

    /** True after the first call to next(), preventing rewind(). */
    private bool $started = false;

    /** @internal Debug context — database name for __debugInfo(). */
    private string $debugDatabase = '';

    /** @internal Debug context — command that produced this cursor (null for query cursors). */
    private ?Command $debugCommand = null;

    /** @internal Debug context — query that produced this cursor (null for command cursors). */
    private ?Query $debugQuery = null;

    private function __construct()
    {
    }

    /** @internal */
    public static function createFromCommandResult(
        array $items,
        int|Int64 $cursorId,
        string $namespace,
        Server $server,
        array $typeMap = [],
        ?Closure $getMoreFn = null,
        string $database = '',
        ?Command $command = null,
    ): self {
        $instance = new self();
        $instance->items = $items;
        $instance->cursorId = $cursorId instanceof Int64 ? (int) (string) $cursorId : $cursorId;
        $instance->namespace = $namespace;
        $instance->server = $server;
        $instance->typeMap = $typeMap;
        $instance->getMoreFn = $getMoreFn;
        $instance->exhausted = ($instance->cursorId === 0);
        $instance->debugDatabase = $database;
        $instance->debugCommand = $command;

        return $instance;
    }

    /** @internal */
    public static function createFromArray(array $items, Server $server, string $database = '', ?Command $command = null): self
    {
        $instance = new self();
        $instance->items = $items;
        $instance->cursorId = 0;
        $instance->server = $server;
        $instance->exhausted = true;
        $instance->debugDatabase = $database;
        $instance->debugCommand = $command;

        return $instance;
    }

    public function current(): array|object|null
    {
        if (! $this->valid()) {
            return null;
        }

        $item = $this->items[$this->position];

        return $this->typeMap !== [] ? TypeMapper::apply($item, $this->typeMap, 'root') : $item;
    }

    public function getId(): Int64
    {
        return new Int64($this->cursorId);
    }

    public function getServer(): Server
    {
        if ($this->server === null) {
            throw new RuntimeException('Cursor has no associated server');
        }

        return $this->server;
    }

    public function isDead(): bool
    {
        return $this->exhausted && $this->position >= count($this->items);
    }

    public function key(): ?int
    {
        if (! $this->valid()) {
            return null;
        }

        return $this->globalOffset + $this->position;
    }

    public function next(): void
    {
        if (! $this->valid()) {
            if ($this->exhausted) {
                if ($this->lastError !== null) {
                    throw $this->lastError;
                }

                // Regular cursor fully consumed: superfluous iteration.
                throw new RuntimeException('Cannot advance a completed or failed cursor.');
            }

            // Tailable cursor: buffer temporarily empty but cursor still alive.
            // Fetch the next batch (may return empty; caller should check valid()/current()).
            $this->started = true;
            $this->fetchMore();

            return;
        }

        $this->started = true;
        $this->position++;

        // Still within the current batch — nothing more to do.
        if ($this->position < count($this->items)) {
            return;
        }

        // Batch exhausted. Fetch more if cursor is still alive.
        if (! $this->exhausted && $this->getMoreFn !== null && $this->cursorId !== 0) {
            $this->fetchMore();
        } else {
            $this->exhausted = true;
        }
    }

    public function rewind(): void
    {
        if ($this->started && $this->position > 0) {
            throw new LogicException('Cursors cannot rewind after starting iteration');
        }

        $this->position = 0;
    }

    public function setTypeMap(array $typemap): void
    {
        $this->validateTypeMap($typemap);
        $this->typeMap = $typemap;
    }

    public function toArray(): array
    {
        $this->rewind();
        $result = [];
        while ($this->valid()) {
            $result[] = $this->current();
            $this->next();
        }

        return $result;
    }

    public function valid(): bool
    {
        return isset($this->items[$this->position]);
    }

    public function __debugInfo(): array
    {
        // Extract collection from namespace ("db.collection" → "collection", or null for commands).
        $collection = null;
        if ($this->namespace !== '' && str_contains($this->namespace, '.')) {
            $collection = substr($this->namespace, strpos($this->namespace, '.') + 1);
        }

        return [
            'database'        => $this->debugDatabase !== '' ? $this->debugDatabase : null,
            'collection'      => $collection,
            'query'           => $this->debugQuery,
            'command'         => $this->debugCommand,
            'readPreference'  => null,
            'session'         => null,
            'isDead'          => $this->isDead(),
            'currentIndex'    => $this->globalOffset + $this->position,
            'currentDocument' => null,
            'server'          => $this->server,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function fetchMore(): void
    {
        try {
            [$newItems, $newCursorId] = ($this->getMoreFn)($this->cursorId, $this->namespace);
            $this->globalOffset += count($this->items);
            $this->items = $newItems;
            $this->position = 0;
            $this->cursorId = $newCursorId;
            $this->exhausted = ($newCursorId === 0);
        } catch (Throwable $e) {
            $this->exhausted  = true;
            $this->lastError  = $e;

            throw $e;
        }
    }

    /**
     * Validate a type map array, throwing InvalidArgumentException for invalid entries.
     *
     * Valid top-level keys: 'root', 'document', 'array', 'fieldPaths'.
     * Valid values for root/document/array: 'array', 'object', 'bson', or a valid class name.
     */
    private function validateTypeMap(array $typemap): void
    {
        foreach (['root', 'document', 'array'] as $key) {
            if (! isset($typemap[$key])) {
                continue;
            }

            $this->validateTypeMapValue($typemap[$key]);
        }

        if (! array_key_exists('fieldPaths', $typemap)) {
            return;
        }

        $this->validateFieldPaths($typemap['fieldPaths']);
    }

    private function validateTypeMapValue(mixed $value): void
    {
        if (! is_string($value)) {
            return;
        }

        // Built-in type tokens are always valid.
        if ($value === 'array' || $value === 'object' || $value === stdClass::class || $value === 'bson') {
            return;
        }

        if (! class_exists($value) && ! interface_exists($value)) {
            throw new InvalidArgumentException(sprintf('Class %s does not exist', $value));
        }

        $rc = new ReflectionClass($value);

        if ($rc->implementsInterface(Unserializable::class)) {
            // Unserializable classes are always allowed: newInstanceWithoutConstructor bypasses
            // the constructor, so private constructors are valid (e.g. named constructors pattern).
            return;
        }

        if (! $rc->isInstantiable()) {
            $prefix = $rc->isInterface() ? 'Interface' : 'Class';

            throw new InvalidArgumentException(sprintf('%s %s is not instantiatable', $prefix, $value));
        }

        throw new InvalidArgumentException(
            'Class ' . $value . ' does not implement MongoDB\BSON\Unserializable',
        );
    }

    private function validateFieldPaths(mixed $fieldPaths): void
    {
        if (! is_array($fieldPaths)) {
            throw new InvalidArgumentException("The 'fieldPaths' element is not an array");
        }

        if (array_is_list($fieldPaths)) {
            throw new InvalidArgumentException("The 'fieldPaths' element is not an associative array");
        }

        foreach ($fieldPaths as $key => $value) {
            $key = (string) $key;

            if ($key === '') {
                throw new InvalidArgumentException("The 'fieldPaths' element may not be an empty string");
            }

            if (str_starts_with($key, '.')) {
                throw new InvalidArgumentException("A 'fieldPaths' key may not start with a '.'");
            }

            if (str_ends_with($key, '.')) {
                throw new InvalidArgumentException("A 'fieldPaths' key may not end with a '.'");
            }

            if (str_contains($key, '..')) {
                throw new InvalidArgumentException("A 'fieldPaths' key may not have an empty segment");
            }

            $this->validateTypeMapValue($value);
        }
    }
}
