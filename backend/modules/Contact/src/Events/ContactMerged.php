<?php

namespace Modules\Contact\src\Events;

class ContactMerged extends ContactEvent
{
    protected string $eventName = 'contact.merged';

    public function __construct(
        string $tenantId,
        string $primaryContactId,
        public readonly string $mergedContactId,
        ?string $userId = null,
    ) {
        parent::__construct($tenantId, $primaryContactId, $userId);
    }

    public function getPayload(): array
    {
        return [
            'primary_contact_id' => $this->contactId,
            'merged_contact_id' => $this->mergedContactId,
        ];
    }
}
