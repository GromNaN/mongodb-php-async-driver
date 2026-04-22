<?php
declare(strict_types=1);

namespace MongoDB\Driver\Exception;

use RuntimeException as BaseRuntimeException;

use function in_array;
use function is_array;

class RuntimeException extends BaseRuntimeException implements Exception
{
    protected mixed $errorLabels = null;

    final public function hasErrorLabel(string $errorLabel): bool
    {
        if (! is_array($this->errorLabels)) {
            return false;
        }

        return in_array($errorLabel, $this->errorLabels, true);
    }
}
