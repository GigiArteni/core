<?php

namespace Apiato\Repository\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transactional Repository Trait
 *
 * Provides intelligent database transaction handling for repository operations.
 * Includes deadlock retry logic, timeout handling, and transaction-aware methods.
 *
 * Features:
 * - Smart transaction wrapping for bulk operations
 * - Deadlock detection and retry logic
 * - Transaction timeout handling
 * - Nested transaction detection
 * - Performance monitoring
 * - Safe transaction helpers
 */
trait TransactionalRepository
{
    protected bool $forceTransaction = false;
    protected bool $skipTransaction = false;
    protected ?string $transactionIsolationLevel = null;

    /**
     * Force the next operation to use a transaction
     * Useful for ensuring data integrity on critical operations
     *
     * @param bool $force
     * @return $this
     *
     * @example
     * $repository->withTransaction()->create($criticalData);
     */
    public function withTransaction($force = true)
    {
        $this->forceTransaction = $force;
        return $this;
    }

    /**
     * Skip transaction for the next operation
     * Useful when you're already in a transaction or managing it manually
     *
     * @param bool $skip
     * @return $this
     *
     * @example
     * DB::transaction(function() use ($repository, $data) {
     *     $repository->skipTransaction()->create($data);
     * });
     */
    public function skipTransaction($skip = true)
    {
        $this->skipTransaction = $skip;
        return $this;
    }

    /**
     * Set transaction isolation level for the next operation
     *
     * @param string $level READ_UNCOMMITTED, READ_COMMITTED, REPEATABLE_READ, SERIALIZABLE
     * @return $this
     *
     * @example
     * $repository->withIsolationLevel('SERIALIZABLE')->create($data);
     */
    public function withIsolationLevel(string $level)
    {
        $this->transactionIsolationLevel = $level;
        return $this;
    }

    /**
     * Execute a callback within a database transaction with retry logic
     *
     * @param callable $callback
     * @param int|null $attempts
     * @return mixed
     *
     * @example
     * $result = $repository->transaction(function() use ($data) {
     *     $user = $this->create($data['user']);
     *     $profile = $this->profileRepo->create($data['profile'] + ['user_id' => $user->id]);
     *     return ['user' => $user, 'profile' => $profile];
     * });
     */
    public function transaction(callable $callback, ?int $attempts = null)
    {
        $attempts = $attempts ?? config('repository.transactions.max_retries', 3);
        $retryDelay = config('repository.transactions.retry_delay', 100);

        return $this->executeWithRetry(function() use ($callback) {
            return $this->executeInTransaction($callback);
        }, $attempts, $retryDelay);
    }

    /**
     * Execute callback in transaction with proper setup
     */
    protected function executeInTransaction(callable $callback)
    {
        // Set isolation level before starting transaction if specified
        if ($this->transactionIsolationLevel) {
            $this->setTransactionIsolationLevel($this->transactionIsolationLevel);
        }

        return DB::transaction(function() use ($callback) {
            // Execute the callback
            $result = $callback();

            // Reset flags
            $this->forceTransaction = false;
            $this->skipTransaction = false;
            $this->transactionIsolationLevel = null;

            return $result;
        });
    }

    /**
     * Execute with deadlock retry logic
     */
    protected function executeWithRetry(callable $callback, int $attempts, int $retryDelay)
    {
        $lastException = null;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $callback();
            } catch (Throwable $e) {
                $lastException = $e;

                // Check if it's a deadlock or lock timeout
                if ($this->isRetryableException($e) && $i < $attempts - 1) {
                    Log::warning('Database deadlock detected, retrying', [
                        'repository' => get_class($this),
                        'attempt' => $i + 1,
                        'max_attempts' => $attempts,
                        'error' => $e->getMessage()
                    ]);

                    // Wait before retry with exponential backoff
                    usleep($retryDelay * 1000 * (2 ** $i));
                    continue;
                }

                // Not retryable or max attempts reached
                throw $e;
            }
        }

        throw $lastException;
    }

    /**
     * Check if exception is retryable (deadlock, lock timeout)
     */
    protected function isRetryableException(Throwable $e): bool
    {
        if (!config('repository.transactions.retry_deadlocks', true)) {
            return false;
        }

        $message = strtolower($e->getMessage());

        // Common deadlock/lock timeout patterns
        $retryablePatterns = [
            'deadlock found',
            'lock wait timeout',
            'deadlock detected',
            'serialization failure',
            'could not serialize'
        ];

        foreach ($retryablePatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set transaction isolation level (must be called before transaction starts)
     */
    protected function setTransactionIsolationLevel(string $level): void
    {
        $validLevels = ['READ UNCOMMITTED', 'READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'];

        if (!in_array($level, $validLevels, true)) {
            throw new \InvalidArgumentException("Invalid isolation level: {$level}");
        }

        // Note: This must be called outside of a transaction
        if ($this->inTransaction()) {
            Log::warning('Cannot set isolation level inside active transaction');
            return;
        }

        DB::statement("SET SESSION TRANSACTION ISOLATION LEVEL {$level}");
    }

    /**
     * Check if we should wrap operation in transaction
     */
    protected function shouldUseTransaction(string $operation): bool
    {
        // Skip if explicitly disabled
        if ($this->skipTransaction) {
            return false;
        }

        // Force if explicitly enabled
        if ($this->forceTransaction) {
            return true;
        }

        // Already in transaction
        if ($this->inTransaction()) {
            return false;
        }

        // Check configuration for operation type
        if ($operation === 'bulk') {
            return config('repository.transactions.auto_wrap_bulk', true);
        }

        return config('repository.transactions.auto_wrap_single', false);
    }

    /**
     * Check if currently in a database transaction
     *
     * @return bool
     */
    public function inTransaction(): bool
    {
        return DB::transactionLevel() > 0;
    }

    /**
     * Get current transaction level
     *
     * @return int
     */
    public function getTransactionLevel(): int
    {
        return DB::transactionLevel();
    }

    /**
     * Safe create operation with automatic transaction handling
     *
     * @param array $attributes
     * @return mixed
     *
     * @example
     * $user = $repository->safeCreate($userData); // Automatically wrapped in transaction if needed
     */
    public function safeCreate(array $attributes)
    {
        if ($this->shouldUseTransaction('single')) {
            return $this->transaction(fn() => $this->create($attributes));
        }

        return $this->create($attributes);
    }

    /**
     * Safe update operation with automatic transaction handling
     *
     * @param array $attributes
     * @param mixed $id
     * @return mixed
     */
    public function safeUpdate(array $attributes, $id)
    {
        if ($this->shouldUseTransaction('single')) {
            return $this->transaction(fn() => $this->update($attributes, $id));
        }

        return $this->update($attributes, $id);
    }

    /**
     * Safe delete operation with automatic transaction handling
     *
     * @param mixed $id
     * @return mixed
     */
    public function safeDelete($id)
    {
        if ($this->shouldUseTransaction('single')) {
            return $this->transaction(fn() => $this->delete($id));
        }

        return $this->delete($id);
    }

    /**
     * Bulk create with transaction safety
     *
     * @param array $records
     * @return array
     *
     * @example
     * $users = $repository->bulkCreateSafely([
     *     ['name' => 'John', 'email' => 'john@test.com'],
     *     ['name' => 'Jane', 'email' => 'jane@test.com']
     * ]);
     */
    public function bulkCreateSafely(array $records): array
    {
        return $this->transaction(function() use ($records) {
            $results = [];

            foreach ($records as $record) {
                $results[] = $this->create($record);
            }

            return $results;
        });
    }

    /**
     * Bulk update with transaction safety using model's update method
     *
     * @param array $updates Array of ['id' => id, 'data' => attributes] or similar
     * @return int Number of updated records
     */
    public function bulkUpdateSafely(array $updates): int
    {
        return $this->transaction(function() use ($updates) {
            $count = 0;

            foreach ($updates as $update) {
                if (isset($update['id']) && isset($update['data'])) {
                    $this->update($update['data'], $update['id']);
                    $count++;
                }
            }

            return $count;
        });
    }

    /**
     * Execute multiple operations in a single transaction
     *
     * @param array $operations Array of callables
     * @return array Results of all operations
     *
     * @example
     * $results = $repository->batchOperations([
     *     fn() => $this->create($userData),
     *     fn() => $this->profileRepo->create($profileData),
     *     fn() => $this->settingsRepo->create($settingsData)
     * ]);
     */
    public function batchOperations(array $operations): array
    {
        return $this->transaction(function() use ($operations) {
            $results = [];

            foreach ($operations as $operation) {
                if (!is_callable($operation)) {
                    throw new \InvalidArgumentException('All operations must be callable');
                }

                $results[] = $operation();
            }

            return $results;
        });
    }

    /**
     * Create or update with transaction safety
     *
     * @param array $attributes
     * @param array $values
     * @return mixed
     */
    public function createOrUpdateSafely(array $attributes, array $values = [])
    {
        return $this->transaction(function() use ($attributes, $values) {
            return $this->updateOrCreate($attributes, $values);
        });
    }

    /**
     * Conditional transaction - only use transaction if condition is met
     *
     * @param bool $condition
     * @param callable $callback
     * @return mixed
     *
     * @example
     * $result = $repository->conditionalTransaction(
     *     $isImportantOperation,
     *     fn() => $this->complexOperation($data)
     * );
     */
    public function conditionalTransaction(bool $condition, callable $callback)
    {
        if ($condition && !$this->inTransaction()) {
            return $this->transaction($callback);
        }

        return $callback();
    }

    /**
     * Get transaction performance stats for monitoring
     *
     * @return array
     */
    public function getTransactionStats(): array
    {
        return [
            'in_transaction' => $this->inTransaction(),
            'transaction_level' => $this->getTransactionLevel(),
            'force_transaction' => $this->forceTransaction,
            'skip_transaction' => $this->skipTransaction,
            'isolation_level' => $this->transactionIsolationLevel,
            'auto_wrap_bulk' => config('repository.transactions.auto_wrap_bulk', true),
            'auto_wrap_single' => config('repository.transactions.auto_wrap_single', false),
        ];
    }
}
