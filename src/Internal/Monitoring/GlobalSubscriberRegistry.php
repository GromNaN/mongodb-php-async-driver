<?php

declare(strict_types=1);

namespace MongoDB\Internal\Monitoring;

use Closure;
use MongoDB\Driver\Monitoring\Subscriber;
use SplObjectStorage;
use Throwable;

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

    /**
     * @param class-string<TSubscriber> $subscriberClass
     * @param callable(TSubscriber)     $callSubsciber
     *
     * @template TSubscriber = object
     */
    public static function dispatch(array $managerSubscribers, string $subscriberClass, Closure $callSubsciber): void
    {
        foreach ($managerSubscribers as $subscriber) {
            if (! ($subscriber instanceof $subscriberClass)) {
                continue;
            }

            try {
                $callSubsciber($subscriber);
            } catch (Throwable) {
                // Subscribers must not interfere with operation execution.
            }
        }

        foreach (self::$subscribers ?? [] as $subscriber) {
            if (! ($subscriber instanceof $subscriberClass)) {
                continue;
            }

            try {
                $callSubsciber($subscriber);
            } catch (Throwable) {
                // Subscribers must not interfere with operation execution.
            }
        }
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
