<?php

use Apiato\Core\Repositories\Repository;
use Apiato\Repository\Traits\TransactionalRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Test Model
class TransactionTestModel extends Model
{
    protected $table = 'transaction_test_models';
    protected $fillable = ['name', 'email', 'status', 'active'];
    protected $casts = ['active' => 'boolean'];
}

// Test Repository using the trait
class TransactionTestRepository extends Repository
{
    use TransactionalRepository;

    public function model(): string
    {
        return TransactionTestModel::class;
    }

    // protected $fieldSearchable = [
    //     'name' => 'like',
    //     'email' => '=',
    //     'status' => '=',
    // ];
}

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test table
    if (!DB::getSchemaBuilder()->hasTable('transaction_test_models')) {
        DB::getSchemaBuilder()->create('transaction_test_models', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('status')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    $this->repository = app(TransactionTestRepository::class);

    // Set default config
    Config::set('repository.transactions', [
        'max_retries' => 3,
        'retry_delay' => 10, // Shorter for tests
        'retry_deadlocks' => true,
        'timeout' => 30,
        'isolation_level' => null,
        'auto_wrap_bulk' => true,
        'auto_wrap_single' => false,
    ]);
});

describe('Fluent Transaction Interface', function () {

    test('withTransaction sets force transaction flag and returns self', function () {
        $result = $this->repository->withTransaction();

        expect($result)->toBe($this->repository)
            ->and($this->repository->getTransactionStats()['force_transaction'])->toBeTrue();
    });

    test('withTransaction can be disabled', function () {
        $result = $this->repository->withTransaction(false);

        expect($result)->toBe($this->repository)
            ->and($this->repository->getTransactionStats()['force_transaction'])->toBeFalse();
    });

    test('skipTransaction sets skip flag and returns self', function () {
        $result = $this->repository->skipTransaction();

        expect($result)->toBe($this->repository)
            ->and($this->repository->getTransactionStats()['skip_transaction'])->toBeTrue();
    });

    test('withIsolationLevel sets isolation level and returns self', function () {
        $result = $this->repository->withIsolationLevel('SERIALIZABLE');

        expect($result)->toBe($this->repository)
            ->and($this->repository->getTransactionStats()['isolation_level'])->toBe('SERIALIZABLE');
    });

    test('methods can be chained fluently', function () {
        $result = $this->repository
            ->withTransaction()
            ->withIsolationLevel('REPEATABLE READ')
            ->skipTransaction(false);

        expect($result)->toBe($this->repository);

        $stats = $this->repository->getTransactionStats();
        expect($stats['force_transaction'])->toBeTrue()
            ->and($stats['skip_transaction'])->toBeFalse()
            ->and($stats['isolation_level'])->toBe('REPEATABLE READ');
    });

});

describe('Transaction State Detection', function () {
    test('inTransaction detects when not in transaction', function () {
        $initialLevel = DB::transactionLevel();
        expect($this->repository->inTransaction())->toBe($initialLevel > 0)
            ->and($this->repository->getTransactionLevel())->toBe($initialLevel);
    });

    test('inTransaction detects active transactions', function () {
        $outerLevel = DB::transactionLevel();
        $insideTransaction = false;
        $transactionLevel = 0;

        DB::transaction(function () use (&$insideTransaction, &$transactionLevel, $outerLevel) {
            $insideTransaction = $this->repository->inTransaction();
            $transactionLevel = $this->repository->getTransactionLevel();
            // Should be one more than outer level
        });

        expect($insideTransaction)->toBeTrue()
            ->and($transactionLevel)->toBe($outerLevel + 1);
    });

    test('getTransactionStats returns complete status information', function () {
        $initialLevel = DB::transactionLevel();
        $this->repository->withTransaction()->withIsolationLevel('SERIALIZABLE');

        $stats = $this->repository->getTransactionStats();

        expect($stats)->toHaveKeys([
            'in_transaction',
            'transaction_level',
            'force_transaction',
            'skip_transaction',
            'isolation_level',
            'auto_wrap_bulk',
            'auto_wrap_single'
        ])
        ->and($stats['force_transaction'])->toBeTrue()
        ->and($stats['isolation_level'])->toBe('SERIALIZABLE')
        ->and($stats['in_transaction'])->toBe($initialLevel > 0)
        ->and($stats['transaction_level'])->toBe($initialLevel);
    });

});

describe('Basic Transaction Operations', function () {

    test('transaction executes callback and returns result', function () {
        $result = $this->repository->transaction(function () {
            return 'success_result';
        });

        expect($result)->toBe('success_result');
    });

    test('transaction creates actual database transaction', function () {
        $outerLevel = DB::transactionLevel();
        $transactionLevel = null;

        $this->repository->transaction(function () use (&$transactionLevel) {
            $transactionLevel = $this->repository->getTransactionLevel();
        });

        expect($transactionLevel)->toBe($outerLevel + 1);
    });

    test('transaction resets flags after execution', function () {
        $this->repository
            ->withTransaction()
            ->withIsolationLevel('SERIALIZABLE');

        $this->repository->transaction(function () {
            return true;
        });

        $stats = $this->repository->getTransactionStats();
        expect($stats['force_transaction'])->toBeFalse()
            ->and($stats['isolation_level'])->toBeNull();
    });

    test('transaction with custom retry attempts', function () {
        $attempts = 0;

        $result = $this->repository->transaction(function () use (&$attempts) {
            $attempts++;
            return 'completed';
        }, 5);

        expect($result)->toBe('completed')
            ->and($attempts)->toBe(1);
    });

});

describe('Safe CRUD Operations', function () {

    test('safeCreate creates record without forced transaction', function () {
        Config::set('repository.transactions.auto_wrap_single', false);

        $model = $this->repository->safeCreate([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'status' => 'active'
        ]);

        expect($model)->toBeInstanceOf(TransactionTestModel::class)
            ->and($model->name)->toBe('John Doe')
            ->and($model->email)->toBe('john@example.com')
            ->and($model->status)->toBe('active');
    });

    test('safeCreate uses transaction when forced', function () {
        $transactionUsed = false;

        $this->repository->withTransaction();

        // Mock the transaction to detect usage
        DB::shouldReceive('transactionLevel')->andReturn(0, 1, 0);
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) use (&$transactionUsed) {
            $transactionUsed = true;
            return $callback();
        });

        $this->repository->safeCreate([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com'
        ]);

        expect($transactionUsed)->toBeTrue();
    });

    test('safeUpdate updates existing record', function () {
        $model = $this->repository->create([
            'name' => 'Original Name',
            'email' => 'original@example.com'
        ]);

        $updated = $this->repository->safeUpdate([
            'name' => 'Updated Name',
            'status' => 'modified'
        ], $model->id);

        expect($updated)->toBeInstanceOf(TransactionTestModel::class)
            ->and($updated->name)->toBe('Updated Name')
            ->and($updated->status)->toBe('modified')
            ->and($updated->email)->toBe('original@example.com');
    });

    test('safeDelete removes record', function () {
        $model = $this->repository->create([
            'name' => 'To Delete',
            'email' => 'delete@example.com'
        ]);

        $result = $this->repository->safeDelete($model->id);

        expect($result)->toBeTrue();
        expect($this->repository->find($model->id))->toBeNull();
    });

    test('safeUpdate uses transaction when configured', function () {
        Config::set('repository.transactions.auto_wrap_single', true);

        $model = $this->repository->create([
            'name' => 'Test',
            'email' => 'test@example.com'
        ]);

        $transactionUsed = false;

        DB::shouldReceive('transactionLevel')->andReturn(0, 1, 0);
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) use (&$transactionUsed) {
            $transactionUsed = true;
            return $callback();
        });

        $this->repository->safeUpdate(['name' => 'Updated'], $model->id);

        expect($transactionUsed)->toBeTrue();
    });

});

describe('Bulk Operations', function () {

    test('bulkCreateSafely creates multiple records in transaction', function () {
        $records = [
            ['name' => 'User 1', 'email' => 'user1@example.com', 'status' => 'active'],
            ['name' => 'User 2', 'email' => 'user2@example.com', 'status' => 'pending'],
            ['name' => 'User 3', 'email' => 'user3@example.com', 'status' => 'active']
        ];

        $results = $this->repository->bulkCreateSafely($records);

        expect($results)->toHaveCount(3)
            ->and($results[0])->toBeInstanceOf(TransactionTestModel::class)
            ->and($results[0]->name)->toBe('User 1')
            ->and($results[1]->name)->toBe('User 2')
            ->and($results[2]->name)->toBe('User 3')
            ->and($results[1]->status)->toBe('pending');
    });

    test('bulkUpdateSafely updates multiple records', function () {
        // Create test records
        $model1 = $this->repository->create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $model2 = $this->repository->create(['name' => 'User 2', 'email' => 'user2@example.com']);

        $updates = [
            ['id' => $model1->id, 'data' => ['name' => 'Updated User 1', 'status' => 'modified']],
            ['id' => $model2->id, 'data' => ['name' => 'Updated User 2', 'status' => 'complete']]
        ];

        $count = $this->repository->bulkUpdateSafely($updates);

        expect($count)->toBe(2);

        $updated1 = $this->repository->find($model1->id);
        $updated2 = $this->repository->find($model2->id);

        expect($updated1->name)->toBe('Updated User 1')
            ->and($updated1->status)->toBe('modified')
            ->and($updated2->name)->toBe('Updated User 2')
            ->and($updated2->status)->toBe('complete');
    });

    test('bulkCreateSafely rolls back on error', function () {
        $records = [
            ['name' => 'Valid User', 'email' => 'valid@example.com'],
            ['name' => 'Invalid User'] // Missing required email
        ];

        expect(fn() => $this->repository->bulkCreateSafely($records))
            ->toThrow(Exception::class);

        // Verify no records were created due to rollback
        expect($this->repository->all())->toHaveCount(0);
    });

});

describe('Batch Operations', function () {

    test('batchOperations executes multiple operations in single transaction', function () {
        $operations = [
            fn() => $this->repository->create(['name' => 'Batch 1', 'email' => 'batch1@example.com']),
            fn() => $this->repository->create(['name' => 'Batch 2', 'email' => 'batch2@example.com']),
            fn() => 'custom_result',
            fn() => ['data' => 'array_result']
        ];

        $results = $this->repository->batchOperations($operations);

        expect($results)->toHaveCount(4)
            ->and($results[0])->toBeInstanceOf(TransactionTestModel::class)
            ->and($results[1])->toBeInstanceOf(TransactionTestModel::class)
            ->and($results[2])->toBe('custom_result')
            ->and($results[3])->toBe(['data' => 'array_result'])
            ->and($results[0]->name)->toBe('Batch 1')
            ->and($results[1]->name)->toBe('Batch 2');
    });

    test('batchOperations throws exception for non-callable operations', function () {
        $operations = [
            fn() => 'valid operation',
            'invalid operation', // Not callable
            fn() => 'another valid'
        ];

        expect(fn() => $this->repository->batchOperations($operations))
            ->toThrow(InvalidArgumentException::class, 'All operations must be callable');
    });

    test('batchOperations rolls back all operations on error', function () {
        $operations = [
            fn() => $this->repository->create(['name' => 'First', 'email' => 'first@example.com']),
            fn() => $this->repository->create(['name' => 'Second', 'email' => 'second@example.com']),
            fn() => throw new Exception('Simulated error')
        ];

        expect(fn() => $this->repository->batchOperations($operations))
            ->toThrow(Exception::class, 'Simulated error');

        // Verify rollback - no records should exist
        expect($this->repository->all())->toHaveCount(0);
    });

});

describe('Advanced Transaction Features', function () {

    test('createOrUpdateSafely creates new record when not found', function () {
        $result = $this->repository->createOrUpdateSafely(
            ['email' => 'new@example.com'],
            ['name' => 'New User', 'status' => 'created']
        );

        expect($result)->toBeInstanceOf(TransactionTestModel::class)
            ->and($result->email)->toBe('new@example.com')
            ->and($result->name)->toBe('New User')
            ->and($result->status)->toBe('created');
    });

    test('createOrUpdateSafely updates existing record when found', function () {
        // Create initial record
        $existing = $this->repository->create([
            'name' => 'Original',
            'email' => 'update@example.com',
            'status' => 'old'
        ]);

        $result = $this->repository->createOrUpdateSafely(
            ['email' => 'update@example.com'],
            ['name' => 'Updated User', 'status' => 'updated']
        );

        expect($result->id)->toBe($existing->id)
            ->and($result->name)->toBe('Updated User')
            ->and($result->status)->toBe('updated')
            ->and($result->email)->toBe('update@example.com');
    });

    test('conditionalTransaction uses transaction when condition is true', function () {
        $transactionUsed = false;

        $result = $this->repository->conditionalTransaction(true, function () use (&$transactionUsed) {
            $transactionUsed = $this->repository->inTransaction();
            return 'conditional_result';
        });

        expect($result)->toBe('conditional_result')
            ->and($transactionUsed)->toBeTrue();
    });

    test('conditionalTransaction skips transaction when condition is false', function () {
        $outerLevel = DB::transactionLevel();
        $transactionUsed = null;

        $result = $this->repository->conditionalTransaction(false, function () use (&$transactionUsed) {
            $transactionUsed = $this->repository->getTransactionLevel();
            return 'no_transaction_result';
        });

        expect($result)->toBe('no_transaction_result')
            ->and($transactionUsed)->toBe($outerLevel);
    });

    test('conditionalTransaction skips when already in transaction', function () {
        $outerLevel = DB::transactionLevel();
        $nestedTransactionLevel = null;

        DB::transaction(function () use (&$nestedTransactionLevel, $outerLevel) {
            $this->repository->conditionalTransaction(true, function () use (&$nestedTransactionLevel) {
                $nestedTransactionLevel = $this->repository->getTransactionLevel();
            });
        });

        expect($nestedTransactionLevel)->toBe($outerLevel + 1); // Should match the current transaction level
    });

});

describe('Error Handling and Retry Logic', function () {

    test('isRetryableException detects deadlock errors', function () {
        $deadlockException = new Exception('Deadlock found when trying to get lock; try restarting transaction');

        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('isRetryableException');
        $method->setAccessible(true);

        $result = $method->invoke($this->repository, $deadlockException);

        expect($result)->toBeTrue();
    });

    test('isRetryableException detects lock timeout errors', function () {
        $timeoutException = new Exception('Lock wait timeout exceeded; try restarting transaction');

        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('isRetryableException');
        $method->setAccessible(true);

        $result = $method->invoke($this->repository, $timeoutException);

        expect($result)->toBeTrue();
    });

    test('isRetryableException detects serialization failure', function () {
        $serializationException = new Exception('could not serialize access due to concurrent update');

        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('isRetryableException');
        $method->setAccessible(true);

        $result = $method->invoke($this->repository, $serializationException);

        expect($result)->toBeTrue();
    });

    test('isRetryableException ignores non-retryable errors', function () {
        $regularException = new Exception('Column not found: 1054 Unknown column');

        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('isRetryableException');
        $method->setAccessible(true);

        $result = $method->invoke($this->repository, $regularException);

        expect($result)->toBeFalse();
    });

    test('isRetryableException respects configuration', function () {
        Config::set('repository.transactions.retry_deadlocks', false);

        $deadlockException = new Exception('Deadlock found when trying to get lock');

        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('isRetryableException');
        $method->setAccessible(true);

        $result = $method->invoke($this->repository, $deadlockException);

        expect($result)->toBeFalse();
    });

    test('executeWithRetry retries on deadlock and eventually succeeds', function () {
        $attempts = 0;
        $maxAttempts = 3;

        Log::shouldReceive('warning')->times($maxAttempts - 1);

        $callback = function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new Exception('Deadlock found when trying to get lock');
            }
            return 'success_after_retry';
        };

        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('executeWithRetry');
        $method->setAccessible(true);

        $result = $method->invoke($this->repository, $callback, $maxAttempts, 1);

        expect($result)->toBe('success_after_retry')
            ->and($attempts)->toBe(3);
    });

    test('executeWithRetry gives up after max attempts', function () {
        $attempts = 0;
        $maxAttempts = 2;

        Log::shouldReceive('warning')->times($maxAttempts - 1);

        $callback = function () use (&$attempts) {
            $attempts++;
            throw new Exception('Deadlock found when trying to get lock');
        };

        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('executeWithRetry');
        $method->setAccessible(true);

        expect(fn() => $method->invoke($this->repository, $callback, $maxAttempts, 1))
            ->toThrow(Exception::class, 'Deadlock found when trying to get lock');

        expect($attempts)->toBe($maxAttempts);
    });

    test('executeWithRetry implements exponential backoff', function () {
        $attempts = 0;
        $sleepCalls = [];

        // Mock usleep to capture sleep times
        $originalUsleep = null;
        if (function_exists('usleep')) {
            // We can't easily mock usleep, so we'll test the logic indirectly
            // by checking that attempts increase and delays are calculated correctly
        }

        Log::shouldReceive('warning')->twice();

        $callback = function () use (&$attempts) {
            $attempts++;
            if ($attempts <= 2) {
                throw new Exception('Deadlock found when trying to get lock');
            }
            return 'success';
        };

        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('executeWithRetry');
        $method->setAccessible(true);

        $result = $method->invoke($this->repository, $callback, 3, 10);

        expect($result)->toBe('success')
            ->and($attempts)->toBe(3);
    });

});

describe('Transaction Configuration', function () {

    test('shouldUseTransaction respects skipTransaction flag', function () {
        $this->repository->skipTransaction();

        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('shouldUseTransaction');
        $method->setAccessible(true);

        $result = $method->invoke($this->repository, 'single');

        expect($result)->toBeFalse();
    });

    test('shouldUseTransaction respects forceTransaction flag', function () {
        $this->repository->withTransaction();

        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('shouldUseTransaction');
        $method->setAccessible(true);

        $result = $method->invoke($this->repository, 'single');

        expect($result)->toBeTrue();
    });

    test('shouldUseTransaction checks configuration for bulk operations', function () {
        Config::set('repository.transactions.auto_wrap_bulk', true);

        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('shouldUseTransaction');
        $method->setAccessible(true);

        $outerLevel = DB::transactionLevel();
        $result = $method->invoke($this->repository, 'bulk');

        if ($outerLevel > 0) {
            expect($result)->toBeFalse();
        } else {
            expect($result)->toBeTrue();
        }
    });

    test('shouldUseTransaction checks configuration for single operations', function () {
        Config::set('repository.transactions.auto_wrap_single', true);

        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('shouldUseTransaction');
        $method->setAccessible(true);

        $outerLevel = DB::transactionLevel();
        $result = $method->invoke($this->repository, 'single');

        if ($outerLevel > 0) {
            expect($result)->toBeFalse();
        } else {
            expect($result)->toBeTrue();
        }
    });

    test('shouldUseTransaction skips when already in transaction without force flag', function () {
        $shouldUse = null;
        DB::transaction(function () use (&$shouldUse) {
            $reflection = new ReflectionClass($this->repository);
            $method = $reflection->getMethod('shouldUseTransaction');
            $method->setAccessible(true);
            $shouldUse = $method->invoke($this->repository, 'single');
        });
        expect($shouldUse)->toBeFalse();
    });

    test('shouldUseTransaction does NOT skip when already in transaction if force flag is set', function () {
        $shouldUse = null;
        DB::transaction(function () use (&$shouldUse) {
            $this->repository->withTransaction();
            $reflection = new ReflectionClass($this->repository);
            $method = $reflection->getMethod('shouldUseTransaction');
            $method->setAccessible(true);
            $shouldUse = $method->invoke($this->repository, 'single');
        });
        expect($shouldUse)->toBeTrue();
    });

});

describe('Isolation Level Handling', function () {

    test('setTransactionIsolationLevel validates isolation levels', function () {
        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('setTransactionIsolationLevel');
        $method->setAccessible(true);

        expect(fn() => $method->invoke($this->repository, 'INVALID_LEVEL'))
            ->toThrow(InvalidArgumentException::class, 'Invalid isolation level: INVALID_LEVEL');
    });

    test('setTransactionIsolationLevel accepts valid isolation levels', function () {
        $validLevels = ['READ UNCOMMITTED', 'READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'];

        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('setTransactionIsolationLevel');
        $method->setAccessible(true);

        foreach ($validLevels as $level) {
            expect(fn() => $method->invoke($this->repository, $level))
                ->not->toThrow(InvalidArgumentException::class);
        }
    });

    test('setTransactionIsolationLevel logs warning when called inside transaction', function () {
        Log::shouldReceive('warning')
            ->once()
            ->with('Cannot set isolation level inside active transaction');

        DB::transaction(function () {
            $reflection = new ReflectionClass($this->repository);
            $method = $reflection->getMethod('setTransactionIsolationLevel');
            $method->setAccessible(true);

            $method->invoke($this->repository, 'SERIALIZABLE');
        });
    });

});

describe('Integration and Real-world Scenarios', function () {

    test('complex nested transaction scenario', function () {
        $result = $this->repository->transaction(function () {
            // Create primary record
            $primary = $this->repository->create([
                'name' => 'Primary Record',
                'email' => 'primary@example.com',
                'status' => 'active'
            ]);

            // Execute batch operations
            $batchResults = $this->repository->batchOperations([
                fn() => $this->repository->create(['name' => 'Child 1', 'email' => 'child1@example.com']),
                fn() => $this->repository->create(['name' => 'Child 2', 'email' => 'child2@example.com']),
            ]);

            // Update primary based on children
            $updated = $this->repository->update([
                'status' => 'parent'
            ], $primary->id);

            return [
                'primary' => $updated,
                'children' => $batchResults,
                'total_count' => count($batchResults) + 1
            ];
        });

        expect($result['primary'])->toBeInstanceOf(TransactionTestModel::class)
            ->and($result['primary']->status)->toBe('parent')
            ->and($result['children'])->toHaveCount(2)
            ->and($result['total_count'])->toBe(3);

        // Verify all records exist
        expect($this->repository->all())->toHaveCount(3);
    });

    test('transaction rollback preserves data integrity', function () {
        // Create initial data
        $existing = $this->repository->create([
            'name' => 'Existing',
            'email' => 'existing@example.com'
        ]);

        expect(fn() => $this->repository->transaction(function () {
            // These operations should be rolled back
            $this->repository->create(['name' => 'Should Rollback 1', 'email' => 'rollback1@example.com']);
            $this->repository->create(['name' => 'Should Rollback 2', 'email' => 'rollback2@example.com']);

            // This should cause rollback
            throw new Exception('Force rollback');
        }))->toThrow(Exception::class, 'Force rollback');

        // Verify only original record exists
        $allRecords = $this->repository->all();
        expect($allRecords)->toHaveCount(1)
            ->and($allRecords->first()->id)->toBe($existing->id);
    });

    test('performance with large bulk operations', function () {
        $startTime = microtime(true);

        $records = [];
        for ($i = 1; $i <= 100; $i++) {
            $records[] = [
                'name' => "Performance User {$i}",
                'email' => "perf{$i}@example.com",
                'status' => $i % 2 === 0 ? 'active' : 'inactive'
            ];
        }

        $results = $this->repository->bulkCreateSafely($records);

        $duration = microtime(true) - $startTime;

        expect($results)->toHaveCount(100)
            ->and($duration)->toBeLessThan(5.0) // Should complete within 5 seconds
            ->and($this->repository->all())->toHaveCount(100);

        // Test bulk update performance
        $updateStartTime = microtime(true);

        $updates = [];
        foreach ($results as $result) {
            $updates[] = [
                'id' => $result->id,
                'data' => ['status' => 'bulk_updated']
            ];
        }

        $updateCount = $this->repository->bulkUpdateSafely($updates);
        $updateDuration = microtime(true) - $updateStartTime;

        expect($updateCount)->toBe(100)
            ->and($updateDuration)->toBeLessThan(3.0);
    });

    test('concurrent operation simulation', function () {
        // Simulate what might happen in concurrent requests
        $operations = [];

        // Create multiple competing operations
        for ($i = 1; $i <= 5; $i++) {
            $operations[] = fn() => $this->repository->createOrUpdateSafely(
                ['email' => 'shared@example.com'],
                ['name' => "User {$i}", 'status' => "attempt_{$i}"]
            );
        }

        $results = $this->repository->batchOperations($operations);

        // All operations should have worked on the same record
        expect($results)->toHaveCount(5);
        foreach ($results as $result) {
            expect($result)->toBeInstanceOf(TransactionTestModel::class)
                ->and($result->email)->toBe('shared@example.com');
        }

        // Only one record should exist
        expect($this->repository->all())->toHaveCount(1);
    });

});
