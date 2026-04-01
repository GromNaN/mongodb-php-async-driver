<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use Iterator;
use MongoDB\BSON\Int64;

interface CursorInterface extends Iterator
{
    public function current(): array|object|null;

    public function getId(): Int64;

    public function getServer(): Server;

    public function isDead(): bool;

    public function key(): ?int;

    public function setTypeMap(array $typemap): void;

    public function toArray(): array;
}
