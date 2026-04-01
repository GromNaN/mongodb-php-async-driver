<?php
declare(strict_types=1);

namespace MongoDB\Driver\Exception;

use RuntimeException as BaseRuntimeException;

use function in_array;
use function is_array;

class RuntimeException extends BaseRuntimeException implements Exception
{
    protected mixed $errorLabels = null;

    public function hasErrorLabel(string $label): bool
    {
        if (! is_array($this->errorLabels)) {
            return false;
        }

        return in_array($label, $this->errorLabels, true);
    }
}
