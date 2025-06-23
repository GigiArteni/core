<?php

namespace Apiato\Repository\Events;

/**
 * Repository Created Event
 * Fired after a new model is created
 */
class RepositoryCreated extends RepositoryEventBase
{
    protected string $action = "created";

    /**
     * Get the created model's ID
     */
    public function getModelId()
    {
        if ($this->model && method_exists($this->model, 'getKey')) {
            return $this->model->getKey();
        }

        return null;
    }
}
