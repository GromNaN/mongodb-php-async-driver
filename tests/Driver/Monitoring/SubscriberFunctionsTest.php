<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver\Monitoring;

use MongoDB\Driver\Monitoring\Subscriber;
use MongoDB\Internal\Monitoring\GlobalSubscriberRegistry;
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
        new ReflectionProperty(GlobalSubscriberRegistry::class, 'subscribers')->setValue(null, null);

        $this->subscriberA = $this->createStub(Subscriber::class);
        $this->subscriberB = $this->createStub(Subscriber::class);
    }

    public function testAddSubscriberRegistersIt(): void
    {
        addSubscriber($this->subscriberA);

        $this->assertContains($this->subscriberA, GlobalSubscriberRegistry::getAll());
    }

    public function testAddSubscriberIsIdempotent(): void
    {
        addSubscriber($this->subscriberA);
        addSubscriber($this->subscriberA);

        $this->assertCount(1, GlobalSubscriberRegistry::getAll());
    }

    public function testAddMultipleSubscribers(): void
    {
        addSubscriber($this->subscriberA);
        addSubscriber($this->subscriberB);

        $all = GlobalSubscriberRegistry::getAll();
        $this->assertContains($this->subscriberA, $all);
        $this->assertContains($this->subscriberB, $all);
        $this->assertCount(2, $all);
    }

    public function testRemoveSubscriberUnregistersIt(): void
    {
        addSubscriber($this->subscriberA);
        removeSubscriber($this->subscriberA);

        $this->assertNotContains($this->subscriberA, GlobalSubscriberRegistry::getAll());
    }

    public function testRemoveSubscriberIsIdempotent(): void
    {
        addSubscriber($this->subscriberA);
        removeSubscriber($this->subscriberA);
        removeSubscriber($this->subscriberA); // should not throw

        $this->assertEmpty(GlobalSubscriberRegistry::getAll());
    }

    public function testRemoveDoesNotAffectOtherSubscribers(): void
    {
        addSubscriber($this->subscriberA);
        addSubscriber($this->subscriberB);
        removeSubscriber($this->subscriberA);

        $all = GlobalSubscriberRegistry::getAll();
        $this->assertNotContains($this->subscriberA, $all);
        $this->assertContains($this->subscriberB, $all);
    }

    public function testRemoveUnknownSubscriberIsNoop(): void
    {
        removeSubscriber($this->subscriberA); // never added

        $this->assertEmpty(GlobalSubscriberRegistry::getAll());
    }
}
