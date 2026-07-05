<?php

namespace App\Platform\Workflow;

use App\Platform\Workflow\Contracts\StatusTransitionContract;

/**
 * StatusTransition — concrete implementation of a status transition.
 *
 * Create transitions via the static factory or implement StatusTransitionContract.
 */
class StatusTransition implements StatusTransitionContract
{
    public function __construct(
        protected string $fromStatus,
        protected string $toStatus,
        protected string $action,
        protected string $label,
        protected ?string $requiredPermission = null,
        protected ?string $requiredRole = null,
        protected ?array $conditions = null,
        protected array $eventsToFire = [],
    ) {}

    public static function make(
        string $fromStatus,
        string $toStatus,
        string $action,
        string $label,
        ?string $requiredPermission = null,
        ?string $requiredRole = null,
        ?array $conditions = null,
        array $eventsToFire = [],
    ): self {
        return new self(
            $fromStatus, $toStatus, $action, $label,
            $requiredPermission, $requiredRole, $conditions, $eventsToFire,
        );
    }

    public function getFromStatus(): string { return $this->fromStatus; }
    public function getToStatus(): string { return $this->toStatus; }
    public function getAction(): string { return $this->action; }
    public function getRequiredPermission(): ?string { return $this->requiredPermission; }
    public function getRequiredRole(): ?string { return $this->requiredRole; }
    public function getConditions(): ?array { return $this->conditions; }
    public function getEventsToFire(): array { return $this->eventsToFire; }
    public function getLabel(): string { return $this->label; }
}
