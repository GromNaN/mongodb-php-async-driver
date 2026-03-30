<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use MongoDB\Internal\Operation\OperationExecutor;

final class Server
{
    public const int TYPE_UNKNOWN = 0;
    public const int TYPE_STANDALONE = 1;
    public const int TYPE_MONGOS = 2;
    public const int TYPE_POSSIBLE_PRIMARY = 3;
    public const int TYPE_RS_PRIMARY = 4;
    public const int TYPE_RS_SECONDARY = 5;
    public const int TYPE_RS_ARBITER = 6;
    public const int TYPE_RS_OTHER = 7;
    public const int TYPE_RS_GHOST = 8;
    public const int TYPE_LOAD_BALANCER = 9;

    private string $host;
    private int $port;
    private int $type;
    private ?int $latency;
    private ServerDescription $serverDescription;
    private array $info;
    private array $tags;

    /**
     * Private constructor. Use the internal factory to create instances.
     *
     * @see \MongoDB\Internal\Server\ServerFactory
     */
    private function __construct()
    {
    }

    /** @internal Creates a new Server instance. */
    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
    public static function _createFromInternal(
        string $host,
        int $port,
        int $type,
        ?int $latency,
        ServerDescription $serverDescription,
        array $info = [],
        array $tags = [],
    ): static {
        $instance = new static();
        $instance->host = $host;
        $instance->port = $port;
        $instance->type = $type;
        $instance->latency = $latency;
        $instance->serverDescription = $serverDescription;
        $instance->info = $info;
        $instance->tags = $tags;

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

    public function getType(): int
    {
        return $this->type;
    }

    public function getLatency(): ?int
    {
        return $this->latency;
    }

    public function getServerDescription(): ServerDescription
    {
        return $this->serverDescription;
    }

    public function getInfo(): array
    {
        return $this->info;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function isPrimary(): bool
    {
        return $this->type === self::TYPE_RS_PRIMARY || $this->type === self::TYPE_STANDALONE;
    }

    public function isSecondary(): bool
    {
        return $this->type === self::TYPE_RS_SECONDARY;
    }

    public function isArbiter(): bool
    {
        return $this->type === self::TYPE_RS_ARBITER;
    }

    public function isHidden(): bool
    {
        return (bool) ($this->info['hidden'] ?? false);
    }

    public function isPassive(): bool
    {
        return (bool) ($this->info['passive'] ?? false);
    }

    public function executeCommand(string $db, Command $command, ?array $options = null): CursorInterface
    {
        return OperationExecutor::executeCommand($this, $db, $command, $options);
    }

    public function executeReadCommand(string $db, Command $command, ?array $options = null): CursorInterface
    {
        return OperationExecutor::executeReadCommand($this, $db, $command, $options);
    }

    public function executeWriteCommand(string $db, Command $command, ?array $options = null): CursorInterface
    {
        return OperationExecutor::executeWriteCommand($this, $db, $command, $options);
    }

    public function executeReadWriteCommand(string $db, Command $command, ?array $options = null): CursorInterface
    {
        return OperationExecutor::executeReadWriteCommand($this, $db, $command, $options);
    }

    public function executeQuery(string $namespace, Query $query, ?array $options = null): CursorInterface
    {
        return OperationExecutor::executeQuery($this, $namespace, $query, $options);
    }

    public function executeBulkWrite(string $namespace, BulkWrite $bulk, ?array $options = null): WriteResult
    {
        return OperationExecutor::executeBulkWrite($this, $namespace, $bulk, $options);
    }
}
