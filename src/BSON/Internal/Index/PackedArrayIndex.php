<?php

declare(strict_types=1);

namespace MongoDB\BSON\Internal\Index;

/** @internal */
final class PackedArrayIndex extends Index
{
    protected static function sortFields(array $fields): array
    {
        return $fields;
    }
}
