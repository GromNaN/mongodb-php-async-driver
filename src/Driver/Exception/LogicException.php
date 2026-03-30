<?php
declare(strict_types=1);

namespace MongoDB\Driver\Exception;

use LogicException as BaseLogicException;

class LogicException extends BaseLogicException implements Exception
{
}
