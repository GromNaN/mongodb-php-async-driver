<?php

declare(strict_types=1);

namespace MongoDB\Internal\Monitoring;

use MongoDB\Driver\Monitoring\Subscriber;
use SplObjectStorage;

/** @internal */
final class GlobalSubscriberRegistry
{
    /** @var SplObjectStorage<Subscriber, null> */
    private static ?SplObjectStorage $subscribers = null;

    public static function add(Subscriber $subscriber): void
    {
        (self::$subscribers ??= new SplObjectStorage())->offsetSet($subscriber);
    }

    public static function remove(Subscriber $subscriber): void
    {
        self::$subscribers?->offsetUnset($subscriber);
    }

    /** @return Subscriber[] */
    public static function getAll(): array
    {
        $result = [];
        foreach (self::$subscribers ?? [] as $subscriber) {
            $result[] = $subscriber;
        }

        return $result;
    }
}
