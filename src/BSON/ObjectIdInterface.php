<?php

declare(strict_types=1);

namespace MongoDB\BSON;

interface ObjectIdInterface
{
    public function getTimestamp(): int;

    public function __toString(): string;
}
