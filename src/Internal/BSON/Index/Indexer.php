<?php

declare(strict_types=1);

namespace MongoDB\Internal\BSON\Index;

use InvalidArgumentException;
use MongoDB\Internal\BSON\BsonType;

use function preg_match;
use function sprintf;
use function strlen;
use function substr;
use function unpack;

/**
 * Scans raw BSON bytes and returns a flat list of field metadata records
 * (key name, type byte, and byte offsets/lengths for each field's data).
 * No PHP values are instantiated; decoding is deferred to Index\Field.
 *
 * @internal
 */
final class Indexer
{
    /** @return list<array{key: string, bsonType: int, keyOffset: int, keyLength: int, dataOffset: int|null, dataLength: int|null}> */
    public function getIndex(string $bson): array
    {
        $fields = [];

        $offset = 4;
        $length = $this->getBSONLength($bson);

        while ($offset < $length - 1) {
            [$data, $offset] = $this->getNextFieldData($bson, $offset);
            $fields[]        = $data;
        }

        return $fields;
    }

    private function getBSONLength(string $bson): int
    {
        $data = @unpack('V', $bson);
        if ($data === false) {
            throw new InvalidArgumentException('Invalid BSON data');
        }

        [1 => $length] = $data;
        if ($length < 5) {
            throw new InvalidArgumentException('Invalid BSON length');
        }

        if (strlen($bson) !== $length) {
            throw new InvalidArgumentException('Invalid BSON length');
        }

        if (substr($bson, -1, 1) !== "\0") {
            throw new InvalidArgumentException('Invalid BSON length');
        }

        return $length;
    }

    private function getNextFieldData(string $bson, int $offset): array
    {
        $data = @unpack('ctype/Z*key', $bson, $offset);
        if ($data === false) {
            throw new InvalidArgumentException('Invalid BSON data');
        }

        ['type' => $bsonType, 'key' => $key] = $data;

        if (! preg_match('//u', $key)) {
            throw new InvalidArgumentException('Invalid UTF-8 data in BSON key');
        }

        // Shift offset by 1 byte for type, key length and a null byte
        $newOffset = $offset + 1 + strlen($key) + 1;

        switch ($bsonType) {
            case BsonType::Double:
                $dataOffset = $newOffset;
                $dataLength = 8;
                $newOffset += 8;
                break;

            case BsonType::String:
            case BsonType::Code:
            case BsonType::Symbol:
                $data = @unpack('Vlength', $bson, $newOffset);
                if ($data === false) {
                    throw new InvalidArgumentException('Invalid BSON data');
                }

                // dataOffset points to the int32 length header (BsonDecoder convention)
                $dataOffset = $newOffset;
                $dataLength = 4 + (int) $data['length'];
                $newOffset += $dataLength;
                break;

            case BsonType::Document:
            case BsonType::Array:
                $data = @unpack('Vlength', $bson, $newOffset);
                if ($data === false) {
                    throw new InvalidArgumentException('Invalid BSON data');
                }

                $dataLength = (int) $data['length'];
                $dataOffset = $newOffset;
                $newOffset += $dataLength;
                break;

            case BsonType::Binary:
                $data = @unpack('Vlength', $bson, $newOffset);
                if ($data === false) {
                    throw new InvalidArgumentException('Invalid BSON data');
                }

                // dataOffset points to the int32 length header; total = int32 + subtype byte + data
                $dataOffset = $newOffset;
                $dataLength = 4 + 1 + (int) $data['length'];
                $newOffset += $dataLength;
                break;

            case BsonType::Undefined:
            case BsonType::Null:
            case BsonType::MinKey:
            case BsonType::MaxKey:
                $dataLength = 0;
                $dataOffset = null;
                break;

            case BsonType::ObjectId:
                // An ObjectId is always 12 bytes long
                $dataLength = 12;
                $dataOffset = $newOffset;
                $newOffset += $dataLength;
                break;

            case BsonType::Boolean:
                $dataLength = 1;
                $dataOffset = $newOffset;
                $newOffset += $dataLength;
                break;

            case BsonType::Date:
            case BsonType::Timestamp:
            case BsonType::Int64:
                $dataLength = 8;
                $dataOffset = $newOffset;
                $newOffset += $dataLength;
                break;

            case BsonType::Regex:
                $data = @unpack('Z*pattern', $bson, $newOffset);
                if ($data === false) {
                    throw new InvalidArgumentException('Invalid BSON data');
                }

                $dataOffset = $newOffset;
                $dataLength = strlen($data['pattern']) + 1;
                $newOffset += $dataLength;

                $data = @unpack('Z*options', $bson, $newOffset);
                if ($data === false) {
                    throw new InvalidArgumentException('Invalid BSON data');
                }

                // Since a regex contains two strings, the data will be both strings with their NUL bytes
                $dataLength += strlen($data['options']) + 1;
                $newOffset   = $dataOffset + $dataLength;
                break;

            case BsonType::DBPointer:
                // string (byte*12)
                $data = @unpack('Vlength', $bson, $newOffset);
                if ($data === false) {
                    throw new InvalidArgumentException('Invalid BSON data');
                }

                $dataOffset = $newOffset;
                // Data length includes 4 bytes for the string length and 12 bytes for an ObjectId
                $dataLength = 4 + (int) $data['length'] + 12;
                $newOffset += $dataLength;
                break;

            case BsonType::CodeWithScope:
                // int32 string document
                // The int32 contains the total number of bytes in the code_w_scope (including itself)
                $data = @unpack('Vlength', $bson, $newOffset);
                if ($data === false) {
                    throw new InvalidArgumentException('Invalid BSON data');
                }

                // dataOffset points to the outer int32 (BsonDecoder convention)
                $dataOffset = $newOffset;
                $dataLength = (int) $data['length'];
                $newOffset += $dataLength;
                break;

            case BsonType::Int32:
                $dataLength = 4;
                $dataOffset = $newOffset;
                $newOffset += $dataLength;
                break;

            case BsonType::Decimal128:
                $dataLength = 16;
                $dataOffset = $newOffset;
                $newOffset += $dataLength;
                break;

            default:
                throw new InvalidArgumentException(sprintf('Invalid BSON type "%d"', $bsonType));
        }

        return [
            [
                'key'        => $key,
                'bsonType'   => $bsonType,
                'keyOffset'  => $offset + 1,
                'keyLength'  => strlen($key),
                'dataOffset' => $dataOffset,
                'dataLength' => $dataLength,
            ],
            $newOffset,
        ];
    }
}
