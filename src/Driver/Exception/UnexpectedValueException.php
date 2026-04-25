<?php
declare(strict_types=1);

namespace MongoDB\Driver\Exception;

class UnexpectedValueException extends RuntimeException
{
    /**
     * 'MongoDB\BSON\PackedArray cannot be serialized as a root document'
     *
     * Thrown when a PackedArray is provided where a BSON document (array or
     * object, but not a packed array) is required as the root value.
     */
    public static function documentRequiredAsRoot(): self
    {
        return new self('MongoDB\BSON\PackedArray cannot be serialized as a root document');
    }
}
