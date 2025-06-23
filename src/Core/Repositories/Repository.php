<?php

namespace Apiato\Core\Repositories;

use Apiato\Core\Repositories\Exceptions\ResourceCreationFailed;
use Apiato\Core\Repositories\Exceptions\ResourceNotFound;
use Illuminate\Database\Eloquent\Model;
use Apiato\Repository\Contracts\CriteriaInterface;
use Apiato\Repository\Eloquent\BaseRepository;

/**
 * @template TModel of Model
 */
abstract class Repository extends BaseRepository
{
    /**
     * Define the maximum number of entries per page that is returned.
     * Set to 0 to "disable" this feature.
     */
    protected int $maxPaginationLimit = 0;

    protected bool|null $allowDisablePagination = null;

    /** @var \Closure[] */
    protected array $scopes = [];

    public function __construct()
    {
        parent::__construct(app());
    }

    // --- Pagination limit logic ---
    public function setPaginationLimit($limit): mixed
    {
        return $limit ?? request()?->input('limit');
    }

    public function wantsToSkipPagination(string|int|null $limit): bool
    {
        return '0' === $limit || 0 === $limit;
    }

    public function canSkipPagination(): mixed
    {
        if (!is_null($this->allowDisablePagination)) {
            return $this->allowDisablePagination;
        }
        return config('repository.pagination.skip');
    }

    public function exceedsMaxPaginationLimit(mixed $limit): bool
    {
        return $this->maxPaginationLimit > 0 && $limit > $this->maxPaginationLimit;
    }

    // --- Scope stack logic ---
    public function scope(\Closure $scope): static
    {
        $this->scopes[] = $scope;
        return $this;
    }

    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function resetScope(): static
    {
        $this->scopes = [];
        return $this;
    }

    protected function applyScopes($query = null)
    {
        $query = $query ?: $this->getQuery();
        foreach ($this->scopes as $scope) {
            if (!is_callable($scope)) {
                throw new \RuntimeException('Query scope is not callable');
            }
            $query = $scope($query);
        }
        return $query;
    }

    // Override getQuery to apply scopes as well as eager loads
    protected function getQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = $this->query ?? $this->model->newQuery();
        $query = $this->applyEagerLoadIncludes($query);
        $query = $this->applyScopes($query);
        return $query;
    }

    // --- Implement required RepositoryInterface methods for compatibility ---
    public function hidden(array|null $fields = null): static { return $this; }
    public function visible(array|null $fields = null): static { return $this; }
    public function scopeQuery(\Closure $scope): static { return $this; }
    public function getFieldsSearchable(): array { return $this->fieldSearchable ?? []; }
    public function getModel(): Model { return parent::getModel(); }
    public function getPresenter() { return null; }
    public function setPresenter($presenter): static { return $this; }
    public function skipPresenter(bool $status = true): static { return $this; }
    public function findByCriteria(\Apiato\Repository\Contracts\CriteriaInterface $criteria): mixed
    {
        return parent::getByCriteria($criteria);
    }

    // --- Exception translation for create/update/delete/findOrFail ---
    public function create(array $attributes): mixed
    {
        try {
            return parent::create($attributes);
        } catch (\Exception) {
            throw ResourceCreationFailed::create(class_basename($this->model()));
        }
    }

    public function update(array $attributes, mixed $id): mixed
    {
        try {
            return parent::update($attributes, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            throw ResourceNotFound::create(class_basename($this->model()));
        }
    }

    public function delete(mixed $id): bool
    {
        try {
            return (bool) parent::delete($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            throw ResourceNotFound::create(class_basename($this->model()));
        }
    }

    public function findOrFail(int|string $id, array $columns = ['*']): mixed
    {
        return $this->find($id, $columns) ?? throw ResourceNotFound::create(class_basename($this->model()));
    }

    // --- Criteria helpers ---
    public function pushCriteriaWith(string $criteria, array $args): static
    {
        /** @var CriteriaInterface $criteriaInstance */
        $criteriaInstance = $this->app->makeWith($criteria, $args);
        return $this->pushCriteria($criteriaInstance);
    }

    /**
     * Helper to add RequestCriteria for query string search/filter support.
     *
     * @return static
     */
    public function addRequestCriteria(): static
    {
        // You may need to adjust the class if you use a custom RequestCriteria
        return $this->pushCriteria(app(\Apiato\Repository\Criteria\RequestCriteria::class));
    }

    /**
     * Resolve the model class name using the central Apiato repository resolver, if available.
     * Falls back to convention-based guessing if not.
     */
    public function model(): string
    {
        return apiato()->repository()->resolveModelName(static::class);
    }


    public function make(array $attributes = []): Model
    {
        return $this->model->newInstance($attributes);
    }

    public function store($data): Model
    {
        if ($data instanceof Model) {
            $data->save();
            return $data;
        }
        return $this->create($data);
    }

    public function save($model): bool
    {
        return $model->save();
    }

    // --- Removed custom firstOrCreate: now using BaseRepository implementation which applies criteria, scope, and resets model. ---
    public function removeRequestCriteria(): static
    {
        // Remove all instances of RequestCriteria from criteria stack
        $this->criteria = $this->criteria->reject(function ($item) {
            return $item instanceof \Apiato\Repository\Criteria\RequestCriteria;
        });
        return $this;
    }

    // --- Fix for with() compatibility: accept string or array ---
    public function with(array|string $relations): static
    {
        if (is_string($relations)) {
            $relations = array_map('trim', explode(',', $relations));
        }
        return parent::with($relations);
    }
}
