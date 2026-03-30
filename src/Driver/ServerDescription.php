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
    private ?int $roundTripTime;
    private array $helloResponse;
    private int $lastUpdateTime;

    /**
     * Private constructor. Use the internal factory to create instances.
     *
     * @see \MongoDB\Internal\Server\ServerDescriptionFactory
     */
    private function __construct()
    {
    }

    /** @internal Creates a new ServerDescription instance. */
    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
    public static function _createFromInternal(
        string $host,
        int $port,
        string $type,
        ?int $roundTripTime,
        array $helloResponse,
        int $lastUpdateTime,
    ): static {
        $instance = new static();
        $instance->host = $host;
        $instance->port = $port;
        $instance->type = $type;
        $instance->roundTripTime = $roundTripTime;
        $instance->helloResponse = $helloResponse;
        $instance->lastUpdateTime = $lastUpdateTime;

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
        return $this->roundTripTime;
    }

    public function getHelloResponse(): array
    {
        return $this->helloResponse;
    }

    public function getLastUpdateTime(): int
    {
        return $this->lastUpdateTime;
    }
}
