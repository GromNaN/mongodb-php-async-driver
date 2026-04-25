<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use MongoDB\BSON\Document;
use MongoDB\Driver\Exception\UnexpectedValueException;
use stdClass;

use function is_array;

final class Command
{
    private array $options;

    public function __construct(private array|object $document, ?array $commandOptions = null)
    {
        if (! is_document($document)) {
            throw UnexpectedValueException::documentRequiredAsRoot();
        }

        $this->options = $commandOptions ?? [];
    }

    /** @internal */
    public function getDocument(): array|object
    {
        return $this->document;
    }

    /** @internal */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function __debugInfo(): array
    {
        $doc = $this->document;
        if ($doc instanceof Document) {
            $doc = $doc->toPHP();
        } elseif (is_array($doc)) {
            $obj = new stdClass();
            foreach ($doc as $k => $v) {
                $obj->$k = $v;
            }

            $doc = $obj;
        }

        return ['command' => $doc];
    }
}
