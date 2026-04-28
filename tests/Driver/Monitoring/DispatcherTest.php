<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver\Monitoring;

use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\LogSubscriber;
use MongoDB\Driver\Monitoring\SDAMSubscriber;
use MongoDB\Driver\Monitoring\Subscriber;
use MongoDB\Internal\Monitoring\Dispatcher;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use stdClass;

use function MongoDB\Driver\Monitoring\addSubscriber;

/**
 * Unit tests for Dispatcher::dispatch().
 *
 * Covers:
 * - class filter: only subscribers matching $subscriberClass are called
 * - both manager and global subscribers are iterated
 * - exceptions thrown by subscribers are swallowed
 * - all three subscriber interfaces: CommandSubscriber, SDAMSubscriber, LogSubscriber
 */
class DispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        new ReflectionProperty(Dispatcher::class, 'globalSubscribers')->setValue(null, []);
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

        $called     = [];
        $dispatcher = new Dispatcher();
        $dispatcher->dispatch(CommandSubscriber::class, static function (object $s) use (&$called): void {
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

        $called     = [];
        $dispatcher = new Dispatcher();
        $dispatcher->addSubscriber($manager);
        $dispatcher->dispatch(Subscriber::class, static function (object $s) use (&$called): void {
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

        $order      = [];
        $dispatcher = new Dispatcher();
        $dispatcher->addSubscriber($manager);
        $dispatcher->dispatch(Subscriber::class, static function (object $s) use (&$order, $manager, $global): void {
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

        $called     = [];
        $dispatcher = new Dispatcher();
        $dispatcher->dispatch(Subscriber::class, static function (object $s) use (&$called, $throwing): void {
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

        $received   = null;
        $dispatcher = new Dispatcher();
        $dispatcher->dispatch(CommandSubscriber::class, static function (object $s) use (&$received): void {
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

        $received   = null;
        $dispatcher = new Dispatcher();
        $dispatcher->dispatch(SDAMSubscriber::class, static function (object $s) use (&$received): void {
            $received = $s;
        });

        $this->assertSame($subscriber, $received);
    }

    public function testCommandSubscriberNotCalledForSdamDispatch(): void
    {
        $commandSubscriber = $this->createMock(CommandSubscriber::class);
        addSubscriber($commandSubscriber);

        $called     = false;
        $dispatcher = new Dispatcher();
        $dispatcher->dispatch(SDAMSubscriber::class, static function (object $s) use (&$called): void {
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

        $received   = null;
        $dispatcher = new Dispatcher();
        $dispatcher->dispatch(LogSubscriber::class, static function (object $s) use (&$received): void {
            $received = $s;
        });

        $this->assertSame($subscriber, $received);
    }

    public function testNonLogSubscriberNotCalledForLogDispatch(): void
    {
        $commandSubscriber = $this->createMock(CommandSubscriber::class);
        addSubscriber($commandSubscriber);

        $called     = false;
        $dispatcher = new Dispatcher();
        $dispatcher->dispatch(LogSubscriber::class, static function (object $s) use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);
    }

    // -------------------------------------------------------------------------
    // Empty registry
    // -------------------------------------------------------------------------

    public function testDispatchWithNoSubscribersIsNoop(): void
    {
        $called     = false;
        $dispatcher = new Dispatcher();
        $dispatcher->dispatch(Subscriber::class, static function (object $s) use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);
    }

    // -------------------------------------------------------------------------
    // Event lazy instantiation — $event passed by reference
    // -------------------------------------------------------------------------

    public function testDispatchCreatesEventOnlyOnce(): void
    {
        $sub1 = $this->createMock(Subscriber::class);
        $sub2 = $this->createMock(Subscriber::class);

        addSubscriber($sub1);
        addSubscriber($sub2);

        $createdCount = 0;
        $dispatcher   = new Dispatcher();
        $dispatcher->dispatch(Subscriber::class, static function (object $s, ?object &$event) use (&$createdCount): void {
            if ($event !== null) {
                return;
            }

            $createdCount++;
            $event = new stdClass();
        });

        $this->assertSame(1, $createdCount);
    }

    public function testDispatchSharesEventInstanceAcrossManagerAndGlobalSubscribers(): void
    {
        $global  = $this->createMock(Subscriber::class);
        $manager = $this->createMock(Subscriber::class);

        addSubscriber($global);

        $received   = [];
        $dispatcher = new Dispatcher();
        $dispatcher->addSubscriber($manager);
        $dispatcher->dispatch(Subscriber::class, static function (object $s, ?object &$event) use (&$received): void {
            $event ??= new stdClass();
            $received[] = $event;
        });

        $this->assertCount(2, $received);
        $this->assertSame($received[0], $received[1], 'The same event instance must be shared across all subscribers');
    }

    // -------------------------------------------------------------------------
    // Dispatcher::log()
    // -------------------------------------------------------------------------

    public function testLogDispatchesToGlobalLogSubscribers(): void
    {
        $subscriber = $this->createMock(LogSubscriber::class);
        $subscriber->expects($this->once())->method('log')->with(3, 'test', 'hello');

        addSubscriber($subscriber);
        Dispatcher::log(3, 'test', 'hello');
    }

    public function testLogIgnoresNonLogSubscribers(): void
    {
        $command = $this->createMock(CommandSubscriber::class);
        $command->expects($this->never())->method($this->anything());

        addSubscriber($command);
        Dispatcher::log(0, 'domain', 'message');
    }

    public function testLogSwallowsSubscriberExceptions(): void
    {
        $throwing = $this->createMock(LogSubscriber::class);
        $throwing->method('log')->willThrowException(new RuntimeException('boom'));

        $ok      = $this->createMock(LogSubscriber::class);
        $ok->expects($this->once())->method('log');

        addSubscriber($throwing);
        addSubscriber($ok);

        Dispatcher::log(1, 'dom', 'msg'); // must not throw
    }

    public function testLogThrowsOnInvalidLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Dispatcher::log(7, 'dom', 'msg');
    }

    public function testLogThrowsOnNullByteInDomain(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Dispatcher::log(0, "do\0main", 'msg');
    }

    public function testLogThrowsOnNullByteInMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Dispatcher::log(0, 'dom', "mes\0sage");
    }
}
