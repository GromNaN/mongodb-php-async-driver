<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Internal\Operation\OperationExecutor;

use function get_debug_type;

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
    private ?OperationExecutor $executor;
    private ?WriteConcern $writeConcern;

    /**
     * Private constructor. Use the internal factory to create instances.
     *
     * @see \MongoDB\Internal\Server\ServerFactory
     */
    private function __construct()
    {
    }

    /** @internal Creates a new Server instance. */
    public static function createFromInternal(
        string $host,
        int $port,
        int $type,
        ?int $latency,
        ServerDescription $serverDescription,
        array $info = [],
        array $tags = [],
        ?OperationExecutor $executor = null,
        ?WriteConcern $writeConcern = null,
    ): static {
        $instance = new static();
        $instance->host = $host;
        $instance->port = $port;
        $instance->type = $type;
        $instance->latency = $latency;
        $instance->serverDescription = $serverDescription;
        $instance->info = $info;
        $instance->tags = $tags;
        $instance->executor = $executor;
        $instance->writeConcern = $writeConcern;

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
        $options ??= [];
        self::validateOptions($options, ['readConcern', 'readPreference', 'session', 'writeConcern']);

        $readPreference = $this->type === self::TYPE_STANDALONE ? null : ($options['readPreference'] ?? null);
        $readConcern    = $options['readConcern'] ?? null;
        $writeConcern   = $options['writeConcern'] ?? null;
        $session        = $options['session'] ?? null;

        return $this->executor->executeCommand($db, $command, $readPreference, $session, $readConcern, $writeConcern);
    }

    public function executeReadCommand(string $db, Command $command, ?array $options = null): CursorInterface
    {
        $options ??= [];
        self::validateOptions($options, ['readConcern', 'readPreference', 'session']);

        $readPreference = $this->type === self::TYPE_STANDALONE ? null : ($options['readPreference'] ?? null);
        $readConcern    = $options['readConcern'] ?? null;
        $session        = $options['session'] ?? null;

        return $this->executor->executeCommand($db, $command, $readPreference, $session, $readConcern);
    }

    public function executeWriteCommand(string $db, Command $command, ?array $options = null): CursorInterface
    {
        $options ??= [];
        self::validateOptions($options, ['session', 'writeConcern']);

        $writeConcern = $options['writeConcern'] ?? null;
        $session      = $options['session'] ?? null;

        return $this->executor->executeCommand($db, $command, null, $session, null, $writeConcern);
    }

    public function executeReadWriteCommand(string $db, Command $command, ?array $options = null): CursorInterface
    {
        $options ??= [];
        self::validateOptions($options, ['readConcern', 'readPreference', 'session', 'writeConcern']);

        $readPreference = $this->type === self::TYPE_STANDALONE ? null : ($options['readPreference'] ?? null);
        $readConcern    = $options['readConcern'] ?? null;
        $writeConcern   = $options['writeConcern'] ?? null;
        $session        = $options['session'] ?? null;

        return $this->executor->executeCommand($db, $command, $readPreference, $session, $readConcern, $writeConcern);
    }

    public function executeQuery(string $namespace, Query $query, ?array $options = null): CursorInterface
    {
        $options ??= [];
        self::validateOptions($options, ['readPreference', 'session']);

        $readPreference = $this->type === self::TYPE_STANDALONE ? null : ($options['readPreference'] ?? null);
        $session        = $options['session'] ?? null;

        return $this->executor->executeQuery($namespace, $query, $readPreference, $session);
    }

    public function executeBulkWrite(string $namespace, BulkWrite $bulkWrite, ?array $options = null): WriteResult
    {
        $options ??= [];
        self::validateOptions($options, ['session', 'writeConcern']);

        $writeConcern = $options['writeConcern'] ?? null;
        $session      = $options['session'] ?? null;

        return $this->executor->executeBulkWrite($namespace, $bulkWrite, $writeConcern, $session);
    }

    public function executeBulkWriteCommand(
        BulkWriteCommand $bulkWriteCommand,
        ?array $options = null,
    ): BulkWriteCommandResult {
        $options ??= [];
        self::validateOptions($options, ['session', 'writeConcern']);

        $writeConcern = $options['writeConcern'] ?? $this->writeConcern;
        $session      = $options['session'] ?? null;

        $result = $this->executor->executeBulkWriteCommand($bulkWriteCommand, $writeConcern, $session);

        $bulkWriteCommand->setSession($session);

        return $result;
    }

    public function __debugInfo(): array
    {
        return [
            'host'                => $this->host,
            'port'                => $this->port,
            'type'                => $this->type,
            'is_primary'          => $this->isPrimary(),
            'is_secondary'        => $this->isSecondary(),
            'is_arbiter'          => $this->isArbiter(),
            'is_hidden'           => $this->isHidden(),
            'is_passive'          => $this->isPassive(),
            'last_hello_response' => $this->info,
            'round_trip_time'     => $this->latency,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate a subset of options, throwing InvalidArgumentException for type mismatches.
     *
     * @param list<string> $keys Options to validate.
     */
    private static function validateOptions(array $options, array $keys): void
    {
        $expectedTypes = [
            'readConcern'    => ReadConcern::class,
            'readPreference' => ReadPreference::class,
            'session'        => Session::class,
            'writeConcern'   => WriteConcern::class,
        ];

        foreach ($keys as $key) {
            if (! isset($expectedTypes[$key])) {
                continue;
            }

            if (! isset($options[$key])) {
                continue;
            }

            $expected = $expectedTypes[$key];

            if (! ($options[$key] instanceof $expected)) {
                throw new InvalidArgumentException(
                    'Expected "' . $key . '" option to be ' . $expected . ', ' . get_debug_type($options[$key]) . ' given',
                );
            }
        }
    }
}
