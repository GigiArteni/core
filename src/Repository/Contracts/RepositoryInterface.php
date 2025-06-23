<?php

namespace Apiato\Repository\Contracts;

use Closure;

/**
 * Repository Interface - Enhanced for Apiato v.13
 * Compatible with repository pattern + performance improvements
 */
interface RepositoryInterface
{
    // Core repository methods
    public function all(array $columns = ['*']): \Illuminate\Support\Collection;
    public function first(array $columns = ['*']): mixed;
    public function paginate(int $limit = null, array $columns = ['*']): mixed;
    public function find(mixed $id, array $columns = ['*']): mixed;
    public function findByField(string $field, mixed $value, array $columns = ['*']): \Illuminate\Support\Collection;
    public function findWhere(array $where, array $columns = ['*']): \Illuminate\Support\Collection;
    public function findWhereIn(string $field, array $where, array $columns = ['*']): \Illuminate\Support\Collection;
    public function findWhereNotIn(string $field, array $where, array $columns = ['*']): \Illuminate\Support\Collection;
    public function findWhereBetween(string $field, array $where, array $columns = ['*']): \Illuminate\Support\Collection;
    public function create(array $attributes): mixed;
    public function update(array $attributes, mixed $id): mixed;
    public function updateOrCreate(array $attributes, array $values = []): mixed;
    public function delete(mixed $id): bool;
    public function deleteWhere(array $where): int;
    public function orderBy(string $column, string $direction = 'asc'): static;
    public function with(array $relations): static;
    public function has(string $relation): static;
    public function whereHas(string $relation, Closure $closure): static;
    public function hidden(array $fields): static;
    public function visible(array $fields): static;
    public function scopeQuery(Closure $scope): static;
    public function getFieldsSearchable(): array;
    public function setPresenter(mixed $presenter): static;
    public function skipPresenter(bool $status = true): static;
}
