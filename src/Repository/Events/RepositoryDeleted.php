<?php

namespace Apiato\Repository\Events;

/**
 * Repository Deleted Event
 * Fired after a model is deleted
 */
class RepositoryDeleted extends RepositoryEventBase
{
    protected string $action = "deleted";

    /**
     * Get the deleted model's ID
     */
    public function getModelId()
    {
        if ($this->model && method_exists($this->model, 'getKey')) {
            return $this->model->getKey();
        }

        return null;
    }

    /**
     * Get the deleted model's attributes
     */
    public function getDeletedAttributes(): array
    {
        if ($this->model && method_exists($this->model, 'getAttributes')) {
            return $this->model->getAttributes();
        }

        return [];
    }
}
