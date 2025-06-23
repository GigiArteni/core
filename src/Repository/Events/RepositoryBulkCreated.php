<?php

namespace Apiato\Repository\Events;

use Apiato\Repository\Contracts\RepositoryInterface;

/**
 * Repository Models Bulk Created Event
 * Fired after multiple models are created in bulk
 */
class RepositoryBulkCreated extends RepositoryEventBase
{
    protected string $action = "bulk_created";
    protected array $models;

    public function __construct(RepositoryInterface $repository, array $models)
    {
        $this->repository = $repository;
        $this->models = $models;
        $this->model = null; // No single model for bulk operations
    }

    /**
     * Get the created models
     */
    public function getModels(): array
    {
        return $this->models;
    }

    /**
     * Get the count of created models
     */
    public function getCount(): int
    {
        return count($this->models);
    }
}
