<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use MongoDB\BSON\Document as BsonDocument;
use MongoDB\BSON\Serializable as BsonSerializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Exception\UnexpectedValueException;
use stdClass;

use function array_is_list;
use function array_key_exists;
use function array_map;
use function assert;
use function is_array;
use function is_object;
use function is_string;
use function sprintf;

final class Query
{
    private array $options;

    public function __construct(private array|object $filter, ?array $queryOptions = null)
    {
        if (! is_document($filter)) {
            throw UnexpectedValueException::documentRequiredAsRoot();
        }

        $this->validateEmptyKeys($filter, 'filter');

        $options = $queryOptions ?? [];

        // readConcern must be a ReadConcern instance
        if (array_key_exists('readConcern', $options)) {
            $val = $options['readConcern'];
            if (! ($val instanceof ReadConcern)) {
                throw InvalidArgumentException::invalidOptionType('readConcern', $val, ReadConcern::class);
            }
        }

        // hint: string, array, or object
        if (array_key_exists('hint', $options)) {
            $val = $options['hint'];
            if (! is_string($val) && ! is_array($val) && ! is_object($val)) {
                throw InvalidArgumentException::expectedHintOption('hint', $val);
            }

            if (! is_document($val)) {
                throw UnexpectedValueException::documentRequiredAsRoot();
            }

            if (is_array($val) || is_object($val)) {
                $this->validateEmptyKeys($val, 'hint');
            }
        }

        // Options that must be array or object (document-type)
        $documentOptions = ['collation', 'let', 'max', 'min', 'projection', 'sort'];
        foreach ($documentOptions as $key) {
            if (! array_key_exists($key, $options)) {
                continue;
            }

            $val = $options[$key];

            if (! is_array($val) && ! is_object($val)) {
                throw InvalidArgumentException::expectedDocumentOption($key, $val);
            }

            if (! is_document($val)) {
                throw UnexpectedValueException::documentRequiredAsRoot();
            }

            $this->validateEmptyKeys($val, $key);
        }

        // maxAwaitTimeMS: integer >= 0 and <= 4294967295
        if (array_key_exists('maxAwaitTimeMS', $options)) {
            $val = $options['maxAwaitTimeMS'];
            if ($val < 0) {
                throw new InvalidArgumentException(
                    'Expected "maxAwaitTimeMS" option to be >= 0, ' . $val . ' given',
                );
            }

            if ($val > 4294967295) {
                throw new InvalidArgumentException(
                    'Expected "maxAwaitTimeMS" option to be <= 4294967295, ' . $val . ' given',
                );
            }
        }

        // Eagerly serialize comment if it is a Serializable to propagate exceptions at construction time
        if (array_key_exists('comment', $options) && $options['comment'] instanceof BsonSerializable) {
            $options['comment']->bsonSerialize();
        }

        $this->options = $options;
    }

    /** @internal */
    public function getFilter(): array|object
    {
        return $this->filter;
    }

    /** @internal */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function __debugInfo(): array
    {
        $displayOptions = $this->options;
        $readConcern    = null;

        if (isset($displayOptions['readConcern'])) {
            $rc           = $displayOptions['readConcern'];
            assert($rc instanceof ReadConcern);
            $level        = $rc->getLevel();
            $readConcern  = $level !== null ? ['level' => $level] : [];
            unset($displayOptions['readConcern']);
        }

        return [
            'filter'      => self::convertForDisplay($this->filter),
            'options'     => self::convertForDisplay($displayOptions),
            'readConcern' => $readConcern,
        ];
    }

    private function validateEmptyKeys(array|object $document, string $context): void
    {
        $arr = is_array($document) ? $document : (array) $document;
        foreach ($arr as $key => $value) {
            if ((string) $key === '') {
                if ($context === 'filter') {
                    throw new InvalidArgumentException('Cannot use empty keys in filter document');
                }

                throw new InvalidArgumentException(sprintf('Cannot use empty keys in "%s" option', $context));
            }

            if (! is_array($value) && ! ($value instanceof stdClass)) {
                continue;
            }

            $this->validateEmptyKeys($value, $context);
        }
    }

    private static function convertForDisplay(mixed $value): mixed
    {
        // Decode BSON Document to stdClass for display
        if ($value instanceof BsonDocument) {
            return self::convertForDisplay($value->toPHP());
        }

        if (! is_array($value)) {
            return $value;
        }

        // Non-empty sequential arrays stay as PHP arrays (BSON arrays)
        if ($value !== [] && array_is_list($value)) {
            return array_map(self::convertForDisplay(...), $value);
        }

        // Empty or associative arrays become stdClass (BSON documents)
        $obj = new stdClass();
        foreach ($value as $k => $v) {
            $obj->$k = self::convertForDisplay($v);
        }

        return $obj;
    }
}
