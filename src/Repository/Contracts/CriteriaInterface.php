<?php

namespace Apiato\Repository\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Criteria Interface
 * Defines the contract for applying criteria to repository queries
 */
interface CriteriaInterface
{
    /**
     * Apply criteria in query repository
     *
     * @param Model|Builder $model
     * @param RepositoryInterface $repository
     * @return Model|Builder
     */
    public function apply(Model|Builder $model, RepositoryInterface $repository): Model|Builder;
}
