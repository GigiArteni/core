<?php

namespace Apiato\Repository\Events;

/**
 * Repository Deleting Event
 * Fired before a model is deleted
 */
class RepositoryDeleting extends RepositoryEventBase
{
    protected string $action = "deleting";

    /**
     * Get the ID of the model being deleted
     */
    public function getModelId()
    {
        return $this->model;
    }
}
