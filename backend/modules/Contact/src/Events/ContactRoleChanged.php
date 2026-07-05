<?php

namespace Modules\Contact\src\Events;

class ContactRoleChanged extends ContactEvent
{
    protected string $eventName = 'contact.role.changed';

    public function __construct(
        string $tenantId,
        string $contactId,
        public readonly string $role,
        public readonly string $action, // 'added' | 'removed'
        ?string $userId = null,
    ) {
        parent::__construct($tenantId, $contactId, $userId);
    }

    public function getPayload(): array
    {
        return [
            'contact_id' => $this->contactId,
            'role' => $this->role,
            'action' => $this->action,
        ];
    }
}
