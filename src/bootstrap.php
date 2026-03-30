<?php
declare(strict_types=1);

/**
 * Bootstrap for the MongoDB userland driver.
 *
 * All classes are autoloaded via PSR-4 (see composer.json autoload section).
 * This file is only needed for global functions that cannot be autoloaded.
 */

namespace MongoDB\Driver\Monitoring {

    use function function_exists;

    if (! function_exists('MongoDB\Driver\Monitoring\addSubscriber')) {
        function addSubscriber(Subscriber $subscriber): void
        {
            // Global subscriber registry is managed via Manager instances.
            // This function is a no-op at the global level in this driver;
            // use Manager::addSubscriber() to register per-manager.
        }

        function removeSubscriber(Subscriber $subscriber): void
        {
            // No-op at global level. Use Manager::removeSubscriber().
        }
    }
}
