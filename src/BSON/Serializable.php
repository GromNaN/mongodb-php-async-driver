<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use stdClass;

interface Serializable extends Type
{
    public function bsonSerialize(): array|stdClass;
}
