<?php

namespace Apiato\Repository\Events;

/**
 * Repository Creating Event
 * Fired before a new model is created
 */
class RepositoryCreating extends RepositoryEventBase
{
    protected string $action = "creating";

    /**
     * Get the attributes being created
     */
    public function getAttributes(): array
    {
        return is_array($this->model) ? $this->model : [];
    }
}
