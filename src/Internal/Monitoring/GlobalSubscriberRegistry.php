<?php
declare(strict_types=1);

namespace MongoDB\Internal\Monitoring;

use MongoDB\Driver\Monitoring\Subscriber;

use function array_search;
use function array_values;
use function in_array;

final class GlobalSubscriberRegistry
{
    /** @var Subscriber[] */
    private static array $subscribers = [];

    public static function add(Subscriber $subscriber): void
    {
        if (in_array($subscriber, self::$subscribers, true)) {
            return;
        }

        self::$subscribers[] = $subscriber;
    }

    public static function remove(Subscriber $subscriber): void
    {
        $key = array_search($subscriber, self::$subscribers, true);
        if ($key === false) {
            return;
        }

        unset(self::$subscribers[$key]);
        self::$subscribers = array_values(self::$subscribers);
    }

    /** @return Subscriber[] */
    public static function getAll(): array
    {
        return self::$subscribers;
    }
}
