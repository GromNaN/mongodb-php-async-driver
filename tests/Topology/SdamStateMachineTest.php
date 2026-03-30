<?php

declare(strict_types=1);

namespace MongoDB\Tests\Topology;

use MongoDB\Internal\Topology\InternalServerDescription;
use MongoDB\Internal\Topology\SdamStateMachine;
use MongoDB\Internal\Topology\TopologyType;
use PHPUnit\Framework\TestCase;

class SdamStateMachineTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeServer(
        string $host,
        int $port,
        string $type,
        array $helloResponse = [],
        ?string $setName = null,
    ): InternalServerDescription {
        return new InternalServerDescription(
            host:          $host,
            port:          $port,
            type:          $type,
            helloResponse: $helloResponse,
            setName:       $setName,
        );
    }

    private function apply(
        TopologyType $topologyType,
        array $servers,
        InternalServerDescription $newSd,
        ?string $replicaSetName = null,
    ): array {
        return SdamStateMachine::applyServerDescription(
            $topologyType,
            $servers,
            $newSd,
            $replicaSetName,
        );
    }

    // -------------------------------------------------------------------------
    // Unknown topology transitions
    // -------------------------------------------------------------------------

    public function testUnknownTopologyTransitionsToSingleOnStandalone(): void
    {
        $sd     = $this->makeServer('127.0.0.1', 27017, InternalServerDescription::TYPE_STANDALONE);
        $result = $this->apply(TopologyType::Unknown, [], $sd);

        $this->assertSame(TopologyType::Single, $result['type']);
        $this->assertArrayHasKey('127.0.0.1:27017', $result['servers']);
    }

    public function testUnknownTopologyTransitionsToShardedOnMongos(): void
    {
        $sd     = $this->makeServer('127.0.0.1', 27017, InternalServerDescription::TYPE_MONGOS);
        $result = $this->apply(TopologyType::Unknown, [], $sd);

        $this->assertSame(TopologyType::Sharded, $result['type']);
    }

    public function testUnknownTopologyTransitionsToRSOnPrimary(): void
    {
        $helloResponse = [
            'setName'           => 'rs0',
            'isWritablePrimary' => true,
            'hosts'             => ['127.0.0.1:27017'],
        ];
        $sd = new InternalServerDescription(
            host:          '127.0.0.1',
            port:          27017,
            type:          InternalServerDescription::TYPE_RS_PRIMARY,
            helloResponse: $helloResponse,
            setName:       'rs0',
        );

        $result = $this->apply(TopologyType::Unknown, [], $sd);

        $this->assertSame(TopologyType::ReplicaSetWithPrimary, $result['type']);
        $this->assertSame('rs0', $result['setName']);
    }

    // -------------------------------------------------------------------------
    // Sharded topology
    // -------------------------------------------------------------------------

    public function testShardedTopologyDropsNonMongos(): void
    {
        $mongos   = $this->makeServer('127.0.0.1', 27017, InternalServerDescription::TYPE_MONGOS);
        $servers  = ['127.0.0.1:27017' => $mongos];

        // Apply an RS primary to a sharded topology — it should be marked Unknown
        $rsPrimary = $this->makeServer('127.0.0.1', 27018, InternalServerDescription::TYPE_RS_PRIMARY, [], 'rs0');
        $result    = $this->apply(TopologyType::Sharded, $servers, $rsPrimary);

        $this->assertSame(TopologyType::Sharded, $result['type']);
        $this->assertSame(
            InternalServerDescription::TYPE_UNKNOWN,
            $result['servers']['127.0.0.1:27018']->type,
        );
    }

    // -------------------------------------------------------------------------
    // Replica-set topology
    // -------------------------------------------------------------------------

    public function testRSPrimaryAddsMembers(): void
    {
        $helloResponse = [
            'setName'           => 'rs0',
            'isWritablePrimary' => true,
            'hosts'             => ['127.0.0.1:27017', '127.0.0.1:27018'],
        ];
        $primary = new InternalServerDescription(
            host:          '127.0.0.1',
            port:          27017,
            type:          InternalServerDescription::TYPE_RS_PRIMARY,
            helloResponse: $helloResponse,
            setName:       'rs0',
        );

        $result = $this->apply(TopologyType::ReplicaSetNoPrimary, [], $primary, 'rs0');

        // Both hosts mentioned in 'hosts' must be tracked
        $this->assertArrayHasKey('127.0.0.1:27017', $result['servers']);
        $this->assertArrayHasKey('127.0.0.1:27018', $result['servers']);
        $this->assertSame(TopologyType::ReplicaSetWithPrimary, $result['type']);
    }

    public function testSetNameMismatchMarksUnknown(): void
    {
        // Topology already knows the set name 'rs0'
        $wrongPrimary = new InternalServerDescription(
            host:          '127.0.0.1',
            port:          27017,
            type:          InternalServerDescription::TYPE_RS_PRIMARY,
            helloResponse: ['setName' => 'wrong-set'],
            setName:       'wrong-set',
        );

        $result = $this->apply(
            TopologyType::ReplicaSetNoPrimary,
            [],
            $wrongPrimary,
            'rs0',  // known set name
        );

        $this->assertSame(
            InternalServerDescription::TYPE_UNKNOWN,
            $result['servers']['127.0.0.1:27017']->type,
            'Server with mismatched setName must be marked Unknown.',
        );
        // Set name must remain the previously-known one
        $this->assertSame('rs0', $result['setName']);
    }
}
