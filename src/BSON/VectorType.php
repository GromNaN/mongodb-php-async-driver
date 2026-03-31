<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use MongoDB\Driver\Exception\InvalidArgumentException;

use function array_is_list;
use function chr;
use function count;
use function get_debug_type;
use function is_bool;
use function is_float;
use function is_int;
use function ord;
use function pack;
use function sprintf;
use function strlen;
use function substr;
use function unpack;

enum VectorType: string
{
    case Float32   = 'float32';
    case Int8      = 'int8';
    case PackedBit = 'packed_bit';

    private const DTYPE_INT8       = 0x03;
    private const DTYPE_PACKED_BIT = 0x10;
    private const DTYPE_FLOAT32    = 0x27;

    public static function fromDtypeByte(int $byte): self
    {
        return match ($byte) {
            self::DTYPE_INT8       => self::Int8,
            self::DTYPE_PACKED_BIT => self::PackedBit,
            self::DTYPE_FLOAT32    => self::Float32,
            default                => throw new InvalidArgumentException(
                sprintf('Unknown vector dtype byte: 0x%02x', $byte),
            ),
        };
    }

    /**
     * Encode a PHP array into binary vector data (header + payload).
     */
    public function encode(array $vector): string
    {
        if (! array_is_list($vector)) {
            throw new InvalidArgumentException('Expected vector to be a list');
        }

        return match ($this) {
            self::Float32   => self::encodeFloat32($vector),
            self::Int8      => self::encodeInt8($vector),
            self::PackedBit => self::encodePackedBit($vector),
        };
    }

    /**
     * Decode binary vector data (header + payload) to a PHP array.
     */
    public function decode(string $data): array
    {
        return match ($this) {
            self::Float32   => self::decodeFloat32($data),
            self::Int8      => self::decodeInt8($data),
            self::PackedBit => self::decodePackedBit($data),
        };
    }

    // ------------------------------------------------------------------
    // Float32 helpers
    // ------------------------------------------------------------------

    private static function encodeFloat32(array $vector): string
    {
        $payload = '';
        foreach ($vector as $i => $v) {
            if (! is_float($v)) {
                throw new InvalidArgumentException(
                    sprintf('Expected vector[%d] to be a float, %s given', $i, get_debug_type($v)),
                );
            }

            $payload .= pack('g', $v);
        }

        return chr(self::DTYPE_FLOAT32) . chr(0) . $payload;
    }

    private static function decodeFloat32(string $data): array
    {
        $payload = substr($data, 2);
        $count   = (int) (strlen($payload) / 4);
        $result  = [];

        for ($i = 0; $i < $count; $i++) {
            $unpacked = unpack('g', substr($payload, $i * 4, 4));
            $result[] = (float) $unpacked[1];
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Int8 helpers
    // ------------------------------------------------------------------

    private static function encodeInt8(array $vector): string
    {
        $payload = '';
        foreach ($vector as $i => $v) {
            if (! is_int($v)) {
                throw new InvalidArgumentException(
                    sprintf('Expected vector[%d] to be an integer, %s given', $i, get_debug_type($v)),
                );
            }

            if ($v < -128 || $v > 127) {
                throw new InvalidArgumentException(
                    sprintf('Expected vector[%d] to be a signed 8-bit integer, %d given', $i, $v),
                );
            }

            $payload .= pack('c', $v);
        }

        return chr(self::DTYPE_INT8) . chr(0) . $payload;
    }

    private static function decodeInt8(string $data): array
    {
        $payload = substr($data, 2);
        $result  = [];

        for ($i = 0, $len = strlen($payload); $i < $len; $i++) {
            $byte = ord($payload[$i]);
            $result[] = $byte >= 128 ? $byte - 256 : $byte;
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // PackedBit helpers
    // ------------------------------------------------------------------

    private static function encodePackedBit(array $vector): string
    {
        $bits = [];
        foreach ($vector as $i => $v) {
            if (is_bool($v)) {
                $bits[] = $v ? 1 : 0;
            } elseif (is_int($v)) {
                if ($v !== 0 && $v !== 1) {
                    throw new InvalidArgumentException(
                        sprintf('Expected vector[%d] to be 0 or 1, %d given', $i, $v),
                    );
                }

                $bits[] = $v;
            } else {
                throw new InvalidArgumentException(
                    sprintf('Expected vector[%d] to be 0, 1, or a boolean, %s given', $i, get_debug_type($v)),
                );
            }
        }

        $numBits  = count($bits);
        $padding  = $numBits % 8 === 0 ? 0 : 8 - ($numBits % 8);
        $payload  = '';

        for ($i = 0; $i < $numBits; $i += 8) {
            $byte = 0;
            for ($b = 0; $b < 8; $b++) {
                $bitIdx = $i + $b;
                if ($bitIdx >= $numBits || ! $bits[$bitIdx]) {
                    continue;
                }

                $byte |= (1 << 7 - $b);
            }

            $payload .= chr($byte);
        }

        return chr(self::DTYPE_PACKED_BIT) . chr($padding) . $payload;
    }

    private static function decodePackedBit(string $data): array
    {
        $padding = ord($data[1]);
        $payload = substr($data, 2);
        $result  = [];

        for ($i = 0, $len = strlen($payload); $i < $len; $i++) {
            $byte    = ord($payload[$i]);
            $isLast  = ($i === $len - 1);
            $numBits = $isLast ? 8 - $padding : 8;

            for ($b = 0; $b < $numBits; $b++) {
                $result[] = ($byte >> 7 - $b) & 1;
            }
        }

        return $result;
    }
}
