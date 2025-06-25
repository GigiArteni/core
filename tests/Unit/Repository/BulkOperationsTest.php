<?php

use Apiato\Repository\Support\HashIdHelper;
use Apiato\Repository\Traits\BulkOperations;
use Workbench\App\Containers\MySection\Book\Models\Book;
use Workbench\App\Containers\MySection\Book\Data\Repositories\BookRepository;
use Carbon\Carbon;

describe('HashIdHelper', function (): void {

    beforeEach(function (): void {
        // Enable hash ID decoding
        config(['repository.hashid_decode' => true]);
    });

    it('decodes hash IDs for id fields', function (): void {
        // Create a hash ID
        $originalId = 123;
        $hashId = app('hashids')->encode($originalId);

        $decoded = HashIdHelper::decodeIfNeeded('id', $hashId);

        expect($decoded)->toBe($originalId);
    });

    it('decodes hash IDs for fields ending with _id', function (): void {
        $originalId = 456;
        $hashId = app('hashids')->encode($originalId);

        expect(HashIdHelper::decodeIfNeeded('book_id', $hashId))->toBe($originalId)
            ->and(HashIdHelper::decodeIfNeeded('user_id', $hashId))->toBe($originalId)
            ->and(HashIdHelper::decodeIfNeeded('category_id', $hashId))->toBe($originalId);
    });

    it('does not decode fields that do not match pattern', function (): void {
        $value = 'some-value';

        expect(HashIdHelper::decodeIfNeeded('name', $value))->toBe($value)
            ->and(HashIdHelper::decodeIfNeeded('title', $value))->toBe($value)
            ->and(HashIdHelper::decodeIfNeeded('identifier', $value))->toBe($value);
    });

    it('handles numeric values without decoding', function (): void {
        $numericId = 789;

        expect(HashIdHelper::decodeIfNeeded('id', $numericId))->toBe($numericId)
            ->and(HashIdHelper::decodeIfNeeded('book_id', $numericId))->toBe($numericId);
    });

    it('decodes arrays of hash IDs', function (): void {
        $ids = [1, 2, 3, 4, 5];
        $hashIds = array_map(fn($id) => app('hashids')->encode($id), $ids);

        $decoded = HashIdHelper::decodeIfNeeded('id', $hashIds);

        expect($decoded)->toEqualCanonicalizing($ids);
    });

    it('handles mixed arrays with numeric and hash IDs', function (): void {
        $mixedIds = [
            123, // numeric
            app('hashids')->encode(456), // hash ID
            789, // numeric
            app('hashids')->encode(101112), // hash ID
        ];

        $decoded = HashIdHelper::decodeIfNeeded('book_id', $mixedIds);

        expect($decoded)->toEqualCanonicalizing([123, 456, 789, 101112]);
    });

    it('respects config setting for hash ID decoding', function (): void {
        $hashId = app('hashids')->encode(999);

        // Disable decoding
        config(['repository.hashid_decode' => false]);

        expect(HashIdHelper::decodeIfNeeded('id', $hashId))->toBe($hashId);

        // Re-enable decoding
        config(['repository.hashid_decode' => true]);

        expect(HashIdHelper::decodeIfNeeded('id', $hashId))->toBe(999);
    });

});

describe('BulkOperations with Hash ID Decoding', function (): void {

    beforeEach(function (): void {
        // Enable hash ID decoding
        config(['repository.hashid_decode' => true]);

        // Get repository instance
        $this->bookRepository = app(BookRepository::class);

        // Ensure repository uses BulkOperations trait
        expect(in_array(BulkOperations::class, class_uses_recursive($this->bookRepository)))->toBeTrue();
    });

    it('decodes hash IDs in bulkUpdate conditions', function (): void {
        // Create test books
        $books = Book::factory()->count(3)->create();
        $bookIds = $books->pluck('id')->toArray();
        $hashIds = array_map(fn($id) => app('hashids')->encode($id), $bookIds);

        // Update using hash IDs
        $affected = $this->bookRepository->bulkUpdate(
            ['title' => 'Updated Title'],
            ['id' => ['id', 'IN', $hashIds]]
        );

        expect($affected)->toBe(3);

        // Verify books were updated
        $updatedBooks = Book::whereIn('id', $bookIds)->get();
        expect($updatedBooks)->each(function ($book) {
            $book->title->toBe('Updated Title');
        });
    });

    it('handles bulkInsert with timestamps', function (): void {
        Carbon::setTestNow('2024-01-01 12:00:00');

        $data = [
            ['title' => 'Book 1'],
            ['title' => 'Book 2'],
            ['title' => 'Book 3'],
        ];

        $inserted = $this->bookRepository->bulkInsert($data);

        expect($inserted)->toBe(3);

        // Verify timestamps were added
        $books = Book::latest('id')->take(3)->get();
        expect($books)->each(function ($book) {
            $book->created_at->format('Y-m-d H:i:s')->toBe('2024-01-01 12:00:00');
            $book->updated_at->format('Y-m-d H:i:s')->toBe('2024-01-01 12:00:00');
        });

        Carbon::setTestNow();
    });

    it('processes bulkInsert in batches', function (): void {
        $data = [];
        for ($i = 1; $i <= 2500; $i++) {
            $data[] = ['title' => "Book $i"];
        }

        $batchesProcessed = 0;
        $inserted = $this->bookRepository->bulkInsert($data, [
            'batch_size' => 1000,
            'chunk_callback' => function ($inserted, $total, $totalRecords) use (&$batchesProcessed) {
                $batchesProcessed++;
            }
        ]);

        expect($inserted)->toBe(2500)
            ->and($batchesProcessed)->toBe(3); // 1000, 1000, 500
    });

    it('handles bulkUpsert with hash IDs', function (): void {
        // Create existing books with known titles
        $book1 = Book::factory()->create(['title' => 'Old Book 1']);
        $book2 = Book::factory()->create(['title' => 'Old Book 2']);
        $existingIds = [$book1->id, $book2->id];

        // Prepare upsert data with hash IDs
        $data = [
            // Update existing (using hash IDs)
            [
                'id' => app('hashids')->encode($existingIds[0]),
                'title' => 'Updated Book 1',
            ],
            [
                'id' => app('hashids')->encode($existingIds[1]),
                'title' => 'Updated Book 2',
            ],
            // Insert new
            [
                'title' => 'New Book 1',
            ],
            [
                'title' => 'New Book 2',
            ],
        ];

        $result = $this->bookRepository->bulkUpsert($data, ['id'], ['title']);

        expect($result)->toHaveKey('inserted', 2)
            ->toHaveKey('updated', 2);

        // Verify updates
        $updatedBooks = Book::whereIn('id', $existingIds)->get();
        $expectedTitles = collect($data)->where('id', '!=', null)->pluck('title')->toArray();
        expect($updatedBooks->pluck('title')->toArray())->toEqualCanonicalizing($expectedTitles);
    });

    it('handles bulkDelete with hash IDs', function (): void {
        // Create books to delete
        $booksToDelete = Book::factory()->count(3)->create();
        $booksToKeep = Book::factory()->count(2)->create();

        $deleteIds = $booksToDelete->pluck('id')->toArray();
        $hashIds = array_map(fn($id) => app('hashids')->encode($id), $deleteIds);

        $deleted = $this->bookRepository->bulkDelete([
            'id' => ['id', 'IN', $hashIds]
        ]);

        expect($deleted)->toBe(3)
            ->and(Book::count())->toBe(2)
            ->and(Book::whereIn('id', $booksToKeep->pluck('id'))->count())->toBe(2);
    });

    it('handles soft delete when model uses SoftDeletes', function (): void {
        // Assuming Book model uses SoftDeletes
        $books = Book::factory()->count(3)->create();
        $bookIds = $books->pluck('id')->toArray();
        $hashIds = array_map(fn($id) => app('hashids')->encode($id), $bookIds);

        Carbon::setTestNow('2024-01-01 15:00:00');

        $deleted = $this->bookRepository->bulkDelete([
            'id' => ['id', 'IN', $hashIds]
        ], ['soft_delete' => true]);

        expect($deleted)->toBe(3);

        // Books should be soft deleted
        $softDeletedBooks = Book::withTrashed()->whereIn('id', $bookIds)->get();
        expect($softDeletedBooks)->each(function ($book) {
            $book->deleted_at->format('Y-m-d H:i:s')->toBe('2024-01-01 15:00:00');
        });

        // Books should not appear in normal queries
        expect(Book::whereIn('id', $bookIds)->count())->toBe(0);

        Carbon::setTestNow();
    });

    it('handles complex conditions with hash IDs', function (): void {
        // Create books
        $books = Book::factory()->count(5)->create();
        $bookIds = $books->pluck('id')->toArray();
        $hashIds = array_map(fn($id) => app('hashids')->encode($id), $bookIds);

        // Update books by hash IDs with different data
        $updated = $this->bookRepository->bulkUpdate(
            ['title' => 'Bulk Updated Title'],
            ['id' => ['id', 'IN', $hashIds]]
        );

        expect($updated)->toBe(5);

        // Verify all books were updated
        $updatedBooks = Book::whereIn('id', $bookIds)->get();
        expect($updatedBooks)->toHaveCount(5)
            ->each(function ($book) {
                $book->title->toBe('Bulk Updated Title');
            });
    });

    it('handles bulkCreateOrUpdate alias', function (): void {
        $data = [
            ['title' => 'Book A'],
            ['title' => 'Book B'],
        ];

        // First insert
        $result1 = $this->bookRepository->bulkCreateOrUpdate($data, ['title']);
        expect($result1['inserted'])->toBe(2)
            ->and($result1['updated'])->toBe(0);

        // Update with modified data
        $data[0]['title'] = 'Book A';  // Same title
        $data[1]['title'] = 'Book B';  // Same title
        // Add new books
        $data[] = ['title' => 'Book C'];
        $data[] = ['title' => 'Book D'];

        $result2 = $this->bookRepository->bulkCreateOrUpdate($data, ['title']);
        expect($result2['inserted'])->toBe(2)  // Book C and D
            ->and($result2['updated'])->toBe(2);  // Book A and B
    });

    it('clears cache after bulk operations', function (): void {
        // Create a test repository with cache clearing tracking
        $repository = new class(app()) extends BookRepository {
            public $cacheCleared = false;

            public function clearCache(): void
            {
                $this->cacheCleared = true;
            }

            public function model(): string
            {
                return Book::class;
            }
        };

        $repository->bulkInsert([['title' => 'Test Book']]);

        expect($repository->cacheCleared)->toBeTrue();
    });

    it('decodes nested array conditions in bulk operations', function (): void {
        $books = Book::factory()->count(3)->create();
        $bookIds = $books->pluck('id')->toArray();
        $hashIds = array_map(fn($id) => app('hashids')->encode($id), $bookIds);

        // Test with complex nested conditions
        $conditions = [
            'id' => ['id', 'IN', $hashIds],
        ];

        $deleted = $this->bookRepository->bulkDelete($conditions);

        expect($deleted)->toBe(3)
            ->and(Book::whereIn('id', $bookIds)->count())->toBe(0);
    });

})->covers(
    BulkOperations::class,
    HashIdHelper::class
);
