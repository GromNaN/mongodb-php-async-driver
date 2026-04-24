<?php

declare(strict_types=1);

namespace MongoDB\Driver;

use Countable;
use MongoDB\BSON\Document;
use MongoDB\BSON\PackedArray;
use MongoDB\BSON\Persistable;
use MongoDB\BSON\Serializable as BsonSerializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Exception\UnexpectedValueException;
use MongoDB\Internal\BSON\BsonEncoder;
use stdClass;

use function array_is_list;
use function array_key_exists;
use function array_key_first;
use function array_map;
use function bin2hex;
use function count;
use function get_debug_type;
use function get_object_vars;
use function is_array;
use function is_object;
use function is_string;
use function mb_check_encoding;
use function str_contains;
use function str_starts_with;
use function strpos;
use function substr;

final class BulkWriteCommand implements Countable
{
    /** @var list<array{ns: string}> */
    private array $nsInfo = [];

    /** @var list<array> */
    private array $ops = [];

    /** @var array<int, mixed> Map of op index → inserted _id */
    private array $insertedIds = [];

    private array $options;

    private ?Session $session = null;

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

        // Validate 'comment' option — eagerly serialize to propagate exceptions at construction time
        if (array_key_exists('comment', $options)) {
            if ($options['comment'] instanceof BsonSerializable) {
                $options['comment'] = $options['comment']->bsonSerialize();
            }
        }

        $this->options = $options;
    }

    public function count(): int
    {
        return count($this->ops);
    }

    /**
     * Adds an insertOne operation and returns the document's _id.
     */
    public function insertOne(string $namespace, array|object $document): mixed
    {
        if ($document instanceof PackedArray) {
            throw new UnexpectedValueException(
                'MongoDB\BSON\PackedArray cannot be serialized as a root document',
            );
        }

        $docArr = self::toValidationArray($document);
        self::checkNullBytesInKeys($docArr);
        self::checkUtf8($docArr, '');

        $bsonBytes = BsonEncoder::encodeDocumentWithId($document, $rawId);
        $id        = self::normalizeId($rawId);

        $idx = count($this->ops);
        $this->insertedIds[$idx] = $id;
        $this->ops[] = ['insert' => $this->getNsIndex($namespace), 'document' => Document::fromBson($bsonBytes)];

        return $id;
    }

    public function deleteOne(string $namespace, array|object $filter, ?array $options = null): void
    {
        if ($filter instanceof PackedArray) {
            throw new UnexpectedValueException(
                'MongoDB\BSON\PackedArray cannot be serialized as a root document',
            );
        }

        $filterArr = self::toValidationArray($filter);
        self::checkNullBytesInKeys($filterArr);
        self::checkUtf8($filterArr, '');

        $op = ['delete' => $this->getNsIndex($namespace), 'filter' => Document::fromBson(BsonEncoder::encode($filter)), 'multi' => false];
        $this->applyDeleteOptions($op, $options);
        $this->ops[] = $op;
    }

    public function deleteMany(string $namespace, array|object $filter, ?array $options = null): void
    {
        if ($filter instanceof PackedArray) {
            throw new UnexpectedValueException(
                'MongoDB\BSON\PackedArray cannot be serialized as a root document',
            );
        }

        $filterArr = self::toValidationArray($filter);
        self::checkNullBytesInKeys($filterArr);
        self::checkUtf8($filterArr, '');

        $op = ['delete' => $this->getNsIndex($namespace), 'filter' => Document::fromBson(BsonEncoder::encode($filter)), 'multi' => true];
        $this->applyDeleteOptions($op, $options);
        $this->ops[] = $op;
    }

    public function replaceOne(
        string $namespace,
        array|object $filter,
        array|object $replacement,
        ?array $options = null,
    ): void {
        if ($filter instanceof PackedArray) {
            throw new UnexpectedValueException(
                'MongoDB\BSON\PackedArray cannot be serialized as a root document',
            );
        }

        if ($replacement instanceof PackedArray) {
            throw new UnexpectedValueException(
                'MongoDB\BSON\PackedArray cannot be serialized as a root document',
            );
        }

        $opts = $options ?? [];

        if (array_key_exists('collation', $opts)) {
            self::validateCollationOption($opts['collation']);
        }

        if (array_key_exists('hint', $opts)) {
            self::validateHintOption($opts['hint']);
        }

        // Validate filter
        $filterArr = self::toValidationArray($filter);
        self::checkNullBytesInKeys($filterArr);
        self::checkUtf8($filterArr, '');

        // Validate replacement (null bytes and UTF-8 before structure check)
        $replArr = self::toValidationArray($replacement);
        self::checkNullBytesInKeys($replArr);
        self::checkUtf8($replArr, '');

        $this->validateReplacement($replArr);

        $op = [
            'update'     => $this->getNsIndex($namespace),
            'filter'     => Document::fromBson(BsonEncoder::encode($filter)),
            'updateMods' => Document::fromBson(BsonEncoder::encode($replacement)),
            'multi'      => false,
        ];
        $this->applyUpdateOptions($op, $opts);
        $this->ops[] = $op;
    }

    public function updateOne(
        string $namespace,
        array|object $filter,
        array|object $update,
        ?array $options = null,
    ): void {
        if ($filter instanceof PackedArray) {
            throw new UnexpectedValueException(
                'MongoDB\BSON\PackedArray cannot be serialized as a root document',
            );
        }

        $opts = $options ?? [];

        if (array_key_exists('collation', $opts)) {
            self::validateCollationOption($opts['collation']);
        }

        if (array_key_exists('hint', $opts)) {
            self::validateHintOption($opts['hint']);
        }

        if (array_key_exists('arrayFilters', $opts)) {
            self::validateArrayFiltersOption($opts['arrayFilters']);
        }

        // Validate filter (null bytes and UTF-8)
        $filterArr = self::toValidationArray($filter);
        self::checkNullBytesInKeys($filterArr);
        self::checkUtf8($filterArr, '');

        // Pipeline: PackedArray or non-empty list array
        $isPipeline = ($update instanceof PackedArray)
            || (is_array($update) && array_is_list($update) && count($update) > 0);

        if (! $isPipeline) {
            $updateArr = self::toValidationArray($update);
            self::checkNullBytesInKeys($updateArr);
            self::checkUtf8($updateArr, '');
            $this->validateUpdate($updateArr);
        }

        $op = [
            'update'     => $this->getNsIndex($namespace),
            'filter'     => Document::fromBson(BsonEncoder::encode($filter)),
            'updateMods' => self::encodeUpdateMods($update),
            'multi'      => false,
        ];
        $this->applyUpdateOptions($op, $opts);
        $this->ops[] = $op;
    }

    public function updateMany(
        string $namespace,
        array|object $filter,
        array|object $update,
        ?array $options = null,
    ): void {
        if ($filter instanceof PackedArray) {
            throw new UnexpectedValueException(
                'MongoDB\BSON\PackedArray cannot be serialized as a root document',
            );
        }

        $opts = $options ?? [];

        if (array_key_exists('collation', $opts)) {
            self::validateCollationOption($opts['collation']);
        }

        if (array_key_exists('hint', $opts)) {
            self::validateHintOption($opts['hint']);
        }

        if (array_key_exists('arrayFilters', $opts)) {
            self::validateArrayFiltersOption($opts['arrayFilters']);
        }

        // Validate filter (null bytes and UTF-8)
        $filterArr = self::toValidationArray($filter);
        self::checkNullBytesInKeys($filterArr);
        self::checkUtf8($filterArr, '');

        // Pipeline: PackedArray or non-empty list array
        $isPipeline = ($update instanceof PackedArray)
            || (is_array($update) && array_is_list($update) && count($update) > 0);

        if (! $isPipeline) {
            $updateArr = self::toValidationArray($update);
            self::checkNullBytesInKeys($updateArr);
            self::checkUtf8($updateArr, '');
            $this->validateUpdate($updateArr);
        }

        $op = [
            'update'     => $this->getNsIndex($namespace),
            'filter'     => Document::fromBson(BsonEncoder::encode($filter)),
            'updateMods' => self::encodeUpdateMods($update),
            'multi'      => true,
        ];
        $this->applyUpdateOptions($op, $opts);
        $this->ops[] = $op;
    }

    /** @internal */
    public function getNsInfo(): array
    {
        return $this->nsInfo;
    }

    /** @internal */
    public function getOps(): array
    {
        return $this->ops;
    }

    /** @internal */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** @internal */
    public function getInsertedIds(): array
    {
        return $this->insertedIds;
    }

    /** @internal */
    public function setSession(?Session $session): void
    {
        $this->session = $session;
    }

    public function __debugInfo(): array
    {
        $info = [
            'bypassDocumentValidation' => $this->options['bypassDocumentValidation'] ?? null,
        ];

        if (array_key_exists('comment', $this->options)) {
            $info['comment'] = self::toDebugObject($this->options['comment']);
        }

        if (array_key_exists('let', $this->options)) {
            $info['let'] = self::toDebugObject($this->options['let']);
        }

        $info['ordered']        = $this->options['ordered'] ?? true;
        $info['verboseResults'] = $this->options['verboseResults'] ?? false;
        $info['session']        = $this->session;

        return $info;
    }

    // -------------------------------------------------------------------------

    private function getNsIndex(string $namespace): int
    {
        foreach ($this->nsInfo as $i => $entry) {
            if ($entry['ns'] === $namespace) {
                return $i;
            }
        }

        $this->nsInfo[] = ['ns' => $namespace];

        return count($this->nsInfo) - 1;
    }

    private function applyDeleteOptions(array &$op, ?array $options): void
    {
        $opts = $options ?? [];

        if (array_key_exists('collation', $opts)) {
            self::validateCollationOption($opts['collation']);
        }

        if (array_key_exists('hint', $opts)) {
            self::validateHintOption($opts['hint']);
        }

        if (isset($opts['collation'])) {
            $op['collation'] = Document::fromBson(BsonEncoder::encode($opts['collation']));
        }

        if (! isset($opts['hint'])) {
            return;
        }

        $op['hint'] = is_string($opts['hint']) ? $opts['hint'] : Document::fromBson(BsonEncoder::encode($opts['hint']));
    }

    private function applyUpdateOptions(array &$op, array $options): void
    {
        if (isset($options['arrayFilters'])) {
            $arrayFilters = $options['arrayFilters'];
            if ($arrayFilters instanceof PackedArray) {
                $op['arrayFilters'] = $arrayFilters;
            } else {
                $filters            = is_array($arrayFilters) ? $arrayFilters : (array) $arrayFilters;
                $op['arrayFilters'] = array_map(
                    static fn ($f) => Document::fromBson(BsonEncoder::encode($f)),
                    $filters,
                );
            }
        }

        if (isset($options['collation'])) {
            $op['collation'] = Document::fromBson(BsonEncoder::encode($options['collation']));
        }

        if (isset($options['hint'])) {
            $op['hint'] = is_string($options['hint']) ? $options['hint'] : Document::fromBson(BsonEncoder::encode($options['hint']));
        }

        if (isset($options['sort'])) {
            $op['sort'] = Document::fromBson(BsonEncoder::encode($options['sort']));
        }

        if (! isset($options['upsert'])) {
            return;
        }

        $op['upsert'] = (bool) $options['upsert'];
    }

    private function validateUpdate(array $update): void
    {
        $firstKey = (string) (array_key_first($update) ?? '');

        if ($firstKey === '') {
            throw new InvalidArgumentException('Update document must not be empty');
        }

        if (! str_starts_with($firstKey, '$')) {
            throw new InvalidArgumentException(
                'First key in update document must be an update operator; use replaceOne() for replacements',
            );
        }
    }

    private function validateReplacement(array $replacement): void
    {
        $firstKey = (string) (array_key_first($replacement) ?? '');

        if ($firstKey !== '' && str_starts_with($firstKey, '$')) {
            throw new InvalidArgumentException(
                'Replacement document keys must not start with "$"; use updateOne() or updateMany() for updates',
            );
        }
    }

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

    /**
     * Encode an update/pipeline document to its BSON representation.
     *
     * For pipeline updates (PackedArray or PHP list array), returns a PackedArray.
     * For regular update documents, returns a Document.
     */
    private static function encodeUpdateMods(array|object $update): Document|PackedArray
    {
        if ($update instanceof PackedArray) {
            return $update;
        }

        if (is_array($update) && array_is_list($update)) {
            return PackedArray::fromBSON(BsonEncoder::encodeList($update));
        }

        return Document::fromBson(BsonEncoder::encode($update));
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
        if ($value instanceof PackedArray) {
            throw new InvalidArgumentException(
                'Expected "hint" option to yield string or document but got "array"',
            );
        }

        if (! is_string($value) && ! is_array($value) && ! is_object($value)) {
            throw new InvalidArgumentException(
                'Expected "hint" option to be string, array, or object, ' . get_debug_type($value) . ' given',
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
                'Expected "arrayFilters" option to yield array but got non-sequential keys',
            );
        }
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

    /** Convert a value to stdClass for debug output (arrays become stdClass). */
    private static function toDebugObject(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $obj = new stdClass();
        foreach ($value as $k => $v) {
            $obj->$k = self::toDebugObject($v);
        }

        return $obj;
    }
}
