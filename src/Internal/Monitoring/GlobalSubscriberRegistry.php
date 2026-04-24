<?php

declare(strict_types=1);

namespace MongoDB\Internal\Monitoring;

use Closure;
use MongoDB\Driver\Monitoring\Subscriber;
use Throwable;

use function array_filter;
use function array_values;
use function in_array;

/** @internal */
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
        self::$subscribers = array_values(
            array_filter(self::$subscribers, static fn ($s) => $s !== $subscriber),
        );
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
            if (! $subscriber instanceof $subscriberClass) {
                continue;
            }

            try {
                $callSubsciber($subscriber);
            } catch (Throwable) {
                // Subscribers must not interfere with operation execution.
            }
        }

        foreach (self::$subscribers as $subscriber) {
            if (! $subscriber instanceof $subscriberClass) {
                continue;
            }

            // Skip if already notified via the manager subscriber list.
            if (in_array($subscriber, $managerSubscribers, true)) {
                continue;
            }

            try {
                $callSubsciber($subscriber);
            } catch (Throwable) {
                // Subscribers must not interfere with operation execution.
            }
        }
    }
}
