<?php

namespace Apiato\Repository\Eloquent;

use Closure;
use Exception;
use Illuminate\Container\Container as Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Apiato\Repository\Contracts\CacheableInterface;
use Apiato\Repository\Contracts\CriteriaInterface;
use Apiato\Repository\Contracts\RepositoryCriteriaInterface;
use Apiato\Repository\Contracts\RepositoryInterface;
use Apiato\Repository\Contracts\ValidatorInterface;
use Apiato\Repository\Exceptions\RepositoryException;
use Apiato\Repository\Traits\BulkOperations;
use Apiato\Repository\Traits\CacheableRepository;
use Apiato\Repository\Traits\TransactionalRepository;
use Apiato\Repository\Support\HashIdHelper;

/**
 * Lightweight BaseRepository for Apiato
 *
 * HashId decoding is now built-in and automatic for all repository lookups.
 * See HashIdHelper for details. Controlled by config('repository.hashid_decode').
 */
abstract class BaseRepository implements RepositoryInterface, CacheableInterface, RepositoryCriteriaInterface
{
    use CacheableRepository;
    use TransactionalRepository;
    use BulkOperations;

    protected Application $app;
    protected Model $model;
    /**
     * @var Builder|null
     */
    protected ?Builder $query = null;
    protected ?ValidatorInterface $validator = null;
    protected ?Closure $scopeQuery = null;
    /**
     * @var Collection<int, CriteriaInterface>
     */
    protected Collection $criteria;
    protected bool $skipCriteria = false;
    /**
     * @var array<string, string>
     */
    protected array $fieldSearchable = [];
    /**
     * @var array<int, string>
     */
    protected array $with = [];
    /**
     * @var array<int, string>
     */
    protected array $hidden = [];
    /**
     * @var array<int, string>
     */
    protected array $visible = [];
    /**
     * @var array<string, mixed>|null
     */
    protected ?array $rules = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->criteria = new Collection();
        $this->makeModel();
        $this->makeValidator();
        $this->boot();
    }

    /**
     * Boot method for repository initialization
     * Override in child classes for custom setup
     */
    public function boot(): void
    {
        // Can be overridden in child classes
    }

    /**
     * Specify Model class name
     * Must be implemented in child classes
     */
    abstract public function model(): string;

    /**
     * Create model instance
     */
    public function makeModel(): Model
    {
        $model = $this->app->make($this->model());

        if (!$model instanceof Model) {
            throw new RepositoryException("Class {$this->model()} must be an instance of Illuminate\\Database\\Eloquent\\Model");
        }
        $this->query = null; // Reset query builder
        return $this->model = $model;
    }

    // ========================================
    // CORE REPOSITORY METHODS
    // ========================================

    /**
     * Get all records.
     *
     * HashId decoding is automatic for all repository lookups (see decodeField).
     */
    public function all(array $columns = ['*']): \Illuminate\Support\Collection
    {
        $this->applyCriteria();
        $this->applyScope();
        $results = $this->getQuery()->get($columns);
        $this->resetModel();
        $this->resetScope();
        return $results;
    }

    /**
     * Get the first record.
     *
     * HashId decoding is automatic for all repository lookups (see decodeField).
     */
    public function first(array $columns = ['*']): mixed
    {
        $this->applyCriteria();
        $this->applyScope();
        $results = $this->getQuery()->first($columns);
        $this->resetModel();
        $this->resetScope();
        return $results;
    }

    /**
     * Paginate records.
     *
     * HashId decoding is automatic for all repository lookups (see decodeField).
     */
    public function paginate(int $limit = null, array $columns = ['*']): mixed
    {
        $this->applyCriteria();
        $this->applyScope();
        $limit = is_null($limit) ? config('repository.pagination.limit', 15) : $limit;
        $results = $this->getQuery()->paginate($limit, $columns);
        $this->resetModel();
        return $results;
    }

    /**
     * Find a record by its primary key.
     *
     * HashId decoding is automatic for all repository lookups (see decodeField).
     * @param mixed $id Encoded or plain ID
     */
    public function find(mixed $id, array $columns = ['*']): mixed
    {
        $id = $this->decodeField('id', $id);
        $this->applyCriteria();
        $this->applyScope();
        $model = $this->getQuery()->find($id, $columns);
        $this->resetModel();
        return $model;
    }

    /**
     * Find a model by its primary key. Returns null if not found.
     * HashId decoding is automatic.
     */
    public function findById(mixed $id, array $columns = ['*']): ?Model
    {
        return $this->find($id, $columns);
    }

    /**
     * Find records by a field value.
     *
     * HashId decoding is automatic for all repository lookups (see decodeField).
     */
    public function findByField(string $field, mixed $value, array $columns = ['*']): \Illuminate\Support\Collection
    {
        $value = $this->decodeField($field, $value);
        $this->applyCriteria();
        $this->applyScope();
        $model = $this->getQuery()->where($field, '=', $value)->get($columns);
        $this->resetModel();
        return $model;
    }

    /**
     * Find multiple models by their primary keys. Returns a collection.
     * HashId decoding is automatic.
     */
    public function findMany(array $ids, array $columns = ['*']): Collection
    {
        $ids = $this->decodeField('id', $ids);
        $this->applyCriteria();
        $this->applyScope();
        $result = $this->getQuery()->whereIn('id', $ids)->get($columns);
        $this->resetModel();
        $this->resetScope();
        return $result;
    }

    /**
     * Find records by multiple where conditions.
     *findById 
     * HashId decoding is automatic for all repository lookups (see decodeField).
     * Supports nested/composite keys and custom operators.
     */
    public function findWhere(array $where, array $columns = ['*']): \Illuminate\Support\Collection
    {
        $this->applyCriteria();
        $this->applyScope();
        $query = $this->getQuery();
        foreach ($where as $field => $value) {
            if (is_array($value)) {
                // Support for custom operator arrays and nested arrays
                if (array_is_list($value) && isset($value[0]) && is_array($value[0])) {
                    foreach ($value as $sub) {
                        if (is_array($sub) && count($sub) === 3) {
                            [$f, $condition, $val] = $sub;
                            $val = $this->decodeField($f, $val);
                            $query = $query->where($f, $condition, $val);
                        }
                    }
                } elseif (count($value) === 3) {
                    [$f, $condition, $val] = $value;
                    $val = $this->decodeField($f, $val);
                    $query = $query->where($f, $condition, $val);
                } else {
                    // Fallback: treat as normal value
                    $value = $this->decodeField($field, $value);
                    $query = $query->where($field, '=', $value);
                }
            } else {
                $value = $this->decodeField($field, $value);
                $query = $query->where($field, '=', $value);
            }
        }
        $model = $query->get($columns);
        $this->resetModel();
        return $model;
    }

    /**
     * Find records where a field is in a set of values.
     *
     * HashId decoding is automatic for all repository lookups (see decodeField).
     */
    public function findWhereIn(string $field, array $where, array $columns = ['*']): \Illuminate\Support\Collection
    {
        $where = $this->decodeField($field, $where);
        $this->applyCriteria();
        $this->applyScope();
        $model = $this->getQuery()->whereIn($field, $where)->get($columns);
        $this->resetModel();
        return $model;
    }

    /**
     * Find records where a field is not in a set of values.
     *
     * HashId decoding is automatic for all repository lookups (see decodeField).
     */
    public function findWhereNotIn(string $field, array $where, array $columns = ['*']): \Illuminate\Support\Collection
    {
        $where = $this->decodeField($field, $where);
        $this->applyCriteria();
        $this->applyScope();
        $model = $this->getQuery()->whereNotIn($field, $where)->get($columns);
        $this->resetModel();
        return $model;
    }

    /**
     * Find records where a field is between two values.
     *
     * HashId decoding is automatic for all repository lookups (see decodeField).
     */
    public function findWhereBetween(string $field, array $where, array $columns = ['*']): \Illuminate\Support\Collection
    {
        $this->applyCriteria();
        $this->applyScope();
        $model = $this->getQuery()->whereBetween($field, $where)->get($columns);
        $this->resetModel();
        return $model;
    }

    /**
     * Create a record.
     *
     * HashId decoding is automatic for all repository lookups (see decodeField).
     */
    public function create(array $attributes): mixed
    {
        if (!is_null($this->validator)) {
            $this->validator->with($attributes);
            if (!$this->validator->passesCreate()) {
                throw new \Exception('Validation failed: ' . json_encode($this->validator->errors()));
            }
        }
        $model = $this->getQuery()->create($attributes);
        $model->save();
        $this->resetModel();
        return $model;
        
    }

    /**
     * Update a record by its primary key.
     *
     * HashId decoding is automatic for all repository lookups (see decodeField).
     * @param mixed $id Encoded or plain ID
     */
    public function update(array $attributes, mixed $id): mixed
    {
        $id = $this->decodeField('id', $id);
        $this->applyCriteria();
        $this->applyScope();
        if (!is_null($this->validator)) {
            $this->validator->with($attributes);
            if (!$this->validator->passesUpdate()) {
                throw new \Exception('Validation failed: ' . json_encode($this->validator->errors()));
            }
        }
        $model = $this->getQuery()->findOrFail($id);
        $model->fill($attributes);
        $model->save();
        $this->resetModel();
        return $model;
    }

    public function updateOrCreate(array $attributes, array $values = []): mixed
    {
        // Decode attributes in a key-preserving way
        foreach ($attributes as $key => $value) {
            $attributes[$key] = $this->decodeField($key, $value);
        }
        $this->applyCriteria();
        $this->applyScope();
        $model = $this->getQuery()->updateOrCreate($attributes, $values);
        $this->resetModel();
        return $model;
    }

    /**
     * Retrieve the first record matching the attributes or create it.
     * HashId decoding is automatic for all repository lookups (see decodeField).
     *
     * @param array $attributes
     * @param array $values
     * @return Model
     */
    public function firstOrCreate(array $attributes, array $values = []): Model
    {
        // Decode attributes in a key-preserving way
        foreach ($attributes as $key => $value) {
            $attributes[$key] = $this->decodeField($key, $value);
        }
        $this->applyCriteria();
        $this->applyScope();
        $model = $this->getQuery()->firstOrCreate($attributes, $values);
        $this->resetModel();
        return $model;
    }

    /**
     * Delete a record by its primary key.
     *
     * HashId decoding is automatic for all repository lookups (see decodeField).
     * @param mixed $id Encoded or plain ID
     */
    public function delete(mixed $id): bool
    {
        $id = $this->decodeField('id', $id);
        $this->applyCriteria();
        $this->applyScope();
        $model = $this->getQuery()->findOrFail($id);
        $this->resetModel();
        $originalModel = clone $model;
        $deleted = $originalModel->delete();
        return (bool)$deleted;
    }

    /**
     * Delete records by multiple where conditions.
     *
     * HashId decoding is automatic for all repository lookups (see decodeField).
     * Supports nested/composite keys and custom operators.
     */
    public function deleteWhere(array $where): int
    {
        $this->applyCriteria();
        $this->applyScope();
        $query = $this->getQuery();
        foreach ($where as $field => $value) {
            if (is_array($value)) {
                // Support for custom operator arrays and nested arrays
                if (array_is_list($value) && isset($value[0]) && is_array($value[0])) {
                    foreach ($value as $sub) {
                        if (is_array($sub) && count($sub) === 3) {
                            [$f, $condition, $val] = $sub;
                            $val = $this->decodeField($f, $val);
                            $query = $query->where($f, $condition, $val);
                        }
                    }
                } elseif (count($value) === 3) {
                    [$f, $condition, $val] = $value;
                    $val = $this->decodeField($f, $val);
                    $query = $query->where($f, $condition, $val);
                } else {
                    $value = $this->decodeField($field, $value);
                    $query = $query->where($field, '=', $value);
                }
            } else {
                $value = $this->decodeField($field, $value);
                $query = $query->where($field, '=', $value);
            }
        }
        $deleted = $query->delete();
        $this->resetModel();
        return (int)$deleted;
    }

    /**
     * Get a list of values for a given column.
     */
    public function pluck(string $column, ?string $key = null): \Illuminate\Support\Collection
    {
        $this->applyCriteria();
        $this->applyScope();
        $results = $this->getQuery()->pluck($column, $key);
        $this->resetModel();
        $this->resetScope();
        return $results;
    }

    /**
     * Count the number of records matching the current query.
     */
    public function count(): int
    {
        $this->applyCriteria();
        $this->applyScope();
        $count = $this->getQuery()->count();
        $this->resetModel();
        $this->resetScope();
        return $count;
    }

    /**
     * Determine if any records exist for the current query.
     */
    public function exists(): bool
    {
        $this->applyCriteria();
        $this->applyScope();
        $exists = $this->getQuery()->exists();
        $this->resetModel();
        $this->resetScope();
        return $exists;
    }

    // ========================================
    // QUERY BUILDER METHODS
    // ========================================

    public function with(array $relations): static
    {
        $this->query = $this->getQuery()->with($relations);
        return $this;
    }

    public function has(string $relation): static
    {
        $this->query = $this->getQuery()->has($relation);
        return $this;
    }

    public function whereHas(string $relation, Closure $closure): static
    {
        $this->query = $this->getQuery()->whereHas($relation, $closure);
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->query = $this->getQuery()->orderBy($column, $direction);
        return $this;
    }

    // ========================================
    // IMPLEMENTATION METHODS
    // ========================================

    public function makeValidator(): void
    {
        $validator = $this->validator();

        if (!is_null($validator)) {
            $this->validator = is_string($validator) ? $this->app->make($validator) : $validator;
        }
    }

    public function validator(): ValidatorInterface|null
    {
        return null;
    }

    public function resetModel(): static
    {
        $this->makeModel();
        return $this;
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    // ========================================
    // CRITERIA METHODS
    // ========================================

    public function pushCriteria(CriteriaInterface $criteria): static
    {
        $this->criteria->push($criteria);
        return $this;
    }

    public function popCriteria(CriteriaInterface $criteria): static
    {
        $this->criteria = $this->criteria->reject(function ($item) use ($criteria) {
            return get_class($item) === get_class($criteria);
        });
        return $this;
    }

    /**
     * @return Collection<int, CriteriaInterface>
     */
    public function getCriteria(): Collection
    {
        return $this->criteria;
    }

    public function getByCriteria(CriteriaInterface $criteria): array
    {
        $this->model = $criteria->apply($this->model, $this);
        $results = $this->model->get()->all();
        $this->resetModel();
        return $results;
    }

    public function skipCriteria(bool $status = true): static
    {
        $this->skipCriteria = $status;
        return $this;
    }

    public function clearCriteria(): static
    {
        $this->criteria = new Collection();
        return $this;
    }

    public function applyCriteria(): static
    {
        if ($this->skipCriteria) {
            return $this;
        }
        $criteria = $this->getCriteria();
        foreach ($criteria as $c) {
            if ($c instanceof CriteriaInterface) {
                $this->model = $c->apply($this->model, $this);
            }
        }
        return $this;
    }

    protected function applyScope(): static
    {
        if (is_callable($this->scopeQuery)) {
            $callback = $this->scopeQuery;
            $this->model = $callback($this->model);
        }
        return $this;
    }

    protected function resetScope(): static
    {
        $this->scopeQuery = null;
        return $this;
    }

    protected function applyConditions(array $where): void
    {
        $query = $this->getQuery();
        foreach ($where as $field => $value) {
            if (is_array($value)) {
                [$f, $condition, $val] = $value;
                $query = $query->where($f, $condition, $val);
            } else {
                $query = $query->where($field, '=', $value);
            }
        }
        $this->query = $query;
    }

    /**
     * Apply eager loading from the `include` query parameter if enabled in config.
     * Supports dot notation (e.g. ?include=user.roles.permissions)
     */
    protected function applyEagerLoadIncludes(Builder $query): Builder
    {
        if (!config('repository.eager_load_includes', true)) {
            return $query;
        }
        $includeParam = request()->query('include');
        if (empty($includeParam)) {
            return $query;
        }
        // Accept both string and array for compatibility
        if (is_array($includeParam)) {
            $relations = $includeParam;
        } else {
            $relations = array_filter(array_map('trim', explode(',', $includeParam)));
        }
        if (!empty($relations)) {
            $query = $query->with($relations);
        }
        return $query;
    }

    /**
     * Override getQuery to always apply eager loading includes if enabled.
     */
    protected function getQuery(): Builder
    {
        $query = $this->query ?? $this->model->newQuery();
        $query = $this->applyEagerLoadIncludes($query);
        return $query;
    }

    /**
     * Optimization: decodeField now supports nested arrays and composite keys.
     * Handles edge cases for custom operators and deeply nested where conditions.
     * Used by all repository lookup and mutation methods.
     */
    protected function decodeField(string $field, mixed $value): mixed
    {
        // If value is an array of arrays (e.g., composite keys or nested conditions), decode recursively
        if (is_array($value) && array_is_list($value) && isset($value[0]) && is_array($value[0])) {
            return array_map(fn($v) => $this->decodeField($field, $v), $value);
        }
        return HashIdHelper::decodeIfNeeded($field, $value);
    }

    /**
     * Find the first record matching the given where conditions.
     *
     * HashId decoding is automatic for all repository lookups (see decodeField).
     * Supports nested/composite keys and custom operators.
     *
     * @param array $where
     * @param array $columns
     * @return Model|object|null
     */
    public function findWhereFirst(array $where, array $columns = ['*']): ?Model
    {
        $this->applyCriteria();
        $this->applyScope();
        $query = $this->getQuery();
        foreach ($where as $field => $value) {
            if (is_array($value)) {
                // Support for custom operator arrays and nested arrays
                if (array_is_list($value) && isset($value[0]) && is_array($value[0])) {
                    foreach ($value as $sub) {
                        if (is_array($sub) && count($sub) === 3) {
                            [$f, $condition, $val] = $sub;
                            $val = $this->decodeField($f, $val);
                            $query = $query->where($f, $condition, $val);
                        }
                    }
                } elseif (count($value) === 3) {
                    [$f, $condition, $val] = $value;
                    $val = $this->decodeField($f, $val);
                    $query = $query->where($f, $condition, $val);
                } else {
                    // Fallback: treat as normal value
                    $value = $this->decodeField($field, $value);
                    $query = $query->where($field, '=', $value);
                }
            } else {
                $value = $this->decodeField($field, $value);
                $query = $query->where($field, '=', $value);
            }
        }
        $result = $query->first($columns);
        $this->resetModel();
        $this->resetScope();
        return $result;
    }
}
