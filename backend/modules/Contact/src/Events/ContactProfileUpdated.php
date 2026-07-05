<?php

namespace Modules\Contact\src\Events;

class ContactProfileUpdated extends ContactEvent
{
    public function __construct(
        string $tenantId,
        string $contactId,
        public readonly string $profileType, // 'accounting_customer', 'crm', 'hr', etc.
        ?string $userId = null,
        ?array $oldValue = null,
        ?array $newValue = null,
    ) {
        parent::__construct($tenantId, $contactId, $userId, $oldValue, $newValue);
    }
}
