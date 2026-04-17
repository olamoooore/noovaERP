<?php

namespace Modules\FinancialClose\DTOs;

use Modules\FinancialClose\Enums\TaskStatus;
use Modules\FinancialClose\Enums\TaskPriority;

class TaskSummary
{
    public function __construct(
        public string $id,
        public string $title,
        public TaskStatus $status,
        public TaskPriority $priority,
        public ?string $assignedTo,
        public ?string $dueDate,
        public bool $isBlocked,
        public bool $isOverdue,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'assigned_to' => $this->assignedTo,
            'due_date' => $this->dueDate,
            'is_blocked' => $this->isBlocked,
            'is_overdue' => $this->isOverdue,
        ];
    }
}