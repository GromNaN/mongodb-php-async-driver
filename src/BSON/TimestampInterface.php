<?php

declare(strict_types=1);

namespace MongoDB\BSON;

interface TimestampInterface
{
    public function getIncrement(): int;

    public function getTimestamp(): int;

    public function __toString(): string;
}
