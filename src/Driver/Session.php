<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use MongoDB\BSON\Timestamp;
use MongoDB\BSON\TimestampInterface;

use function in_array;
use function is_array;

final class Session
{
    public const string TRANSACTION_NONE = 'none';
    public const string TRANSACTION_STARTING = 'starting';
    public const string TRANSACTION_IN_PROGRESS = 'in_progress';
    public const string TRANSACTION_COMMITTED = 'committed';
    public const string TRANSACTION_ABORTED = 'aborted';

    private object $logicalSessionId;
    private ?object $clusterTime;
    private ?Timestamp $operationTime;
    private string $transactionState;
    private bool $dirty;
    private ?Server $server;
    private ?array $transactionOptions;

    /**
     * Private constructor. Use the internal factory to create instances.
     *
     * @see \MongoDB\Internal\Session\SessionFactory
     */
    private function __construct()
    {
    }

    /** @internal Creates a new Session instance. */
    public static function createFromInternal(
        object $logicalSessionId,
        ?object $clusterTime = null,
        ?Timestamp $operationTime = null,
        string $transactionState = self::TRANSACTION_NONE,
        bool $dirty = false,
        ?Server $server = null,
        ?array $transactionOptions = null,
    ): static {
        $instance = new static();
        $instance->logicalSessionId = $logicalSessionId;
        $instance->clusterTime = $clusterTime;
        $instance->operationTime = $operationTime;
        $instance->transactionState = $transactionState;
        $instance->dirty = $dirty;
        $instance->server = $server;
        $instance->transactionOptions = $transactionOptions;

        return $instance;
    }

    public function getLogicalSessionId(): object
    {
        return $this->logicalSessionId;
    }

    public function getClusterTime(): ?object
    {
        return $this->clusterTime;
    }

    public function getOperationTime(): ?Timestamp
    {
        return $this->operationTime;
    }

    public function getTransactionState(): string
    {
        return $this->transactionState;
    }

    public function isInTransaction(): bool
    {
        return in_array($this->transactionState, [
            self::TRANSACTION_STARTING,
            self::TRANSACTION_IN_PROGRESS,
        ], true);
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function getServer(): ?Server
    {
        return $this->server;
    }

    public function getTransactionOptions(): ?array
    {
        return $this->transactionOptions;
    }

    public function startTransaction(?array $options = null): void
    {
        if ($this->isInTransaction()) {
            throw new Exception\RuntimeException('Transaction already in progress');
        }

        $this->transactionState = self::TRANSACTION_STARTING;
        $this->transactionOptions = $options;
    }

    public function abortTransaction(): void
    {
        if (! $this->isInTransaction()) {
            throw new Exception\RuntimeException('No transaction is in progress');
        }

        $this->transactionState = self::TRANSACTION_ABORTED;
    }

    public function commitTransaction(): void
    {
        if (! $this->isInTransaction()) {
            throw new Exception\RuntimeException('No transaction is in progress');
        }

        $this->transactionState = self::TRANSACTION_COMMITTED;
    }

    public function endSession(): void
    {
        if ($this->isInTransaction()) {
            $this->abortTransaction();
        }

        $this->transactionState = self::TRANSACTION_NONE;
    }

    public function advanceClusterTime(array|object $clusterTime): void
    {
        $this->clusterTime = is_array($clusterTime) ? (object) $clusterTime : $clusterTime;
    }

    public function advanceOperationTime(TimestampInterface $operationTime): void
    {
        if (
            $this->operationTime !== null
            && $operationTime->getTimestamp() <= $this->operationTime->getTimestamp()
            && (
                $operationTime->getTimestamp() !== $this->operationTime->getTimestamp()
                || $operationTime->getIncrement() <= $this->operationTime->getIncrement()
            )
        ) {
            return;
        }

        $this->operationTime = new Timestamp(
            $operationTime->getIncrement(),
            $operationTime->getTimestamp(),
        );
    }
}
