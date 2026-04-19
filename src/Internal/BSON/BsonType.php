<?php

declare(strict_types=1);

namespace MongoDB\Internal\BSON;

final class BsonType
{
    public const int Double        = 1;
    public const int String        = 2;
    public const int Document      = 3;
    public const int Array         = 4;
    public const int Binary        = 5;
    public const int Undefined     = 6;
    public const int ObjectId      = 7;
    public const int Boolean       = 8;
    public const int Date          = 9;
    public const int Null          = 10;
    public const int Regex         = 11;
    public const int DBPointer     = 12;
    public const int Code          = 13;
    public const int Symbol        = 14;
    public const int CodeWithScope = 15;
    public const int Int32         = 16;
    public const int Timestamp     = 17;
    public const int Int64         = 18;
    public const int Decimal128    = 19;
    public const int MinKey        = 255;
    public const int MaxKey        = 127;
}
