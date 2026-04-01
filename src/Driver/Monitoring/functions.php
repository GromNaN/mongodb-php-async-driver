<?php
declare(strict_types=1);

/**
 * Bootstrap for the MongoDB userland driver.
 *
 * All classes are autoloaded via PSR-4 (see composer.json autoload section).
 * This file is only needed for global functions that cannot be autoloaded.
 */

namespace MongoDB\Driver\Monitoring;

use MongoDB\Internal\Monitoring\GlobalSubscriberRegistry;

function addSubscriber(Subscriber $subscriber): void
{
    GlobalSubscriberRegistry::add($subscriber);
}

function removeSubscriber(Subscriber $subscriber): void
{
    GlobalSubscriberRegistry::remove($subscriber);
}
