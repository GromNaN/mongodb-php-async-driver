<?php

declare(strict_types=1);

namespace MongoDB\Driver;

use MongoDB\BSON\PackedArray;

use function is_array;
use function is_object;

/**
 * Returns true when $value can be used as a BSON root document.
 *
 * A valid root document is an array or any object except PackedArray,
 * which represents a BSON array and cannot be used at the root level.
 */
function is_document(mixed $value): bool
{
    return is_array($value) || (is_object($value) && ! $value instanceof PackedArray);
}
