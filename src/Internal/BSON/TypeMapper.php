<?php

declare(strict_types=1);

namespace MongoDB\Internal\BSON;

use InvalidArgumentException;
use MongoDB\BSON\Binary;
use MongoDB\BSON\Document;
use MongoDB\BSON\PackedArray;
use MongoDB\BSON\Persistable;
use MongoDB\BSON\Unserializable;
use ReflectionClass;
use stdClass;

use function array_is_list;
use function array_values;
use function class_exists;
use function is_array;
use function is_object;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

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
 *   'bson'(object) - MongoDB\BSON\Document
 *   'bson'(array)  - MongoDB\BSON\PackedArray
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

        // Persistable reconstruction is disabled only when the typeMap EXPLICITLY specifies
        // 'object' (or 'array') for this context level. When the key is absent (default), Persistable
        // reconstruction is enabled — matching ext-mongodb behaviour.
        $noPersistable = isset($typeMap[$context]) && ($typeMap[$context] === 'object' || $typeMap[$context] === 'array');

        // Recursively apply typeMap to children before converting the container
        $value = self::applyToChildren($value, $typeMap);

        return self::convertToType($value, $targetType, $noPersistable);
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
            $strKey = (string) $key;

            if (! is_array($child) && ! ($child instanceof stdClass)) {
                $result[$key] = $child;
                continue;
            }

            // Normalize child to array (stdClass → array)
            $childArr         = is_array($child) ? $child : (array) $child;
            $childDataContext = is_array($child) && array_is_list($child) ? 'array' : 'document';

            // fieldPath override for this key; '$' wildcard applies to array elements
            $fieldPathType = self::resolveFieldPath($typeMap, $strKey)
                ?? ($isArray ? self::resolveFieldPath($typeMap, '$') : null);

            // Narrow fieldPaths for recursion: strip current key prefix
            $childTypeMap = self::narrowTypeMap($typeMap, $strKey, $isArray);

            if ($fieldPathType !== null) {
                $childTypeMap['root'] = $fieldPathType;
                $result[$key]         = self::apply($childArr, $childTypeMap, 'root');
            } else {
                $result[$key] = self::apply($childArr, $childTypeMap, $childDataContext);
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
     * Produce a typeMap narrowed to the subtree rooted at $key.
     *
     * Strips the "$key." prefix from all fieldPaths entries.
     * When $isArrayParent is true, also strips the "$." prefix (wildcard).
     */
    private static function narrowTypeMap(array $typeMap, string $key, bool $isArrayParent): array
    {
        $fieldPaths = $typeMap['fieldPaths'] ?? [];
        if ($fieldPaths === []) {
            return $typeMap;
        }

        $prefix         = $key . '.';
        $wildcardPrefix = '$.';
        $narrowed       = [];

        foreach ($fieldPaths as $path => $type) {
            $path = (string) $path;
            if (str_starts_with($path, $prefix)) {
                $narrowed[substr($path, strlen($prefix))] = $type;
            }

            if (! $isArrayParent || ! str_starts_with($path, $wildcardPrefix)) {
                continue;
            }

            $narrowed[substr($path, strlen($wildcardPrefix))] = $type;
        }

        $typeMap['fieldPaths'] = $narrowed;

        return $typeMap;
    }

    /**
     * Convert $value to the target type.
     *
     * @param bool $noPersistable When true, __pclass Persistable reconstruction is suppressed.
     *                            This is set when the typeMap EXPLICITLY specifies 'object' or
     *                            'array' for the current context level.
     */
    private static function convertToType(array|object $value, string $targetType, bool $noPersistable = false): array|object
    {
        // Normalise to array for manipulation
        $fields = is_array($value) ? $value : (array) $value;

        if ($targetType === 'array') {
            return $fields;
        }

        // Check __pclass Persistable reconstruction before applying target type.
        // This happens unless the typeMap explicitly disabled Persistable for this level.
        if (
            ! $noPersistable
            && isset($fields['__pclass'])
            && $fields['__pclass'] instanceof Binary
            && $fields['__pclass']->getType() === Binary::TYPE_USER_DEFINED
        ) {
            $pclassName = $fields['__pclass']->getData();
            if (class_exists($pclassName)) {
                $rc = new ReflectionClass($pclassName);
                if ($rc->isInstantiable() && $rc->implementsInterface(Persistable::class)) {
                    $obj = $rc->newInstanceWithoutConstructor();
                    $obj->bsonUnserialize($fields);

                    return $obj;
                }
            }
        }

        if ($targetType === 'object' || $targetType === 'stdClass' || $targetType === 'stdclass') {
            return self::fieldsToStdClass($fields);
        }

        if ($targetType === 'bsonDocument') {
            return Document::fromPHP($fields);
        }

        if ($targetType === 'bsonArray') {
            return PackedArray::fromPHP(array_values($fields));
        }

        return self::instantiateClass($targetType, $fields);
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
