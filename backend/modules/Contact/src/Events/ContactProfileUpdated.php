<?php

namespace Modules\Contact\src\Events;

class ContactProfileUpdated extends ContactEvent
{
    protected string $eventName = 'contact.profile.updated';

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

    public function getPayload(): array
    {
        return [
            'contact_id' => $this->contactId,
            'profile_type' => $this->profileType,
            'old_value' => $this->oldValue,
            'new_value' => $this->newValue,
        ];
    }
}
