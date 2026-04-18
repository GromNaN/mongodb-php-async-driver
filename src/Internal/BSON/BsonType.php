<?php

declare(strict_types=1);

namespace MongoDB\Internal\BSON;

enum BsonType: int
{
    case Double        = 1;
    case String        = 2;
    case Document      = 3;
    case Array         = 4;
    case Binary        = 5;
    case Undefined     = 6;
    case ObjectId      = 7;
    case Boolean       = 8;
    case Date          = 9;
    case Null          = 10;
    case Regex         = 11;
    case DBPointer     = 12;
    case JavaScript    = 13;
    case Symbol        = 14;
    case JavaScriptWithScope = 15;
    case Int32         = 16;
    case Timestamp     = 17;
    case Int64         = 18;
    case Decimal128    = 19;
    case MinKey        = -1;
    case MaxKey        = 127;
}
