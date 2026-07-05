<?php

namespace Modules\Contact\src\Events;

class ContactRoleChanged extends ContactEvent
{
    public function __construct(
        string $tenantId,
        string $contactId,
        public readonly string $role,
        public readonly string $action, // 'added' | 'removed'
        ?string $userId = null,
    ) {
        parent::__construct($tenantId, $contactId, $userId);
    }
}
