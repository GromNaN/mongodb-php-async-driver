<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

interface LogSubscriber extends Subscriber
{
    public const int LEVEL_ERROR    = 0;
    public const int LEVEL_CRITICAL = 1;
    public const int LEVEL_WARNING  = 2;
    public const int LEVEL_MESSAGE  = 3;
    public const int LEVEL_INFO     = 4;
    public const int LEVEL_DEBUG    = 5;

    public function log(int $level, string $domain, string $message): void;
}
