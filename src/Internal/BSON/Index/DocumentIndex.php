<?php

declare(strict_types=1);

namespace MongoDB\Internal\BSON\Index;

use function array_column;

/** @internal */
final class DocumentIndex extends Index
{
    protected static function sortFields(array $fields): array
    {
        return array_column($fields, null, 'key');
    }
}
