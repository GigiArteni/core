<?php

namespace Apiato\Repository\Contracts;

use Illuminate\Support\Collection;

/**
 * Repository Criteria Interface
 * Defines the contract for managing criteria in repositories
 */
interface RepositoryCriteriaInterface
{
    /**
     * Push Criteria for filter the query
     *
     * @param CriteriaInterface $criteria
     * @return $this
     */
    public function pushCriteria(CriteriaInterface $criteria): static;

    /**
     * Pop Criteria
     *
     * @param CriteriaInterface $criteria
     * @return $this
     */
    public function popCriteria(CriteriaInterface $criteria): static;

    /**
     * Get Collection of Criteria
     *
     * @return Collection<int, CriteriaInterface>
     */
    public function getCriteria(): Collection;

    /**
     * Find data by Criteria
     *
     * @param CriteriaInterface $criteria
     * @return mixed
     */
    public function findByCriteria(CriteriaInterface $criteria): mixed;
}
