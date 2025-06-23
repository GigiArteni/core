<?php

namespace Apiato\Repository\Exceptions;

use Exception;

/**
 * Repository Exception
 * Custom exception for repository-related errors
 */
class RepositoryException extends Exception
{
    /**
     * Create a new repository exception instance
     */
    public function __construct($message = "Repository Exception", $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the exception message with context
     */
    public function getMessageWithContext(): string
    {
        return sprintf('[Repository Error] %s', $this->getMessage());
    }
}
