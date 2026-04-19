<?php

declare(strict_types=1);

namespace MongoDB\Tests\Driver;

use MongoDB\BSON\Binary;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Session;
use PHPUnit\Framework\TestCase;

use function strlen;

class ManagerSessionTest extends TestCase
{
    public function testStartSessionReturnsSession(): void
    {
        $manager = new Manager('mongodb://127.0.0.1:27017');
        $session = $manager->startSession();

        $this->assertInstanceOf(Session::class, $session);
    }

    public function testStartSessionHasValidLogicalSessionId(): void
    {
        $manager = new Manager('mongodb://127.0.0.1:27017');
        $session = $manager->startSession();
        $lsid    = $session->getLogicalSessionId();

        $this->assertIsObject($lsid);
        $this->assertInstanceOf(Binary::class, $lsid->id);
        $this->assertSame(Binary::TYPE_UUID, $lsid->id->getType());
        $this->assertSame(16, strlen($lsid->id->getData()));
    }

    public function testStartSessionIsNotInTransaction(): void
    {
        $manager = new Manager('mongodb://127.0.0.1:27017');
        $session = $manager->startSession();

        $this->assertFalse($session->isInTransaction());
        $this->assertSame(Session::TRANSACTION_NONE, $session->getTransactionState());
    }

    public function testStartSessionReturnsDistinctLsids(): void
    {
        $manager  = new Manager('mongodb://127.0.0.1:27017');
        $sessionA = $manager->startSession();
        $sessionB = $manager->startSession();

        $this->assertNotEquals(
            $sessionA->getLogicalSessionId()->id->getData(),
            $sessionB->getLogicalSessionId()->id->getData(),
        );
    }
}
