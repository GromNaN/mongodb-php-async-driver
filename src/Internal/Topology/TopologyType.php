<?php

declare(strict_types=1);

namespace MongoDB\Internal\Topology;

/**
 * Represents the discovered topology type for a MongoDB deployment.
 *
 * @internal
 */
enum TopologyType: string
{
    case Unknown                = 'Unknown';
    case Single                 = 'Single';
    case ReplicaSetNoPrimary    = 'ReplicaSetNoPrimary';
    case ReplicaSetWithPrimary  = 'ReplicaSetWithPrimary';
    case Sharded                = 'Sharded';
    case LoadBalanced           = 'LoadBalanced';
}
