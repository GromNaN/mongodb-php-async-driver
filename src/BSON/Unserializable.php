<?php

declare(strict_types=1);

namespace MongoDB\BSON;

interface Unserializable
{
    public function bsonUnserialize(array $data): void;
}
