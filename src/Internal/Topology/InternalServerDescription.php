<?php

declare(strict_types=1);

namespace MongoDB\Internal\Topology;

/**
 * Internal mutable representation of a single server's discovered state.
 *
 * This is distinct from the public {@see \MongoDB\Driver\ServerDescription} DTO;
 * it is used exclusively within the SDAM machinery and connection management
 * layers, and is never exposed to application code directly.
 *
 * @internal
 */
final class InternalServerDescription
{
    // -----------------------------------------------------------------
    // Server type constants — mirrors \MongoDB\Driver\ServerDescription
    // -----------------------------------------------------------------

    public const TYPE_UNKNOWN          = 'Unknown';
    public const TYPE_STANDALONE       = 'Standalone';
    public const TYPE_MONGOS           = 'Mongos';
    public const TYPE_RS_PRIMARY       = 'RSPrimary';
    public const TYPE_RS_SECONDARY     = 'RSSecondary';
    public const TYPE_RS_ARBITER       = 'RSArbiter';
    public const TYPE_RS_OTHER         = 'RSOther';
    public const TYPE_RS_GHOST         = 'RSGhost';
    public const TYPE_POSSIBLE_PRIMARY = 'PossiblePrimary';
    public const TYPE_LOAD_BALANCER    = 'LoadBalancer';

    public function __construct(
        public readonly string      $host,
        public readonly int         $port,
        public readonly string      $type          = self::TYPE_UNKNOWN,
        public readonly array       $helloResponse = [],
        public readonly ?int        $roundTripTimeMs = null,
        public readonly ?string     $setName       = null,
        public readonly array       $tags          = [],
        public readonly bool        $primary       = false,
        public readonly int         $lastUpdateTime = 0,
        public readonly ?\Throwable $error         = null,
    ) {}

    // -----------------------------------------------------------------
    // Wither methods (return clones with a single field replaced)
    // -----------------------------------------------------------------

    public function withType(string $type): self
    {
        return new self(
            host:            $this->host,
            port:            $this->port,
            type:            $type,
            helloResponse:   $this->helloResponse,
            roundTripTimeMs: $this->roundTripTimeMs,
            setName:         $this->setName,
            tags:            $this->tags,
            primary:         $this->primary,
            lastUpdateTime:  $this->lastUpdateTime,
            error:           $this->error,
        );
    }

    public function withHelloResponse(array $response, int $rttMs): self
    {
        return new self(
            host:            $this->host,
            port:            $this->port,
            type:            $this->type,
            helloResponse:   $response,
            roundTripTimeMs: $rttMs,
            setName:         $this->setName,
            tags:            $this->tags,
            primary:         $this->primary,
            lastUpdateTime:  (int) (microtime(true) * 1000),
            error:           null,
        );
    }

    public function withError(\Throwable $error): self
    {
        return new self(
            host:            $this->host,
            port:            $this->port,
            type:            self::TYPE_UNKNOWN,
            helloResponse:   [],
            roundTripTimeMs: null,
            setName:         null,
            tags:            [],
            primary:         false,
            lastUpdateTime:  (int) (microtime(true) * 1000),
            error:           $error,
        );
    }

    // -----------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------

    /**
     * Returns true when the server has a known, usable type.
     */
    public function isAvailable(): bool
    {
        return $this->type !== self::TYPE_UNKNOWN;
    }

    /**
     * Returns the canonical "host:port" address string.
     */
    public function getAddress(): string
    {
        return "{$this->host}:{$this->port}";
    }

    // -----------------------------------------------------------------
    // Factory
    // -----------------------------------------------------------------

    /**
     * Derive a fully-populated InternalServerDescription from a hello response.
     *
     * Detection order (per SDAM spec):
     *  1. msg == "isdbgrid"                               → Mongos
     *  2. isreplicaset == true                            → RSGhost
     *  3. setName present + ismaster/isWritablePrimary    → RSPrimary
     *  4. setName present + secondary == true             → RSSecondary
     *  5. setName present + arbiterOnly == true           → RSArbiter
     *  6. setName present                                 → RSOther (hidden, recovering, …)
     *  7. loadBalanced == true                            → LoadBalancer
     *  8. (none of the above)                             → Standalone
     */
    public static function fromHello(
        string $host,
        int    $port,
        array  $response,
        int    $rttMs,
    ): self {
        $type    = self::TYPE_UNKNOWN;
        $setName = null;
        $tags    = [];
        $primary = false;

        // Extract convenience values.
        $msg              = $response['msg']               ?? null;
        $isReplicaSet     = (bool) ($response['isreplicaset'] ?? false);
        $responseSetName  = $response['setName']           ?? null;
        $ismaster         = (bool) ($response['ismaster']  ?? $response['isWritablePrimary'] ?? false);
        $secondary        = (bool) ($response['secondary']   ?? false);
        $arbiterOnly      = (bool) ($response['arbiterOnly'] ?? false);
        $loadBalanced     = (bool) ($response['loadBalanced'] ?? false);

        if ($msg === 'isdbgrid') {
            $type = self::TYPE_MONGOS;
        } elseif ($isReplicaSet) {
            $type = self::TYPE_RS_GHOST;
        } elseif ($responseSetName !== null) {
            $setName = (string) $responseSetName;

            if ($ismaster) {
                $type    = self::TYPE_RS_PRIMARY;
                $primary = true;
            } elseif ($secondary) {
                $type = self::TYPE_RS_SECONDARY;
            } elseif ($arbiterOnly) {
                $type = self::TYPE_RS_ARBITER;
            } else {
                $type = self::TYPE_RS_OTHER;
            }
        } elseif ($loadBalanced) {
            $type = self::TYPE_LOAD_BALANCER;
        } else {
            $type = self::TYPE_STANDALONE;
        }

        // Extract tag set if present.
        if (isset($response['tags']) && is_array($response['tags'])) {
            $tags = $response['tags'];
        }

        return new self(
            host:            $host,
            port:            $port,
            type:            $type,
            helloResponse:   $response,
            roundTripTimeMs: $rttMs,
            setName:         $setName,
            tags:            $tags,
            primary:         $primary,
            lastUpdateTime:  (int) (microtime(true) * 1000),
            error:           null,
        );
    }
}
