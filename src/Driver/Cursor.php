<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use Closure;
use MongoDB\BSON\Int64;
use MongoDB\Driver\Exception\RuntimeException;
use Throwable;

use function count;
use function is_int;

final class Cursor implements CursorInterface
{
    private array $items = [];
    private int $position = 0;
    private array $typeMap = [];
    private ?int $cursorId = null;
    private ?Server $server = null;
    private string $namespace = '';
    /** @var Closure|null Callable that fetches more items: fn(int $cursorId): array */
    private ?Closure $getMoreFn = null;
    private bool $exhausted = false;

    private function __construct()
    {
    }

    /** @internal */
    public static function _createFromCommandResult(
        array $items,
        int|Int64 $cursorId,
        string $namespace,
        Server $server,
        array $typeMap,
        ?Closure $getMoreFn = null,
    ): self {
        $instance = new self();
        $instance->items = $items;
        $instance->cursorId = is_int($cursorId) ? $cursorId : (int) (string) $cursorId;
        $instance->namespace = $namespace;
        $instance->server = $server;
        $instance->typeMap = $typeMap;
        $instance->getMoreFn = $getMoreFn;
        $instance->exhausted = ($instance->cursorId === 0);

        return $instance;
    }

    /** @internal */
    public static function _createFromArray(array $items, Server $server): self
    {
        $instance = new self();
        $instance->items = $items;
        $instance->cursorId = 0;
        $instance->server = $server;
        $instance->exhausted = true;

        return $instance;
    }

    public function current(): array|object|null
    {
        return $this->items[$this->position] ?? null;
    }

    public function getId(): Int64
    {
        return new Int64($this->cursorId ?? 0);
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
        return $this->valid() ? $this->position : null;
    }

    public function next(): void
    {
        $this->position++;
        // Fetch more if we've consumed all current items and cursor is still open
        if ($this->position < count($this->items) || $this->exhausted || $this->getMoreFn === null) {
            return;
        }

        $this->fetchMore();
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function setTypeMap(array $typemap): void
    {
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

        // Also fetch remaining batches
        while (! $this->exhausted && $this->getMoreFn !== null) {
            $this->fetchMore();
            while ($this->position < count($this->items)) {
                $result[] = $this->items[$this->position++];
            }
        }

        return $result;
    }

    public function valid(): bool
    {
        return $this->position < count($this->items);
    }

    private function fetchMore(): void
    {
        if ($this->getMoreFn === null || $this->cursorId === 0) {
            $this->exhausted = true;

            return;
        }

        try {
            [$newItems, $newCursorId] = ($this->getMoreFn)($this->cursorId, $this->namespace);
            $this->items = $newItems;
            $this->position = 0;
            $this->cursorId = $newCursorId;
            $this->exhausted = ($newCursorId === 0);
        } catch (Throwable $e) {
            $this->exhausted = true;

            throw $e;
        }
    }
}
