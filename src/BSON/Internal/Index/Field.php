<?php

declare(strict_types=1);

namespace MongoDB\BSON\Internal\Index;

use InvalidArgumentException;
use MongoDB\BSON\Binary;
use MongoDB\BSON\DBPointer;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Document;
use MongoDB\BSON\Int64;
use MongoDB\BSON\Javascript;
use MongoDB\BSON\MaxKey;
use MongoDB\BSON\MinKey;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\PackedArray;
use MongoDB\BSON\Regex;
use MongoDB\BSON\Symbol;
use MongoDB\BSON\Timestamp;
use MongoDB\BSON\Undefined;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Internal\BSON\BsonType;
use OutOfBoundsException;
use Stringable;
use WeakReference;

use function bin2hex;
use function strlen;
use function substr;
use function unpack;

/** @internal */
final class Field
{
    /** @var WeakReference<Stringable> */
    private readonly WeakReference $source;
    private bool $isInitialized = false;
    private mixed $value;

    public function __construct(
        Stringable $source,
        public readonly string $key,
        public readonly BsonType $bsonType,
        public readonly int $keyOffset,
        public readonly int $keyLength,
        public readonly int|null $dataOffset = null,
        public readonly int|null $dataLength = null,
    ) {
        if (
            $this->bsonType === BsonType::Undefined
            || $this->bsonType === BsonType::Null
            || $this->bsonType === BsonType::MinKey
            || $this->bsonType === BsonType::MaxKey
        ) {
            if ($this->dataLength !== 0 || $this->dataOffset !== null) {
                throw new InvalidArgumentException('Invalid data offset or length');
            }
        } else {
            if ($this->dataLength === null || $this->dataOffset === null) {
                throw new InvalidArgumentException('Invalid data offset or length');
            }

            if ($this->dataLength < 0 || $this->dataOffset <= $this->keyOffset + $this->keyLength) {
                throw new InvalidArgumentException('Invalid data offset or length');
            }
        }

        $this->source = WeakReference::create($source);
    }

    public function getValue(): mixed
    {
        if (! $this->isInitialized) {
            $this->readValue();
        }

        return $this->value;
    }

    private function readValue(): void
    {
        $source = $this->source->get();
        if (! $source instanceof Stringable) {
            throw new OutOfBoundsException('BSON document is no longer valid');
        }

        $bson = (string) $source;

        switch ($this->bsonType) {
            case BsonType::Double:
                $this->value = (float) $this->unpackWithChecks('edata', $bson, $this->dataOffset, 'data');
                break;

            case BsonType::String:
                $this->value = $this->unpackWithChecks('a' . $this->dataLength . 'data', $bson, $this->dataOffset, 'data');
                break;

            case BsonType::JavaScript:
                $code        = $this->unpackWithChecks('a' . $this->dataLength . 'data', $bson, $this->dataOffset, 'data');
                $this->value = new Javascript($code);
                break;

            case BsonType::JavaScriptWithScope:
                $codeLength = (int) $this->unpackWithChecks('Vlength', $bson, $this->dataOffset, 'length');

                // $codeLength includes the trailing NUL byte — exclude it when reading code
                $code        = $this->unpackWithChecks('a' . ($codeLength - 1) . 'data', $bson, $this->dataOffset + 4, 'data');
                $scope       = Document::fromBSON(substr($bson, $this->dataOffset + 4 + $codeLength, $this->dataLength - $codeLength - 4));
                $this->value = new Javascript($code, $scope);
                break;

            case BsonType::Symbol:
                $this->value = new Symbol(substr($bson, $this->dataOffset, $this->dataLength));
                break;

            case BsonType::Document:
                $this->value = Document::fromBSON(substr($bson, $this->dataOffset, $this->dataLength));
                break;

            case BsonType::Array:
                $this->value = PackedArray::fromBSON(substr($bson, $this->dataOffset, $this->dataLength));
                break;

            case BsonType::Binary:
                $data = $this->unpackWithChecks('Csubtype/a' . ($this->dataLength - 1) . 'data', $bson, $this->dataOffset);

                /* subtype 2 has a redundant length header in the data */
                if ((int) $data['subtype'] === Binary::TYPE_OLD_BINARY) {
                    $data['data'] = substr($data['data'], 4);
                }

                $this->value = new Binary($data['data'], (int) $data['subtype']);
                break;

            case BsonType::Undefined:
                $this->value = new Undefined();
                break;

            case BsonType::Null:
                $this->value = null;
                break;

            case BsonType::MinKey:
                $this->value = new MinKey();
                break;

            case BsonType::MaxKey:
                $this->value = new MaxKey();
                break;

            case BsonType::ObjectId:
                $this->value = new ObjectId(bin2hex(substr($bson, $this->dataOffset, $this->dataLength)));
                break;

            case BsonType::Boolean:
                $this->value = (bool) $this->unpackWithChecks('Cdata', $bson, $this->dataOffset, 'data');
                break;

            case BsonType::Date:
                $timestamp   = $this->unpackWithChecks('qdata', $bson, $this->dataOffset, 'data');
                $this->value = new UTCDateTime($timestamp);
                break;

            case BsonType::Timestamp:
                $data        = $this->unpackWithChecks('Vincrement/Vtimestamp', $bson, $this->dataOffset);
                $this->value = new Timestamp((int) $data['increment'], (int) $data['timestamp']);
                break;

            case BsonType::Int64:
                $value       = $this->unpackWithChecks('qdata', $bson, $this->dataOffset, 'data');
                $this->value = new Int64($value);
                break;

            case BsonType::Regex:
                $pattern     = $this->unpackWithChecks('Z*pattern', $bson, $this->dataOffset, 'pattern');
                $flags       = $this->unpackWithChecks('Z*flags', $bson, $this->dataOffset + strlen($pattern) + 1, 'flags');
                $this->value = new Regex($pattern, $flags);
                break;

            case BsonType::DBPointer:
                $refLength   = (int) $this->unpackWithChecks('Vlength', $bson, $this->dataOffset, 'length');
                $data        = $this->unpackWithChecks('Z' . $refLength . 'ref/Z12id', $bson, $this->dataOffset + 4);
                $this->value = new DBPointer($data['ref'], bin2hex($data['id']));
                break;

            case BsonType::Int32:
                $this->value = (int) $this->unpackWithChecks('ldata', $bson, $this->dataOffset, 'data');
                break;

            case BsonType::Decimal128:
                $this->value = Decimal128::fromBinaryBytes(substr($bson, $this->dataOffset, $this->dataLength));
                break;

            default:
                throw new InvalidArgumentException('Invalid BSON type ' . $this->bsonType->name);
        }

        $this->isInitialized = true;
    }

    private function unpackWithChecks(string $format, string $string, int $offset = 0, ?string $key = null): mixed
    {
        $data = @unpack($format, $string, $offset);
        if ($data === false) {
            throw new InvalidArgumentException('Invalid BSON data');
        }

        if ($key !== null) {
            return $data[$key];
        }

        return $data;
    }
}
