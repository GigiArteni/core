<?php

namespace Apiato\Repository\Events;

use Illuminate\Database\Eloquent\Model;
use Apiato\Repository\Contracts\RepositoryInterface;

/**
 * Repository Event Base
 * Base class for all repository events
 */
abstract class RepositoryEventBase
{
    protected $model;
    protected RepositoryInterface $repository;
    protected string $action;

    /**
     * Create a new event instance
     */
    public function __construct(RepositoryInterface $repository, $model)
    {
        $this->repository = $repository;
        $this->model = $model;
    }

    /**
     * Get the model instance
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * Get the repository instance
     */
    public function getRepository(): RepositoryInterface
    {
        return $this->repository;
    }

    /**
     * Get the action name
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Get the model class name
     */
    public function getModelClass(): string
    {
        if ($this->model instanceof Model) {
            return get_class($this->model);
        }

        return 'Unknown';
    }

    /**
     * Get event data as array
     */
    public function toArray(): array
    {
        return [
            'action' => $this->getAction(),
            'model_class' => $this->getModelClass(),
            'repository_class' => get_class($this->repository),
            'timestamp' => now()->toISOString(),
        ];
    }
}
