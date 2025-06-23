<?php

namespace Apiato\Repository\Events;

use Apiato\Repository\Contracts\RepositoryInterface;

/**
 * Repository Models Bulk Updated Event
 * Fired after multiple models are updated in bulk
 */
class RepositoryBulkUpdated extends RepositoryEventBase
{
    protected string $action = "bulk_updated";
    protected array $conditions;
    protected array $values;
    protected int $count;

    public function __construct(RepositoryInterface $repository, array $conditions, array $values, int $count)
    {
        $this->repository = $repository;
        $this->conditions = $conditions;
        $this->values = $values;
        $this->count = $count;
        $this->model = null; // No single model for bulk operations
    }

    /**
     * Get the update conditions
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /**
     * Get the update values
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * Get the count of updated models
     */
    public function getCount(): int
    {
        return $this->count;
    }
}
