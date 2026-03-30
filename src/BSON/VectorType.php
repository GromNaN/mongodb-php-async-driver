<?php

declare(strict_types=1);

namespace MongoDB\BSON;

enum VectorType: string
{
    case Float32    = 'float32';
    case Int8       = 'int8';
    case PackedBit  = 'packed_bit';
}
