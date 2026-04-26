<?php
declare(strict_types=1);

/**
 * Bootstrap for the MongoDB userland driver.
 *
 * All classes are autoloaded via PSR-4 (see composer.json autoload section).
 * This file is only needed for global functions that cannot be autoloaded.
 */

namespace MongoDB\Driver\Monitoring;

use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Internal\Monitoring\Dispatcher;

use function function_exists;
use function sprintf;
use function str_contains;
use function strstr;

if (! function_exists('MongoDB\Driver\Monitoring\addSubscriber')) {
    function addSubscriber(Subscriber $subscriber): void
    {
        Dispatcher::addGlobalSubscriber($subscriber);
    }

    function removeSubscriber(Subscriber $subscriber): void
    {
        Dispatcher::removeGlobalSubscriber($subscriber);
    }

    function mongoc_log(int $level, string $domain, string $message): void
    {
        if ($level < 0 || $level > 6) {
            throw new InvalidArgumentException(
                sprintf('Expected level to be >= 0 and <= 6, %d given', $level),
            );
        }

        if (str_contains($domain, "\0")) {
            throw new InvalidArgumentException(
                sprintf('Domain cannot contain null bytes. Unexpected null byte after "%s".', strstr($domain, "\0", true)),
            );
        }

        if (str_contains($message, "\0")) {
            throw new InvalidArgumentException(
                sprintf('Message cannot contain null bytes. Unexpected null byte after "%s".', strstr($message, "\0", true)),
            );
        }

        Dispatcher::dispatch(
            [],
            LogSubscriber::class,
            static fn (LogSubscriber $subscriber) => $subscriber->log($level, $domain, $message),
        );
    }
}
