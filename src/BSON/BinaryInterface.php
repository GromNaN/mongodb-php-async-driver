<?php

declare(strict_types=1);

namespace MongoDB\BSON;

interface BinaryInterface
{
    public function getData(): string;

    public function getType(): int;

    public function __toString(): string;
}
