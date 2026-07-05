<?php

namespace Modules\Contact\src\Events;

class ContactMerged extends ContactEvent
{
    public function __construct(
        string $tenantId,
        string $primaryContactId,
        public readonly string $mergedContactId,
        ?string $userId = null,
    ) {
        parent::__construct($tenantId, $primaryContactId, $userId);
    }
}
