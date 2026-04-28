<?php
declare(strict_types=1);

/**
 * Bootstrap for the MongoDB userland driver.
 *
 * All classes are autoloaded via PSR-4 (see composer.json autoload section).
 * This file is only needed for global functions that cannot be autoloaded.
 */

namespace MongoDB\Driver\Monitoring;

use MongoDB\Internal\Monitoring\Dispatcher;

use function function_exists;

if (! function_exists(__NAMESPACE__ . '\addSubscriber')) {
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
        Dispatcher::log($level, $domain, $message);
    }
}
