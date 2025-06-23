<?php

namespace Apiato\Repository\Events;

/**
 * Repository Updating Event
 * Fired before a model is updated
 */
class RepositoryUpdating extends RepositoryEventBase
{
    protected string $action = "updating";

    /**
     * Get the attributes being updated
     */
    public function getAttributes(): array
    {
        return is_array($this->model) ? $this->model : [];
    }
}
