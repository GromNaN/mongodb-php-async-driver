<?php

declare(strict_types=1);

namespace MongoDB\Driver;

use MongoDB\BSON\Timestamp;
use MongoDB\BSON\TimestampInterface;
use MongoDB\Internal\Operation\OperationExecutor;
use MongoDB\Internal\Session\LogicalSessionId;
use MongoDB\Internal\SyncRunner;
use Throwable;

use function hrtime;
use function in_array;
use function intdiv;
use function is_array;

final class Session
{
    public const string TRANSACTION_NONE = 'none';
    public const string TRANSACTION_STARTING = 'starting';
    public const string TRANSACTION_IN_PROGRESS = 'in_progress';
    public const string TRANSACTION_COMMITTED = 'committed';
    public const string TRANSACTION_ABORTED = 'aborted';

    private LogicalSessionId $logicalSessionId;
    private ?object $clusterTime;
    private ?Timestamp $operationTime;
    private string $transactionState;
    private bool $dirty;
    private ?Server $server;
    private ?array $transactionOptions;

    /** Monotonically increasing counter; incremented each time startTransaction() is called. */
    private int $txnNumber = 0;

    /**
     * Executor used to send commitTransaction / abortTransaction commands.
     * Null only in unit-test contexts where no real executor is available.
     */
    private ?OperationExecutor $executor;

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
        LogicalSessionId $logicalSessionId,
        ?object $clusterTime = null,
        ?Timestamp $operationTime = null,
        string $transactionState = self::TRANSACTION_NONE,
        bool $dirty = false,
        ?Server $server = null,
        ?array $transactionOptions = null,
        ?OperationExecutor $executor = null,
    ): static {
        $instance = new static();
        $instance->logicalSessionId   = $logicalSessionId;
        $instance->clusterTime        = $clusterTime;
        $instance->operationTime      = $operationTime;
        $instance->transactionState   = $transactionState;
        $instance->dirty              = $dirty;
        $instance->server             = $server;
        $instance->transactionOptions = $transactionOptions;
        $instance->executor           = $executor;

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

    /** @internal Returns the current transaction number (used by CommandHelper). */
    public function getTxnNumber(): int
    {
        return $this->txnNumber;
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

    /**
     * Begin a new transaction on this session.
     *
     * Increments the txnNumber and transitions state to STARTING.
     * The actual `startTransaction: true` flag is injected into the first
     * command by {@see \MongoDB\Internal\Operation\CommandHelper::prepareCommand()}.
     *
     * @throws Exception\RuntimeException if a transaction is already in progress.
     */
    public function startTransaction(?array $options = null): void
    {
        if ($this->isInTransaction()) {
            throw new Exception\RuntimeException('Transaction already in progress');
        }

        $this->txnNumber++;
        $this->server             = null;   // unpin — first op will pin
        $this->dirty              = false;
        $this->transactionState   = self::TRANSACTION_STARTING;
        $this->transactionOptions = $options;
    }

    /**
     * Commit the active transaction.
     *
     * For an empty transaction (no commands sent yet) this is a no-op on the
     * wire and simply transitions state to COMMITTED.
     *
     * @throws Exception\RuntimeException if no transaction was started or if
     *                                    the session is in an aborted state.
     */
    public function commitTransaction(): void
    {
        if (
            $this->transactionState === self::TRANSACTION_NONE
            || $this->transactionState === self::TRANSACTION_ABORTED
        ) {
            throw new Exception\RuntimeException('No transaction started');
        }

        if ($this->transactionState === self::TRANSACTION_STARTING) {
            // Empty transaction — transition without sending a command.
            $this->transactionState = self::TRANSACTION_COMMITTED;

            return;
        }

        // IN_PROGRESS or re-commit of COMMITTED: send the command.
        if ($this->executor !== null) {
            SyncRunner::run(fn () => $this->executor->commitTransaction($this));
        }

        $this->transactionState = self::TRANSACTION_COMMITTED;
    }

    /**
     * Abort the active transaction.
     *
     * For an empty transaction (no commands sent yet) this is a no-op on the
     * wire.  Errors from the `abortTransaction` command are silently ignored
     * per the transactions spec.
     *
     * @throws Exception\RuntimeException if no transaction is in progress.
     */
    public function abortTransaction(): void
    {
        if (! $this->isInTransaction()) {
            throw new Exception\RuntimeException('No transaction is in progress');
        }

        if ($this->transactionState === self::TRANSACTION_STARTING) {
            // Empty transaction — transition without sending a command.
            $this->transactionState = self::TRANSACTION_ABORTED;
            $this->dirty            = false;

            return;
        }

        // IN_PROGRESS: send abortTransaction, ignore all errors.
        if ($this->executor !== null) {
            try {
                SyncRunner::run(fn () => $this->executor->abortTransaction($this));
            } catch (Throwable) {
                // Errors are intentionally swallowed per the transactions spec.
            }
        }

        $this->transactionState = self::TRANSACTION_ABORTED;
        $this->dirty            = false;
    }

    /**
     * Execute $callback inside a transaction with automatic retry.
     *
     * Retries the whole callback on TransientTransactionError and retries only
     * the commit on UnknownTransactionCommitResult, up to 120 seconds total.
     *
     * @param callable(Session): void $callback
     */
    public function withTransaction(callable $callback, ?array $options = null): void
    {
        $maxTimeMs = 120_000;
        $startMs   = intdiv(hrtime(true), 1_000_000);

        while (true) {
            $this->startTransaction($options);

            try {
                $callback($this);
            } catch (Throwable $callbackError) {
                if ($this->isInTransaction()) {
                    $this->abortTransaction();
                }

                $elapsed = intdiv(hrtime(true), 1_000_000) - $startMs;

                if (
                    $elapsed < $maxTimeMs
                    && $callbackError instanceof Exception\RuntimeException
                    && $callbackError->hasErrorLabel('TransientTransactionError')
                ) {
                    continue;
                }

                throw $callbackError;
            }

            // Commit loop: retry on UnknownTransactionCommitResult.
            while (true) {
                try {
                    $this->commitTransaction();

                    return;
                } catch (Throwable $commitError) {
                    $elapsed = intdiv(hrtime(true), 1_000_000) - $startMs;

                    if (
                        $elapsed < $maxTimeMs
                        && $commitError instanceof Exception\RuntimeException
                        && $commitError->hasErrorLabel('UnknownTransactionCommitResult')
                    ) {
                        continue;
                    }

                    if (
                        $elapsed < $maxTimeMs
                        && $commitError instanceof Exception\RuntimeException
                        && $commitError->hasErrorLabel('TransientTransactionError')
                    ) {
                        if ($this->isInTransaction()) {
                            $this->abortTransaction();
                        }

                        break; // retry entire transaction
                    }

                    throw $commitError;
                }
            }
        }
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

    /**
     * @internal Called by CommandHelper when the first transactional command is
     * prepared, advancing the state from STARTING → IN_PROGRESS.
     */
    public function startTransactionSent(): void
    {
        if ($this->transactionState !== self::TRANSACTION_STARTING) {
            return;
        }

        $this->transactionState = self::TRANSACTION_IN_PROGRESS;
    }
}
