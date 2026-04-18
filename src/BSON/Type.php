<?php

declare(strict_types=1);

namespace MongoDB\BSON;

interface Type
{
    public const int DOUBLE        = 1;
    public const int STRING        = 2;
    public const int DOCUMENT      = 3;
    public const int ARRAY         = 4;
    public const int BINARY        = 5;
    public const int UNDEFINED     = 6;
    public const int OBJECTID      = 7;
    public const int BOOLEAN       = 8;
    public const int UTCDATETIME   = 9;
    public const int NULL          = 10;
    public const int REGEX         = 11;
    public const int DBPOINTER     = 12;
    public const int CODE          = 13;
    public const int SYMBOL        = 14;
    public const int CODEWITHSCOPE = 15;
    public const int INT32         = 16;
    public const int TIMESTAMP     = 17;
    public const int INT64         = 18;
    public const int DECIMAL128    = 19;
    public const int MINKEY        = -1;
    public const int MAXKEY        = 127;
}
