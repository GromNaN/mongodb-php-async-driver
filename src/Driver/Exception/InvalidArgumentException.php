<?php

declare(strict_types=1);

namespace MongoDB\Driver\Exception;

use InvalidArgumentException as PhpInvalidArgumentException;

use function get_debug_type;
use function sprintf;

class InvalidArgumentException extends PhpInvalidArgumentException implements Exception
{
    /**
     * 'Expected "readConcern" option to be MongoDB\Driver\ReadConcern, string given'
     */
    public static function invalidOptionType(string $option, mixed $value, string $expectedType): self
    {
        return new self(sprintf(
            'Expected "%s" option to be %s, %s given',
            $option,
            $expectedType,
            get_debug_type($value),
        ));
    }

    /**
     * 'Expected "serverApi" driver option to be MongoDB\Driver\ServerApi, string given'
     */
    public static function invalidDriverOptionType(string $option, mixed $value, string $expectedType): self
    {
        return new self(sprintf(
            'Expected "%s" driver option to be %s, %s given',
            $option,
            $expectedType,
            get_debug_type($value),
        ));
    }

    /**
     * 'Expected "sort" option to be array or object, int given'
     */
    public static function expectedDocumentOption(string $option, mixed $value): self
    {
        return new self(sprintf(
            'Expected "%s" option to be array or object, %s given',
            $option,
            get_debug_type($value),
        ));
    }

    /**
     * 'Expected "hint" option to be string, array, or object, int given'
     */
    public static function expectedHintOption(string $option, mixed $value): self
    {
        return new self(sprintf(
            'Expected "%s" option to be string, array, or object, %s given',
            $option,
            get_debug_type($value),
        ));
    }

    /**
     * 'MongoDB\Driver\ReadConcern initialization requires "level" string field'
     */
    public static function initializationRequiresStringField(string $class, string $field): self
    {
        return new self(sprintf('%s initialization requires "%s" string field', $class, $field));
    }

    /**
     * 'MongoDB\Driver\WriteConcern initialization requires "w" field to be integer or string'
     */
    public static function initializationRequiresFieldType(string $class, string $field, string $type): self
    {
        return new self(sprintf('%s initialization requires "%s" field to be %s', $class, $field, $type));
    }
}
