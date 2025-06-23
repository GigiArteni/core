<?php

namespace Apiato\Repository\Events;

use Apiato\Repository\Contracts\RepositoryInterface;

/**
 * Repository Bulk Deleted Event
 * Fired after multiple entities are deleted in bulk
 */
class RepositoryBulkDeleted extends RepositoryEventBase
{
    protected string $action = "bulk_deleted";
    protected array $conditions;
    protected int $affectedRows;

    public function __construct(RepositoryInterface $repository, array $conditions, int $affectedRows)
    {
        $this->repository = $repository;
        $this->conditions = $conditions;
        $this->affectedRows = $affectedRows;
        $this->model = null; // No single model for bulk operations
    }

    /**
     * Get the delete conditions
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /**
     * Get the number of affected rows
     */
    public function getAffectedRows(): int
    {
        return $this->affectedRows;
    }
}
