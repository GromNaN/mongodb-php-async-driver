<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver\Monitoring;

use MongoDB\Driver\Monitoring\Subscriber;
use MongoDB\Internal\Monitoring\Dispatcher;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function MongoDB\Driver\Monitoring\addSubscriber;
use function MongoDB\Driver\Monitoring\removeSubscriber;

class SubscriberFunctionsTest extends TestCase
{
    private Subscriber $subscriberA;
    private Subscriber $subscriberB;

    protected function setUp(): void
    {
        new ReflectionProperty(Dispatcher::class, 'globalSubscribers')->setValue(null, []);

        $this->subscriberA = $this->createStub(Subscriber::class);
        $this->subscriberB = $this->createStub(Subscriber::class);
    }

    public function testAddSubscriberRegistersIt(): void
    {
        addSubscriber($this->subscriberA);

        $this->assertContains($this->subscriberA, $this->collectDispatched());
    }

    public function testAddSubscriberIsIdempotent(): void
    {
        addSubscriber($this->subscriberA);
        addSubscriber($this->subscriberA);

        $this->assertCount(1, $this->collectDispatched());
    }

    public function testAddMultipleSubscribers(): void
    {
        addSubscriber($this->subscriberA);
        addSubscriber($this->subscriberB);

        $all = $this->collectDispatched();
        $this->assertContains($this->subscriberA, $all);
        $this->assertContains($this->subscriberB, $all);
        $this->assertCount(2, $all);
    }

    public function testRemoveSubscriberUnregistersIt(): void
    {
        addSubscriber($this->subscriberA);
        removeSubscriber($this->subscriberA);

        $this->assertNotContains($this->subscriberA, $this->collectDispatched());
    }

    public function testRemoveSubscriberIsIdempotent(): void
    {
        addSubscriber($this->subscriberA);
        removeSubscriber($this->subscriberA);
        removeSubscriber($this->subscriberA); // should not throw

        $this->assertEmpty($this->collectDispatched());
    }

    public function testRemoveDoesNotAffectOtherSubscribers(): void
    {
        addSubscriber($this->subscriberA);
        addSubscriber($this->subscriberB);
        removeSubscriber($this->subscriberA);

        $all = $this->collectDispatched();
        $this->assertNotContains($this->subscriberA, $all);
        $this->assertContains($this->subscriberB, $all);
    }

    public function testRemoveUnknownSubscriberIsNoop(): void
    {
        removeSubscriber($this->subscriberA); // never added

        $this->assertEmpty($this->collectDispatched());
    }

    /** @return Subscriber[] */
    private function collectDispatched(): array
    {
        $collected  = [];
        $dispatcher = new Dispatcher();
        $dispatcher->dispatch(Subscriber::class, static function (Subscriber $s) use (&$collected): void {
            $collected[] = $s;
        });

        return $collected;
    }
}
