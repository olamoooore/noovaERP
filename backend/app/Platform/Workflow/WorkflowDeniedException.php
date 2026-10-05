<?php

namespace App\Platform\Workflow;

use RuntimeException;

/**
 * Thrown when a status transition is not defined for the subject's current
 * status, or when one of its guards (permission / approval / period lock)
 * refuses it. Callers must surface the message instead of flipping a status.
 */
class WorkflowDeniedException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $entityType = '',
        private readonly string $entityId = '',
        private readonly string $action = '',
    ) {
        parent::__construct($message);
    }

    public function entityType(): string
    {
        return $this->entityType;
    }

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function action(): string
    {
        return $this->action;
    }
}
