<?php

namespace Apiato\Repository\Events;

/**
 * Repository Updated Event
 * Fired after a model is updated
 */
class RepositoryUpdated extends RepositoryEventBase
{
    protected string $action = "updated";

    /**
     * Get the updated model's ID
     */
    public function getModelId()
    {
        if ($this->model && method_exists($this->model, 'getKey')) {
            return $this->model->getKey();
        }

        return null;
    }

    /**
     * Get the changes made to the model
     */
    public function getChanges(): array
    {
        if ($this->model && method_exists($this->model, 'getChanges')) {
            return $this->model->getChanges();
        }

        return [];
    }

    /**
     * Get the original attributes
     */
    public function getOriginal(): array
    {
        if ($this->model && method_exists($this->model, 'getOriginal')) {
            return $this->model->getOriginal();
        }

        return [];
    }
}
