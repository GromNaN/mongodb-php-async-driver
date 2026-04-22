<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use stdClass;

interface Persistable extends Serializable, Unserializable
{
    public function bsonSerialize(): array|stdClass|Document;
}
