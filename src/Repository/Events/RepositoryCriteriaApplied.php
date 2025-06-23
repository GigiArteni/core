<?php

namespace Apiato\Repository\Events;

use Apiato\Repository\Contracts\CriteriaInterface;
use Apiato\Repository\Contracts\RepositoryInterface;

/**
 * Repository Criteria Applied Event
 * Fired when criteria is applied to a repository
 */
class RepositoryCriteriaApplied extends RepositoryEventBase
{
    protected string $action = "criteria_applied";
    protected CriteriaInterface $criteria;

    public function __construct(RepositoryInterface $repository, CriteriaInterface $criteria)
    {
        $this->repository = $repository;
        $this->criteria = $criteria;
        $this->model = null; // No model for criteria events
    }

    /**
     * Get the applied criteria
     */
    public function getCriteria(): CriteriaInterface
    {
        return $this->criteria;
    }

    /**
     * Get the criteria class name
     */
    public function getCriteriaClass(): string
    {
        return get_class($this->criteria);
    }
}
