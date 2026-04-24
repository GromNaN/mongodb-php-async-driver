<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use Countable;
use MongoDB\BSON\Binary;
use MongoDB\BSON\Document;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\PackedArray;
use MongoDB\BSON\Persistable;
use MongoDB\BSON\Serializable as BsonSerializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Exception\UnexpectedValueException;
use stdClass;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function bin2hex;
use function count;
use function get_debug_type;
use function get_object_vars;
use function is_array;
use function is_object;
use function is_string;
use function mb_check_encoding;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strpos;
use function substr;

final class BulkWrite implements Countable
{
    private array $operations = [];
    private array $options;
    private bool $executed = false;
    private int $serverId = 0;
    private ?string $database = null;
    private ?string $collection = null;
    private ?WriteConcern $writeConcern = null;

    public function __construct(?array $options = null)
    {
        $options ??= [];

        // Validate 'let' option
        if (array_key_exists('let', $options)) {
            $let = $options['let'];
            if (! is_array($let) && ! is_object($let)) {
                throw new InvalidArgumentException(
                    'Expected "let" option to be array or object, ' . get_debug_type($let) . ' given',
                );
            }

            if ($let instanceof PackedArray) {
                throw new UnexpectedValueException(
                    'MongoDB\BSON\PackedArray cannot be serialized as a root document',
                );
            }
        }

        // Validate 'comment' option
        if (array_key_exists('comment', $options)) {
            $comment = $options['comment'];
            if ($comment instanceof BsonSerializable) {
                // Trigger bsonSerialize() to surface exceptions immediately
                $comment->bsonSerialize();
            } elseif (is_array($comment)) {
                self::checkUtf8($comment, 'data');
            }
        }

        $this->options = array_merge(['ordered' => true], $options);
    }

    public function count(): int
    {
        return count($this->operations);
    }

    public function insert(array|object $document): mixed
    {
        if ($document instanceof PackedArray) {
            throw new UnexpectedValueException(
                'MongoDB\BSON\PackedArray cannot be serialized as a root document',
            );
        }

        $docArr = self::toValidationArray($document);
        self::checkNullBytesInKeys($docArr);
        self::checkEmptyKeys($docArr, 'invalid document for insert: Element key cannot be an empty string');
        self::checkUtf8($docArr, '');

        // Extract or generate _id, and ensure the stored document includes it so
        // the _id returned by insert() always matches the one sent to the server.
        if (is_array($document)) {
            if (! isset($document['_id'])) {
                $document['_id'] = new ObjectId();
            }

            $id = self::normalizeId($document['_id']);
        } elseif ($document instanceof Document) {
            $data = (array) $document->toPHP(['root' => 'array', 'document' => 'array']);
            if (isset($data['_id'])) {
                $id = self::normalizeId($data['_id']);
            } else {
                $generatedId = new ObjectId();
                $id          = self::normalizeId($generatedId);
                $document    = Document::fromPHP(['_id' => $generatedId] + $data);
            }
        } elseif ($document instanceof Persistable) {
            $serialized = $document->bsonSerialize();
            $serialized = is_array($serialized) ? $serialized : (array) $serialized;
            if (isset($serialized['_id'])) {
                $id = self::normalizeId($serialized['_id']);
            } else {
                $generatedId = new ObjectId();
                $id          = self::normalizeId($generatedId);
                // Expand to array: __pclass first (mirrors BsonEncoder), then _id, then fields.
                $document = [
                    '__pclass' => new Binary($document::class, Binary::TYPE_USER_DEFINED),
                    '_id'      => $generatedId,
                ] + $serialized;
            }
        } elseif ($document instanceof BsonSerializable) {
            $serialized = $document->bsonSerialize();
            $serialized = is_array($serialized) ? $serialized : (array) $serialized;
            if (isset($serialized['_id'])) {
                $id = self::normalizeId($serialized['_id']);
            } else {
                $generatedId = new ObjectId();
                $id          = self::normalizeId($generatedId);
                $document    = ['_id' => $generatedId] + $serialized;
            }
        } else {
            $vars = get_object_vars($document);
            if (array_key_exists('_id', $vars)) {
                $id = self::normalizeId($vars['_id']);
            } elseif ($document instanceof stdClass) {
                $document->_id = new ObjectId();
                $id            = $document->_id;
            } else {
                // Cannot safely set dynamic properties on arbitrary objects;
                // convert to array so _id is included in the encoded document.
                $generatedId = new ObjectId();
                $id          = self::normalizeId($generatedId);
                $document    = ['_id' => $generatedId] + $vars;
            }
        }

        $this->operations[] = ['insert', $document, null];

        return $id;
    }

    public function update(array|object $filter, array|object $newObj, ?array $updateOptions = null): void
    {
        if ($filter instanceof PackedArray) {
            throw new UnexpectedValueException(
                'MongoDB\BSON\PackedArray cannot be serialized as a root document',
            );
        }

        $options = $updateOptions ?? [];

        // Validate option types
        if (array_key_exists('collation', $options)) {
            self::validateCollationOption($options['collation']);
        }

        if (array_key_exists('hint', $options)) {
            self::validateHintOption($options['hint']);
        }

        if (array_key_exists('arrayFilters', $options)) {
            self::validateArrayFiltersOption($options['arrayFilters']);
        }

        // Validate filter (null bytes and UTF-8 only)
        $filterArr = self::toValidationArray($filter);
        self::checkNullBytesInKeys($filterArr);
        self::checkUtf8($filterArr, '');

        // newObj: PackedArray is a pipeline; non-empty list array is a pipeline;
        // empty array [] is treated as a replacement document, not a pipeline.
        $isPipeline = ($newObj instanceof PackedArray)
            || (is_array($newObj) && array_is_list($newObj) && count($newObj) > 0);

        // A Serializable object that serializes to a list is also a pipeline.
        if (! $isPipeline && $newObj instanceof BsonSerializable) {
            $serialized = $newObj->bsonSerialize();
            if (is_array($serialized) && array_is_list($serialized) && count($serialized) > 0) {
                $isPipeline = true;
            }
        }

        if (! $isPipeline) {
            $newObjArr = self::toValidationArray($newObj);
            $docType   = self::detectUpdateDocType($newObjArr);

            if ($docType === 'mixed') {
                foreach ($newObjArr as $k => $v) {
                    if (! str_starts_with((string) $k, '$')) {
                        throw new InvalidArgumentException(
                            sprintf("Invalid key '%s': update only works with \$ operators and pipelines", $k),
                        );
                    }
                }
            }

            if ($docType === 'replacement') {
                if (($options['multi'] ?? false) === true) {
                    throw new InvalidArgumentException(
                        'Replacement document conflicts with true "multi" option',
                    );
                }

                if (array_key_exists('sort', $options)) {
                    throw new InvalidArgumentException("Invalid option 'sort'");
                }

                self::checkNullBytesInKeys($newObjArr);
                self::checkEmptyKeys($newObjArr, 'invalid argument for replace: Element key cannot be an empty string');
                self::checkUtf8($newObjArr, '');
            } else {
                // Update operator document
                if (array_key_exists('sort', $options) && ($options['multi'] ?? false) === true) {
                    throw new InvalidArgumentException("Invalid option 'sort'");
                }

                self::checkNullBytesInKeys($newObjArr);
                self::checkEmptyKeys($newObjArr, 'invalid argument for update: Element key cannot be an empty string');
                self::checkUtf8($newObjArr, '');
            }
        } elseif (array_key_exists('sort', $options) && ($options['multi'] ?? false) === true) {
            throw new InvalidArgumentException("Invalid option 'sort'");
        }

        $this->operations[] = ['update', $filter, $newObj, $options];
    }

    public function delete(array|object $filter, ?array $deleteOptions = null): void
    {
        if ($filter instanceof PackedArray) {
            throw new UnexpectedValueException(
                'MongoDB\BSON\PackedArray cannot be serialized as a root document',
            );
        }

        $options = $deleteOptions ?? [];

        if (array_key_exists('collation', $options)) {
            self::validateCollationOption($options['collation']);
        }

        if (array_key_exists('hint', $options)) {
            self::validateHintOption($options['hint']);
        }

        $filterArr = self::toValidationArray($filter);
        self::checkNullBytesInKeys($filterArr);
        self::checkUtf8($filterArr, '');

        $this->operations[] = ['delete', $filter, $options];
    }

    /** @internal */
    public function getOperations(): array
    {
        return $this->operations;
    }

    /** @internal */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** @internal */
    public function isExecuted(): bool
    {
        return $this->executed;
    }

    /** @internal */
    public function markExecuted(string $database, string $collection, int $serverId, ?WriteConcern $writeConcern = null): void
    {
        $this->executed     = true;
        $this->database     = $database;
        $this->collection   = $collection;
        $this->serverId     = $serverId;
        $this->writeConcern = $writeConcern;
    }

    public function __debugInfo(): array
    {
        $info = [
            'database'                 => $this->database,
            'collection'               => $this->collection,
            'ordered'                  => $this->options['ordered'],
            'bypassDocumentValidation' => $this->options['bypassDocumentValidation'] ?? null,
        ];

        if (array_key_exists('comment', $this->options)) {
            $info['comment'] = self::toDebugObject($this->options['comment']);
        }

        if (array_key_exists('let', $this->options)) {
            $info['let'] = self::toDebugObject($this->options['let']);
        }

        $info['executed']      = $this->executed;
        $info['server_id']     = $this->serverId;
        $info['session']       = null;
        $info['write_concern'] = $this->writeConcern !== null && ! $this->writeConcern->isDefault()
            ? $this->writeConcern->__debugInfo()
            : null;

        return $info;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** Flatten an array|object to a plain array for validation purposes. */
    private static function toValidationArray(array|object $doc): array
    {
        if ($doc instanceof Document) {
            return (array) $doc->toPHP(['root' => 'array', 'document' => 'array']);
        }

        if ($doc instanceof BsonSerializable) {
            $serialized = $doc->bsonSerialize();

            return is_array($serialized) ? $serialized : (array) $serialized;
        }

        return is_array($doc) ? $doc : get_object_vars($doc);
    }

    /** Detect whether a newObj array is a replacement, update operator doc, or mixed. */
    private static function detectUpdateDocType(array $doc): string
    {
        $hasDollar    = false;
        $hasNonDollar = false;

        foreach (array_keys($doc) as $key) {
            if (str_starts_with((string) $key, '$')) {
                $hasDollar = true;
            } else {
                $hasNonDollar = true;
            }
        }

        if ($hasDollar && $hasNonDollar) {
            return 'mixed';
        }

        return $hasDollar ? 'update' : 'replacement';
    }

    /**
     * Recursively check for null bytes in document keys.
     * Throws UnexpectedValueException on the first null-byte key found.
     */
    private static function checkNullBytesInKeys(array $doc): void
    {
        foreach ($doc as $rawKey => $value) {
            $key = (string) $rawKey;

            if (str_contains($key, "\x00")) {
                $nullPos    = strpos($key, "\x00");
                $beforeNull = substr($key, 0, $nullPos);

                throw new UnexpectedValueException(
                    'BSON keys cannot contain null bytes. Unexpected null byte after "' . $beforeNull . '".',
                );
            }

            if (is_array($value)) {
                self::checkNullBytesInKeys($value);
            } elseif ($value instanceof stdClass) {
                self::checkNullBytesInKeys((array) $value);
            }
        }
    }

    /**
     * Recursively check for empty string keys.
     * Throws InvalidArgumentException with $message on the first empty key found.
     */
    private static function checkEmptyKeys(array $doc, string $message): void
    {
        foreach ($doc as $rawKey => $value) {
            if ((string) $rawKey === '') {
                throw new InvalidArgumentException($message);
            }

            if (is_array($value)) {
                self::checkEmptyKeys($value, $message);
            } elseif ($value instanceof stdClass) {
                self::checkEmptyKeys((array) $value, $message);
            }
        }
    }

    /**
     * Recursively check string values for invalid UTF-8.
     * $prefix is the dot-notation path to the current level (empty string for root).
     */
    private static function checkUtf8(array $doc, string $prefix): void
    {
        foreach ($doc as $rawKey => $value) {
            $key  = (string) $rawKey;
            $path = $prefix !== '' ? $prefix . '.' . $key : $key;

            if (is_string($value)) {
                if (! mb_check_encoding($value, 'UTF-8')) {
                    throw new UnexpectedValueException(
                        'Detected invalid UTF-8 for field path "' . $path . '": ' . bin2hex($value),
                    );
                }
            } elseif (is_array($value)) {
                self::checkUtf8($value, $path);
            } elseif ($value instanceof stdClass) {
                self::checkUtf8((array) $value, $path);
            }
        }
    }

    private static function validateCollationOption(mixed $value): void
    {
        if (! is_array($value) && ! is_object($value)) {
            throw new InvalidArgumentException(
                'Expected "collation" option to be array or object, ' . get_debug_type($value) . ' given',
            );
        }

        if ($value instanceof PackedArray) {
            throw new UnexpectedValueException(
                'MongoDB\BSON\PackedArray cannot be serialized as a root document',
            );
        }

        $arr = is_array($value) ? $value : (array) $value;
        self::checkNullBytesInKeys($arr);
        self::checkUtf8($arr, '');
    }

    private static function validateHintOption(mixed $value): void
    {
        if (! is_string($value) && ! is_array($value) && ! is_object($value)) {
            throw new InvalidArgumentException(
                'Expected "hint" option to be string, array, or object, ' . get_debug_type($value) . ' given',
            );
        }

        if ($value instanceof PackedArray) {
            throw new UnexpectedValueException(
                'MongoDB\BSON\PackedArray cannot be serialized as a root document',
            );
        }
    }

    private static function validateArrayFiltersOption(mixed $value): void
    {
        if (! is_array($value) && ! is_object($value)) {
            throw new InvalidArgumentException(
                'Expected "arrayFilters" option to be array or object, ' . get_debug_type($value) . ' given',
            );
        }

        // PackedArray is a valid BSON array — allow it
        if ($value instanceof PackedArray) {
            return;
        }

        $arr = is_array($value) ? $value : (array) $value;

        if (! array_is_list($arr)) {
            throw new InvalidArgumentException(
                '"arrayFilters" option has invalid keys for a BSON array',
            );
        }
    }

    /** Convert a value to stdClass for debug output (arrays become stdClass). */
    private static function toDebugObject(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $obj = new stdClass();

        foreach ($value as $k => $v) {
            $obj->$k = $v;
        }

        return $obj;
    }

    /**
     * Normalize an _id value to match what the server stores and returns.
     * Arrays become stdClass; Serializable (non-Persistable) returns the serialized stdClass.
     * Persistable and scalar types are returned as-is.
     */
    private static function normalizeId(mixed $id): mixed
    {
        if (is_array($id)) {
            return self::toDebugObject($id);
        }

        if ($id instanceof Persistable) {
            return $id;
        }

        if ($id instanceof BsonSerializable) {
            $serialized = $id->bsonSerialize();
            $arr        = is_array($serialized) ? $serialized : (array) $serialized;

            return self::toDebugObject($arr);
        }

        return $id;
    }
}
