<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver\Monitoring;

use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\LogSubscriber;
use MongoDB\Driver\Monitoring\SDAMSubscriber;
use MongoDB\Driver\Monitoring\Subscriber;
use MongoDB\Internal\Monitoring\GlobalSubscriberRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

use function MongoDB\Driver\Monitoring\addSubscriber;

/**
 * Unit tests for GlobalSubscriberRegistry::dispatch().
 *
 * Covers:
 * - class filter: only subscribers matching $subscriberClass are called
 * - both manager and global subscribers are iterated
 * - exceptions thrown by subscribers are swallowed
 * - all three subscriber interfaces: CommandSubscriber, SDAMSubscriber, LogSubscriber
 */
class GlobalSubscriberRegistryDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        new ReflectionProperty(GlobalSubscriberRegistry::class, 'subscribers')->setValue(null, []);
    }

    // -------------------------------------------------------------------------
    // Class filter
    // -------------------------------------------------------------------------

    public function testDispatchOnlyCallsMatchingSubscriberClass(): void
    {
        $commandSubscriber = $this->createMock(CommandSubscriber::class);
        $sdamSubscriber    = $this->createMock(SDAMSubscriber::class);

        addSubscriber($commandSubscriber);
        addSubscriber($sdamSubscriber);

        $called = [];
        GlobalSubscriberRegistry::dispatch([], CommandSubscriber::class, static function (object $s) use (&$called): void {
            $called[] = $s;
        });

        $this->assertContains($commandSubscriber, $called);
        $this->assertNotContains($sdamSubscriber, $called);
    }

    // -------------------------------------------------------------------------
    // Manager vs global subscribers
    // -------------------------------------------------------------------------

    public function testDispatchCallsBothManagerAndGlobalSubscribers(): void
    {
        $global  = $this->createMock(Subscriber::class);
        $manager = $this->createMock(Subscriber::class);

        addSubscriber($global);

        $called = [];
        GlobalSubscriberRegistry::dispatch([$manager], Subscriber::class, static function (object $s) use (&$called): void {
            $called[] = $s;
        });

        $this->assertContains($manager, $called);
        $this->assertContains($global, $called);
    }

    public function testManagerSubscribersAreCalledBeforeGlobalSubscribers(): void
    {
        $global  = $this->createMock(Subscriber::class);
        $manager = $this->createMock(Subscriber::class);

        addSubscriber($global);

        $order = [];
        GlobalSubscriberRegistry::dispatch([$manager], Subscriber::class, static function (object $s) use (&$order, $manager, $global): void {
            if ($s === $manager) {
                $order[] = 'manager';
            } elseif ($s === $global) {
                $order[] = 'global';
            }
        });

        $this->assertSame(['manager', 'global'], $order);
    }

    // -------------------------------------------------------------------------
    // Exception swallowing
    // -------------------------------------------------------------------------

    public function testDispatchSwallowsSubscriberExceptions(): void
    {
        $throwing = $this->createMock(Subscriber::class);
        $ok       = $this->createMock(Subscriber::class);

        addSubscriber($throwing);
        addSubscriber($ok);

        $called = [];
        GlobalSubscriberRegistry::dispatch([], Subscriber::class, static function (object $s) use (&$called, $throwing): void {
            if ($s === $throwing) {
                throw new RuntimeException('subscriber error');
            }

            $called[] = $s;
        });

        // Remaining subscribers must still be called despite the exception.
        $this->assertContains($ok, $called);
    }

    // -------------------------------------------------------------------------
    // CommandSubscriber
    // -------------------------------------------------------------------------

    public function testDispatchCommandSubscriberReceivesCallbacks(): void
    {
        $subscriber = $this->createMock(CommandSubscriber::class);
        addSubscriber($subscriber);

        $received = null;
        GlobalSubscriberRegistry::dispatch([], CommandSubscriber::class, static function (object $s) use (&$received): void {
            $received = $s;
        });

        $this->assertSame($subscriber, $received);
    }

    // -------------------------------------------------------------------------
    // SDAMSubscriber
    // -------------------------------------------------------------------------

    public function testDispatchSdamSubscriberReceivesCallbacks(): void
    {
        $subscriber = $this->createMock(SDAMSubscriber::class);
        addSubscriber($subscriber);

        $received = null;
        GlobalSubscriberRegistry::dispatch([], SDAMSubscriber::class, static function (object $s) use (&$received): void {
            $received = $s;
        });

        $this->assertSame($subscriber, $received);
    }

    public function testCommandSubscriberNotCalledForSdamDispatch(): void
    {
        $commandSubscriber = $this->createMock(CommandSubscriber::class);
        addSubscriber($commandSubscriber);

        $called = false;
        GlobalSubscriberRegistry::dispatch([], SDAMSubscriber::class, static function (object $s) use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);
    }

    // -------------------------------------------------------------------------
    // LogSubscriber
    // -------------------------------------------------------------------------

    public function testDispatchLogSubscriberReceivesCallbacks(): void
    {
        $subscriber = $this->createMock(LogSubscriber::class);
        addSubscriber($subscriber);

        $received = null;
        GlobalSubscriberRegistry::dispatch([], LogSubscriber::class, static function (object $s) use (&$received): void {
            $received = $s;
        });

        $this->assertSame($subscriber, $received);
    }

    public function testNonLogSubscriberNotCalledForLogDispatch(): void
    {
        $commandSubscriber = $this->createMock(CommandSubscriber::class);
        addSubscriber($commandSubscriber);

        $called = false;
        GlobalSubscriberRegistry::dispatch([], LogSubscriber::class, static function (object $s) use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);
    }

    // -------------------------------------------------------------------------
    // Empty registry
    // -------------------------------------------------------------------------

    public function testDispatchWithNoSubscribersIsNoop(): void
    {
        $called = false;
        GlobalSubscriberRegistry::dispatch([], Subscriber::class, static function (object $s) use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);
    }
}
