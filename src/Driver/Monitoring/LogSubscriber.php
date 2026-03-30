<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

interface LogSubscriber extends Subscriber
{
    public function log(int $level, string $domain, string $message): void;
}
