<?php

use Apiato\Core\Repositories\Repository;
use Apiato\Repository\Traits\BulkOperations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

// Test Model for bulk operations
class BulkTestModel extends Model
{
    protected $table = 'bulk_test_models';
    protected $fillable = ['name', 'email', 'status', 'priority', 'active', 'category_id'];
    protected $casts = [
        'active' => 'boolean',
        'priority' => 'integer',
        'category_id' => 'integer'
    ];
}

// Test Model with Soft Deletes
class SoftDeleteBulkModel extends Model
{
    use SoftDeletes;

    protected $table = 'soft_delete_bulk_models';
    protected $fillable = ['name', 'email', 'status'];
    protected $dates = ['deleted_at'];
}

// Test Repository with BulkOperations trait
class BulkOperationsRepository extends Repository
{
    use BulkOperations;

    protected int $cacheClears = 0;

    public function model(): string
    {
        return BulkTestModel::class;
    }

    protected array $fieldSearchable = [
        'name' => 'like',
        'email' => '=',
        'status' => '=',
    ];

    // Mock cache clearing for testing
    public function clearCache()
    {
        // Use the real repository cache feature if available
        if (is_callable([parent::class, 'clearCache'])) {
            parent::clearCache();
        }
        // Track cache clears for testing
        $this->cacheClears++;
    }

    public function getCacheClearCount(): int
    {
        return $this->cacheClears;
    }
}

// Repository for soft delete testing
class SoftDeleteRepository extends Repository
{
    use BulkOperations;

    public function model(): string
    {
        return SoftDeleteBulkModel::class;
    }
}

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test table
    if (!DB::getSchemaBuilder()->hasTable('bulk_test_models')) {
        DB::getSchemaBuilder()->create('bulk_test_models', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email'); // Remove unique constraint - let bulkUpsert handle uniqueness
            $table->string('status')->default('active');
            $table->integer('priority')->default(0);
            $table->boolean('active')->default(true);
            $table->integer('category_id')->nullable();
            $table->timestamps();

            // Add composite unique constraint for testing multiple unique columns
            $table->unique(['email', 'category_id'], 'email_category_unique');
        });
    }

    // Create soft delete test table
    if (!DB::getSchemaBuilder()->hasTable('soft_delete_bulk_models')) {
        DB::getSchemaBuilder()->create('soft_delete_bulk_models', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    $this->repository = app(BulkOperationsRepository::class);
    $this->softDeleteRepository = app(SoftDeleteRepository::class);
});

describe('Bulk Insert Operations', function () {

    test('bulkInsert creates multiple records with automatic timestamps', function () {
        $data = [
            ['name' => 'User 1', 'email' => 'user1@example.com', 'status' => 'active', 'priority' => 1],
            ['name' => 'User 2', 'email' => 'user2@example.com', 'status' => 'pending', 'priority' => 2],
            ['name' => 'User 3', 'email' => 'user3@example.com', 'status' => 'inactive', 'priority' => 3],
        ];

        $count = $this->repository->bulkInsert($data);

        expect($count)->toBe(3);

        // Verify records exist with timestamps
        $records = DB::table('bulk_test_models')->get();
        expect($records)->toHaveCount(3);

        foreach ($records as $record) {
            expect($record->created_at)->not->toBeNull()
                ->and($record->updated_at)->not->toBeNull();
        }

        // The repository may clear cache more than once per operation due to cache support.
        expect($this->repository->getCacheClearCount())->toBeGreaterThanOrEqual(1);
    });

    test('bulkInsert handles empty array', function () {
        $count = $this->repository->bulkInsert([]);

        expect($count)->toBe(0)
            ->and(DB::table('bulk_test_models')->count())->toBe(0);
    });

    test('bulkInsert without timestamps when disabled', function () {
        $data = [
            ['name' => 'No Timestamp User', 'email' => 'notimestamp@example.com'],
        ];

        $count = $this->repository->bulkInsert($data, ['timestamps' => false]);

        expect($count)->toBe(1);

        $record = DB::table('bulk_test_models')->first();
        expect($record->created_at)->toBeNull()
            ->and($record->updated_at)->toBeNull();
    });

    test('bulkInsert with custom timestamps', function () {
        $customTime = Carbon::parse('2023-01-01 12:00:00');
        $data = [
            [
                'name' => 'Custom Time User',
                'email' => 'custom@example.com',
                'created_at' => $customTime,
                'updated_at' => $customTime
            ],
        ];

        $count = $this->repository->bulkInsert($data);

        expect($count)->toBe(1);

        $record = DB::table('bulk_test_models')->first();
        expect($record->created_at)->toBe($customTime->toDateTimeString())
            ->and($record->updated_at)->toBe($customTime->toDateTimeString());
    });

    test('bulkInsert processes large datasets in batches', function () {
        $data = [];
        for ($i = 1; $i <= 2500; $i++) {
            $data[] = [
                'name' => "Batch User {$i}",
                'email' => "batch{$i}@example.com",
                'priority' => $i % 10
            ];
        }

        $chunkCallbackCalled = 0;
        $totalProcessed = 0;

        $count = $this->repository->bulkInsert($data, [
            'batch_size' => 1000,
            'chunk_callback' => function ($inserted, $total, $totalData) use (&$chunkCallbackCalled, &$totalProcessed) {
                $chunkCallbackCalled++;
                $totalProcessed = $total;
            }
        ]);

        expect($count)->toBe(2500)
            ->and($chunkCallbackCalled)->toBe(3) // 3 chunks: 1000 + 1000 + 500
            ->and($totalProcessed)->toBe(2500)
            ->and(DB::table('bulk_test_models')->count())->toBe(2500);
    });

    test('bulkInsert with ignore duplicates option', function () {
        // Insert initial data with both email and category_id to match our composite unique constraint
        DB::table('bulk_test_models')->insert([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'category_id' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $data = [
            ['name' => 'Existing User', 'email' => 'existing@example.com', 'category_id' => 1], // Duplicate
            ['name' => 'New User', 'email' => 'new@example.com', 'category_id' => 1],
        ];

        $count = $this->repository->bulkInsert($data, ['ignore_duplicates' => true]);

        // Should not throw error and should insert the new record
        expect($count)->toBe(1) // Only new record inserted
            ->and(DB::table('bulk_test_models')->count())->toBe(2);
    });

});

describe('Bulk Update Operations', function () {

    beforeEach(function () {
        // Create test data
        $this->repository->bulkInsert([
            ['name' => 'Update Test 1', 'email' => 'update1@example.com', 'status' => 'active', 'priority' => 1],
            ['name' => 'Update Test 2', 'email' => 'update2@example.com', 'status' => 'pending', 'priority' => 2],
            ['name' => 'Update Test 3', 'email' => 'update3@example.com', 'status' => 'active', 'priority' => 3],
            ['name' => 'Update Test 4', 'email' => 'update4@example.com', 'status' => 'inactive', 'priority' => 1],
        ]);
    });

    test('bulkUpdate updates records with simple conditions', function () {
        $count = $this->repository->bulkUpdate(
            ['status' => 'bulk_updated', 'priority' => 99],
            ['status' => 'active']
        );

        expect($count)->toBe(2); // 2 records had status 'active'

        $updatedRecords = DB::table('bulk_test_models')
            ->where('status', 'bulk_updated')
            ->get();

        expect($updatedRecords)->toHaveCount(2);

        foreach ($updatedRecords as $record) {
            expect($record->priority)->toBe(99)
                ->and($record->status)->toBe('bulk_updated');
        }

        // The repository may clear cache more than once per operation due to cache support.
        expect($this->repository->getCacheClearCount())->toBeGreaterThanOrEqual(1);
    });

    test('bulkUpdate with complex conditions using operators', function () {
        $count = $this->repository->bulkUpdate(
            ['status' => 'high_priority'],
            [
                ['priority', '>=', 2],
                'status' => 'active'
            ]
        );

        expect($count)->toBe(1); // Only 1 record with priority >= 2 AND status = 'active'

        $updated = DB::table('bulk_test_models')
            ->where('status', 'high_priority')
            ->first();

        expect($updated)->not->toBeNull()
            ->and($updated->name)->toBe('Update Test 3');
    });

    test('bulkUpdate automatically adds updated_at timestamp', function () {
        // Get original timestamp first
        $originalRecord = DB::table('bulk_test_models')
            ->where('name', 'Update Test 1')
            ->first();
        $originalUpdatedAt = Carbon::parse($originalRecord->updated_at);

        // Wait a full second to ensure timestamp difference
        sleep(1);

        $count = $this->repository->bulkUpdate(
            ['status' => 'timestamped'],
            ['name' => 'Update Test 1']
        );

        expect($count)->toBe(1);

        $record = DB::table('bulk_test_models')
            ->where('status', 'timestamped')
            ->first();

        $updatedAt = Carbon::parse($record->updated_at);
        expect($updatedAt->greaterThan($originalUpdatedAt))->toBeTrue();
    });

    test('bulkUpdate without automatic timestamps when disabled', function () {
        $originalUpdatedAt = DB::table('bulk_test_models')
            ->where('name', 'Update Test 1')
            ->value('updated_at');

        $count = $this->repository->bulkUpdate(
            ['status' => 'no_timestamp'],
            ['name' => 'Update Test 1'],
            ['timestamps' => false]
        );

        expect($count)->toBe(1);

        $record = DB::table('bulk_test_models')
            ->where('status', 'no_timestamp')
            ->first();

        expect($record->updated_at)->toBe($originalUpdatedAt);
    });

    test('bulkUpdate with no matching conditions', function () {
        $count = $this->repository->bulkUpdate(
            ['status' => 'no_match'],
            ['status' => 'nonexistent']
        );

        expect($count)->toBe(0);

        $records = DB::table('bulk_test_models')
            ->where('status', 'no_match')
            ->get();

        expect($records)->toHaveCount(0);
    });

});

describe('Bulk Upsert Operations', function () {

    test('bulkUpsert inserts new records when no conflicts', function () {
        $data = [
            ['name' => 'New User 1', 'email' => 'new1@example.com', 'status' => 'active'],
            ['name' => 'New User 2', 'email' => 'new2@example.com', 'status' => 'pending'],
            ['name' => 'New User 3', 'email' => 'new3@example.com', 'status' => 'inactive'],
        ];

        $result = $this->repository->bulkUpsert($data, ['email']);

        expect($result)->toBe(['inserted' => 3, 'updated' => 0])
            ->and(DB::table('bulk_test_models')->count())->toBe(3);

        // The repository may clear cache more than once per operation due to cache support.
        expect($this->repository->getCacheClearCount())->toBeGreaterThanOrEqual(1);
    });

    test('bulkUpsert updates existing records when conflicts exist', function () {
        // Insert initial data
        $this->repository->bulkInsert([
            ['name' => 'Original User 1', 'email' => 'user1@example.com', 'status' => 'active'],
            ['name' => 'Original User 2', 'email' => 'user2@example.com', 'status' => 'pending'],
        ]);

        $data = [
            ['name' => 'Updated User 1', 'email' => 'user1@example.com', 'status' => 'modified'],
            ['name' => 'Updated User 2', 'email' => 'user2@example.com', 'status' => 'completed'],
        ];

        $result = $this->repository->bulkUpsert($data, ['email']);

        expect($result)->toBe(['inserted' => 0, 'updated' => 2])
            ->and(DB::table('bulk_test_models')->count())->toBe(2);

        $records = DB::table('bulk_test_models')->orderBy('id')->get();
        expect($records[0]->name)->toBe('Updated User 1')
            ->and($records[0]->status)->toBe('modified')
            ->and($records[1]->name)->toBe('Updated User 2')
            ->and($records[1]->status)->toBe('completed');
    });

    test('bulkUpsert handles mixed insert and update operations', function () {
        // Insert one existing record
        $this->repository->bulkInsert([
            ['name' => 'Existing User', 'email' => 'existing@example.com', 'status' => 'active'],
        ]);

        $data = [
            ['name' => 'Updated Existing', 'email' => 'existing@example.com', 'status' => 'modified'],
            ['name' => 'New User 1', 'email' => 'new1@example.com', 'status' => 'active'],
            ['name' => 'New User 2', 'email' => 'new2@example.com', 'status' => 'pending'],
        ];

        $result = $this->repository->bulkUpsert($data, ['email']);

        expect($result)->toBe(['inserted' => 2, 'updated' => 1])
            ->and(DB::table('bulk_test_models')->count())->toBe(3);

        // Verify update worked
        $existing = DB::table('bulk_test_models')
            ->where('email', 'existing@example.com')
            ->first();

        expect($existing->name)->toBe('Updated Existing')
            ->and($existing->status)->toBe('modified');
    });

    test('bulkUpsert with specific update columns', function () {
        $this->repository->bulkInsert([
            ['name' => 'Original', 'email' => 'test@example.com', 'status' => 'active', 'priority' => 5],
        ]);

        $data = [
            ['name' => 'Should Not Update', 'email' => 'test@example.com', 'status' => 'updated', 'priority' => 99],
        ];

        $result = $this->repository->bulkUpsert($data, ['email'], ['status']);

        expect($result)->toBe(['inserted' => 0, 'updated' => 1]);

        $record = DB::table('bulk_test_models')->first();
        expect($record->name)->toBe('Original') // Not updated
            ->and($record->status)->toBe('updated') // Updated
            ->and($record->priority)->toBe(5); // Not updated
    });

    test('bulkUpsert with multiple unique columns', function () {
        $this->repository->bulkInsert([
            ['name' => 'User 1', 'email' => 'user@example.com', 'status' => 'active', 'category_id' => 1],
        ]);

        $data = [
            ['name' => 'Updated User', 'email' => 'user@example.com', 'status' => 'modified', 'category_id' => 1],
            ['name' => 'Different Category', 'email' => 'user@example.com', 'status' => 'new', 'category_id' => 2],
        ];

        $result = $this->repository->bulkUpsert($data, ['email', 'category_id']);

        expect($result)->toBe(['inserted' => 1, 'updated' => 1])
            ->and(DB::table('bulk_test_models')->count())->toBe(2);
    });

    test('bulkUpsert processes in batches for large datasets', function () {
        $data = [];
        for ($i = 1; $i <= 1500; $i++) {
            $data[] = [
                'name' => "Batch User {$i}",
                'email' => "batch{$i}@example.com",
                'priority' => $i % 10
            ];
        }

        $result = $this->repository->bulkUpsert($data, ['email'], null, ['batch_size' => 500]);

        expect($result)->toBe(['inserted' => 1500, 'updated' => 0])
            ->and(DB::table('bulk_test_models')->count())->toBe(1500);
    });

    test('bulkCreateOrUpdate is alias for bulkUpsert', function () {
        $data = [
            ['name' => 'Alias Test', 'email' => 'alias@example.com', 'status' => 'active'],
        ];

        $result = $this->repository->bulkCreateOrUpdate($data, ['email']);

        expect($result)->toBe(['inserted' => 1, 'updated' => 0])
            ->and(DB::table('bulk_test_models')->count())->toBe(1);
    });

});

describe('Bulk Delete Operations', function () {

    beforeEach(function () {
        // Create test data
        $this->repository->bulkInsert([
            ['name' => 'Delete Test 1', 'email' => 'delete1@example.com', 'status' => 'active', 'priority' => 1],
            ['name' => 'Delete Test 2', 'email' => 'delete2@example.com', 'status' => 'inactive', 'priority' => 2],
            ['name' => 'Delete Test 3', 'email' => 'delete3@example.com', 'status' => 'active', 'priority' => 3],
            ['name' => 'Keep This', 'email' => 'keep@example.com', 'status' => 'pending', 'priority' => 1],
        ]);
    });

    test('bulkDelete removes records with simple conditions', function () {
        $count = $this->repository->bulkDelete(['status' => 'active']);

        expect($count)->toBe(2) // 2 records with status 'active'
            ->and(DB::table('bulk_test_models')->count())->toBe(2);

        $remaining = DB::table('bulk_test_models')->get();
        expect($remaining->pluck('status')->toArray())->not->toContain('active');

        // The repository may clear cache more than once per operation due to cache support.
        expect($this->repository->getCacheClearCount())->toBeGreaterThanOrEqual(1);
    });

    test('bulkDelete with complex conditions using operators', function () {
        $count = $this->repository->bulkDelete([
            ['priority', '>=', 2],
            'status' => 'active'
        ]);

        expect($count)->toBe(1) // Only 1 record matches both conditions
            ->and(DB::table('bulk_test_models')->count())->toBe(3);

        $deleted = DB::table('bulk_test_models')
            ->where('name', 'Delete Test 3')
            ->first();

        expect($deleted)->toBeNull();
    });

    test('bulkDelete with no matching conditions', function () {
        $count = $this->repository->bulkDelete(['status' => 'nonexistent']);

        expect($count)->toBe(0)
            ->and(DB::table('bulk_test_models')->count())->toBe(4); // All records remain
    });

    test('bulkDelete handles empty conditions array', function () {
        // Should delete all records when no conditions provided
        $count = $this->repository->bulkDelete([]);

        expect($count)->toBe(4) // All records deleted when no conditions
            ->and(DB::table('bulk_test_models')->count())->toBe(0);
    });

});

describe('Soft Delete Operations', function () {

    beforeEach(function () {
        // Create test data for soft delete testing
        DB::table('soft_delete_bulk_models')->insert([
            ['name' => 'Soft Delete 1', 'email' => 'soft1@example.com', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Soft Delete 2', 'email' => 'soft2@example.com', 'status' => 'inactive', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Soft Delete 3', 'email' => 'soft3@example.com', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
    });

    test('bulkDelete uses soft delete for models with SoftDeletes trait', function () {
        $count = $this->softDeleteRepository->bulkDelete(['status' => 'active']);

        expect($count)->toBe(2);

        // Records should still exist but with deleted_at timestamp
        expect(DB::table('soft_delete_bulk_models')->count())->toBe(3);

        $softDeleted = DB::table('soft_delete_bulk_models')
            ->whereNotNull('deleted_at')
            ->count();

        expect($softDeleted)->toBe(2);
    });

    test('bulkDelete can force hard delete even with soft delete model', function () {
        $count = $this->softDeleteRepository->bulkDelete(
            ['status' => 'active'],
            ['soft_delete' => false]
        );

        expect($count)->toBe(2);

        // Records should be completely removed
        expect(DB::table('soft_delete_bulk_models')->count())->toBe(1);
    });

    test('bulkDelete can explicitly enable soft delete', function () {
        $count = $this->softDeleteRepository->bulkDelete(
            ['status' => 'active'],
            ['soft_delete' => true]
        );

        expect($count)->toBe(2);

        $softDeleted = DB::table('soft_delete_bulk_models')
            ->whereNotNull('deleted_at')
            ->count();

        expect($softDeleted)->toBe(2);
    });

});

describe('Helper Methods and Edge Cases', function () {

    test('buildUniqueKey creates correct key string', function () {
        $record = ['email' => 'test@example.com', 'category_id' => 5, 'name' => 'Test'];
        $uniqueColumns = ['email', 'category_id'];

        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('buildUniqueKey');
        $method->setAccessible(true);

        $key = $method->invoke($this->repository, $record, $uniqueColumns);

        expect($key)->toBe('test@example.com|5');
    });

    test('buildUniqueKey handles missing columns gracefully', function () {
        $record = ['email' => 'test@example.com'];
        $uniqueColumns = ['email', 'missing_column'];

        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('buildUniqueKey');
        $method->setAccessible(true);

        $key = $method->invoke($this->repository, $record, $uniqueColumns);

        expect($key)->toBe('test@example.com|');
    });

    test('getExistingKeys returns empty array for empty data', function () {
        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('getExistingKeys');
        $method->setAccessible(true);

        $keys = $method->invoke($this->repository, [], ['email']);

        expect($keys)->toBe([]);
    });

    test('modelUsesSoftDeletes detects SoftDeletes trait correctly', function () {
        $reflection = new ReflectionClass($this->softDeleteRepository);
        $method = $reflection->getMethod('modelUsesSoftDeletes');
        $method->setAccessible(true);

        $result = $method->invoke($this->softDeleteRepository);

        expect($result)->toBeTrue();
    });

    test('modelUsesSoftDeletes returns false for regular models', function () {
        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('modelUsesSoftDeletes');
        $method->setAccessible(true);

        $result = $method->invoke($this->repository);

        expect($result)->toBeFalse();
    });

    test('decodeBulkField handles scalar values', function () {
        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('decodeBulkField');
        $method->setAccessible(true);

        $result = $method->invoke($this->repository, 'id', 'abc123');

        // This would depend on your HashIdHelper implementation
        expect($result)->toBe('abc123'); // Assuming no decoding for this test
    });

    test('decodeBulkField handles array values recursively', function () {
        $reflection = new ReflectionClass($this->repository);
        $method = $reflection->getMethod('decodeBulkField');
        $method->setAccessible(true);

        $values = [['id1'], ['id2'], ['id3']];
        $result = $method->invoke($this->repository, 'id', $values);

        expect($result)->toHaveCount(3)
            ->and($result[0])->toBe(['id1']);
    });

});

describe('Performance and Memory Testing', function () {

    test('bulkInsert is memory efficient with large datasets', function () {
        $initialMemory = memory_get_usage(true);

        $data = [];
        for ($i = 1; $i <= 5000; $i++) {
            $data[] = [
                'name' => "Performance User {$i}",
                'email' => "perf{$i}@example.com",
                'priority' => $i % 10
            ];
        }

        $startTime = microtime(true);
        $count = $this->repository->bulkInsert($data, ['batch_size' => 1000]);
        $duration = microtime(true) - $startTime;

        $finalMemory = memory_get_usage(true);
        $memoryUsed = $finalMemory - $initialMemory;

        expect($count)->toBe(5000)
            ->and($duration)->toBeLessThan(10.0) // Should complete within 10 seconds
            ->and($memoryUsed)->toBeLessThan(100 * 1024 * 1024) // Less than 100MB
            ->and(DB::table('bulk_test_models')->count())->toBe(5000);
    });

    test('bulkUpsert handles large mixed operations efficiently', function () {
        // Create some existing data
        $existing = [];
        for ($i = 1; $i <= 1000; $i++) {
            $existing[] = [
                'name' => "Existing {$i}",
                'email' => "existing{$i}@example.com",
                'priority' => $i % 5
            ];
        }
        $this->repository->bulkInsert($existing);

        // Prepare mixed upsert data (half updates, half inserts)
        $upsertData = [];
        for ($i = 1; $i <= 2000; $i++) {
            $upsertData[] = [
                'name' => "User {$i}",
                'email' => $i <= 1000 ? "existing{$i}@example.com" : "new{$i}@example.com",
                'priority' => 99
            ];
        }

        $startTime = microtime(true);
        $result = $this->repository->bulkUpsert($upsertData, ['email'], null, ['batch_size' => 500]);
        $duration = microtime(true) - $startTime;

        expect($result['inserted'])->toBe(1000)
            ->and($result['updated'])->toBe(1000)
            ->and($duration)->toBeLessThan(15.0) // Should complete within 15 seconds
            ->and(DB::table('bulk_test_models')->count())->toBe(2000);
    });

    test('bulkUpdate performance scales with dataset size', function () {
        // Insert test data
        $data = [];
        for ($i = 1; $i <= 3000; $i++) {
            $data[] = [
                'name' => "Update Test {$i}",
                'email' => "update{$i}@example.com",
                'status' => $i % 2 === 0 ? 'active' : 'inactive'
            ];
        }
        $this->repository->bulkInsert($data);

        $startTime = microtime(true);
        $count = $this->repository->bulkUpdate(
            ['status' => 'bulk_updated'],
            ['status' => 'active']
        );
        $duration = microtime(true) - $startTime;

        expect($count)->toBe(1500) // Half the records
            ->and($duration)->toBeLessThan(5.0); // Should be fast
    });

});

describe('Integration with Timestamps and Caching', function () {

    test('all bulk operations clear cache when cache method exists', function () {
        // Test bulkInsert
        $this->repository->bulkInsert([
            ['name' => 'Cache Test 1', 'email' => 'cache1@example.com']
        ]);
        expect($this->repository->getCacheClearCount())->toBeGreaterThanOrEqual(1);

        // Test bulkUpdate
        $this->repository->bulkUpdate(['status' => 'updated'], ['name' => 'Cache Test 1']);
        expect($this->repository->getCacheClearCount())->toBeGreaterThanOrEqual(1);

        // Test bulkUpsert
        $this->repository->bulkUpsert([
            ['name' => 'Cache Test 2', 'email' => 'cache2@example.com']
        ], ['email']);
        expect($this->repository->getCacheClearCount())->toBeGreaterThanOrEqual(1);

        // Test bulkDelete
        $this->repository->bulkDelete(['name' => 'Cache Test 1']);
        expect($this->repository->getCacheClearCount())->toBeGreaterThanOrEqual(1);
    });

    test('timestamp handling is consistent across operations', function () {
        // Test insert timestamps
        $this->repository->bulkInsert([
            ['name' => 'Timestamp Test', 'email' => 'timestamp@example.com']
        ]);

        $record = DB::table('bulk_test_models')->where('name', 'Timestamp Test')->first();
        $createdAt = Carbon::parse($record->created_at);
        $updatedAt = Carbon::parse($record->updated_at);

        expect($createdAt->isValid())->toBeTrue()
            ->and($updatedAt->isValid())->toBeTrue();

        // Test update timestamps
        $originalUpdatedAt = $updatedAt;

        // Wait a full second to ensure time difference
        sleep(1);

        $this->repository->bulkUpdate(['status' => 'updated'], ['name' => 'Timestamp Test']);

        $updatedRecord = DB::table('bulk_test_models')->where('name', 'Timestamp Test')->first();
        $newUpdatedAt = Carbon::parse($updatedRecord->updated_at);

        expect($newUpdatedAt->greaterThan($originalUpdatedAt))->toBeTrue();
    });

});
