<?php
declare(strict_types=1);

namespace MongoDB\Driver;

final class ServerDescription
{
    public const string TYPE_UNKNOWN = 'Unknown';
    public const string TYPE_STANDALONE = 'Standalone';
    public const string TYPE_MONGOS = 'Mongos';
    public const string TYPE_POSSIBLE_PRIMARY = 'PossiblePrimary';
    public const string TYPE_RS_PRIMARY = 'RSPrimary';
    public const string TYPE_RS_SECONDARY = 'RSSecondary';
    public const string TYPE_RS_ARBITER = 'RSArbiter';
    public const string TYPE_RS_OTHER = 'RSOther';
    public const string TYPE_RS_GHOST = 'RSGhost';
    public const string TYPE_LOAD_BALANCER = 'LoadBalancer';

    private string $host;
    private int $port;
    private string $type;
    private array $hello_response;
    private int $last_update_time;
    private ?int $round_trip_time;

    /**
     * Private constructor. Use the internal factory to create instances.
     */
    private function __construct()
    {
    }

    /** @internal Creates a new ServerDescription instance. */
    public static function createFromInternal(
        string $host,
        int $port,
        string $type,
        ?int $roundTripTime,
        array $helloResponse,
        int $lastUpdateTime,
    ): static {
        $instance = new static();
        $instance->host             = $host;
        $instance->port             = $port;
        $instance->type             = $type;
        $instance->round_trip_time  = $roundTripTime;
        $instance->hello_response   = $helloResponse;
        $instance->last_update_time = $lastUpdateTime;

        return $instance;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getRoundTripTime(): ?int
    {
        return $this->round_trip_time;
    }

    public function getHelloResponse(): array
    {
        return $this->hello_response;
    }

    public function getLastUpdateTime(): int
    {
        return $this->last_update_time;
    }

    public function __debugInfo(): array
    {
        return [
            'host'             => $this->host,
            'port'             => $this->port,
            'type'             => $this->type,
            'hello_response'   => $this->hello_response,
            'last_update_time' => $this->last_update_time,
            'round_trip_time'  => $this->round_trip_time,
        ];
    }
}
