<?php

declare(strict_types=1);

namespace MongoDB\Internal\BSON;

use InvalidArgumentException;
use MongoDB\BSON\Document;
use MongoDB\BSON\PackedArray;
use MongoDB\BSON\Unserializable;
use ReflectionClass;
use stdClass;

use function array_is_list;
use function array_values;
use function class_exists;
use function is_array;
use function is_object;
use function sprintf;

/**
 * Applies a BSON type map to an already-decoded PHP value.
 *
 * The typeMap array may contain:
 *   'root'       - type for the top-level document
 *   'document'   - type for nested BSON documents
 *   'array'      - type for nested BSON arrays
 *   'fieldPaths' - associative map of dot-notation field paths to types
 *
 * Supported type strings:
 *   'array'        - PHP array
 *   'object'       - stdClass
 *   'bsonDocument' - MongoDB\BSON\Document
 *   'bsonArray'    - MongoDB\BSON\PackedArray
 *   <class-name>   - user class (bsonUnserialize called if implements Unserializable)
 *
 * @internal
 */
final class TypeMapper
{
    /**
     * Apply typeMap rules to a decoded value.
     *
     * @param mixed  $value   The decoded PHP value (array or object)
     * @param array  $typeMap Type map configuration
     * @param string $context 'root' | 'document' | 'array'
     */
    public static function apply(mixed $value, array $typeMap, string $context = 'root'): mixed
    {
        if (! is_array($value) && ! is_object($value)) {
            // Scalar / BSON type objects are not subject to typeMap conversion
            return $value;
        }

        $targetType = self::resolveContextType($typeMap, $context);

        // Resolve 'bson' shorthand to concrete BSON type based on context.
        if ($targetType === 'bson') {
            $targetType = $context === 'array' ? 'bsonArray' : 'bsonDocument';
        }

        // Recursively apply typeMap to children before converting the container
        $value = self::applyToChildren($value, $typeMap);

        return self::convertToType($value, $targetType);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Determine the target type string for the given context.
     */
    private static function resolveContextType(array $typeMap, string $context): string
    {
        return match ($context) {
            'root'  => $typeMap['root']     ?? 'object',
            'array' => $typeMap['array']    ?? 'array',
            default => $typeMap['document'] ?? 'object',
        };
    }

    /**
     * Recursively apply typeMap to child documents/arrays within $value.
     */
    private static function applyToChildren(array|object $value, array $typeMap): array|object
    {
        $isArray = is_array($value);
        $items   = $isArray ? $value : (array) $value;

        $result = [];
        foreach ($items as $key => $child) {
            if (is_array($child)) {
                // Determine whether this child was a BSON array (list) or document
                $childContext = array_is_list($child) ? 'array' : 'document';

                // Check field-path override
                $childType = self::resolveFieldPath($typeMap, (string) $key)
                ?? self::resolveContextType($typeMap, $childContext);

                $result[$key] = self::apply($child, $typeMap, $childContext);
            } elseif ($child instanceof stdClass) {
                $result[$key] = self::apply((array) $child, $typeMap, 'document');
            } else {
                $result[$key] = $child;
            }
        }

        if ($isArray) {
            return $result;
        }

        // Reconstruct as the same object type
        $obj = new stdClass();
        foreach ($result as $k => $v) {
            $obj->$k = $v;
        }

        return $obj;
    }

    /**
     * Look up a field-level type override from 'fieldPaths'.
     * Returns null when no override is defined for $fieldName.
     */
    private static function resolveFieldPath(array $typeMap, string $fieldName): ?string
    {
        $fieldPaths = $typeMap['fieldPaths'] ?? [];

        return $fieldPaths[$fieldName] ?? null;
    }

    /**
     * Convert $value to the target type.
     */
    private static function convertToType(array|object $value, string $targetType): array|object
    {
        // Normalise to array for manipulation
        $fields = is_array($value) ? $value : (array) $value;

        return match ($targetType) {
            'array' => $fields,

            'object' => self::fieldsToStdClass($fields),

            'bsonDocument' => Document::fromPHP($fields),

            'bsonArray' => PackedArray::fromPHP(array_values($fields)),

            default => self::instantiateClass($targetType, $fields),
        };
    }

    /**
     * Shallow conversion of an array to stdClass.
     *
     * @param array<string, mixed> $fields
     */
    private static function fieldsToStdClass(array $fields): stdClass
    {
        $obj = new stdClass();
        foreach ($fields as $key => $value) {
            $obj->$key = $value;
        }

        return $obj;
    }

    /**
     * Instantiate a user class and populate it.
     *
     * @param class-string         $className
     * @param array<string, mixed> $fields
     */
    private static function instantiateClass(string $className, array $fields): object
    {
        if (! class_exists($className)) {
            throw new InvalidArgumentException(
                sprintf('TypeMapper: class "%s" does not exist', $className),
            );
        }

        $obj = (new ReflectionClass($className))->newInstanceWithoutConstructor();

        if ($obj instanceof Unserializable) {
            $obj->bsonUnserialize($fields);

            return $obj;
        }

        foreach ($fields as $key => $value) {
            $obj->$key = $value;
        }

        return $obj;
    }
}
