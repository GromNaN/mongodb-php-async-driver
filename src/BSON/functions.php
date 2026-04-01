<?php
declare(strict_types=1);

namespace MongoDB\BSON;

/**
 * Returns the BSON representation of a JSON value.
 *
 * @deprecated Use Document::fromJSON() or PackedArray::fromJSON() instead.
 */
function fromJSON(string $json): string
{
    return (string) Document::fromJSON($json);
}

/**
 * Returns the BSON representation of a PHP value.
 *
 * @deprecated Use Document::fromPHP() instead.
 */
function fromPHP(array|object $value): string
{
    return (string) Document::fromPHP($value);
}

/**
 * Returns the Canonical Extended JSON representation of a BSON value.
 *
 * @deprecated Use Document::toCanonicalExtendedJSON() instead.
 */
function toCanonicalExtendedJSON(string $bson): string
{
    return Document::fromBSON($bson)->toCanonicalExtendedJSON();
}

/**
 * Returns the Legacy Extended JSON representation of a BSON value.
 *
 * @deprecated Use Document::toCanonicalExtendedJSON() instead.
 */
function toJSON(string $bson): string
{
    return Document::fromBSON($bson)->toCanonicalExtendedJSON();
}

/**
 * Returns the PHP representation of a BSON value.
 *
 * @deprecated Use Document::toPHP() instead.
 *
 * @param array|null $typeMap
 */
function toPHP(string $bson, ?array $typeMap = null): array|object
{
    return Document::fromBSON($bson)->toPHP($typeMap);
}

/**
 * Returns the Relaxed Extended JSON representation of a BSON value.
 *
 * @deprecated Use Document::toRelaxedExtendedJSON() instead.
 */
function toRelaxedExtendedJSON(string $bson): string
{
    return Document::fromBSON($bson)->toRelaxedExtendedJSON();
}
