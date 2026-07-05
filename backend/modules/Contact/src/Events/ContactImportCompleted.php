<?php

namespace Modules\Contact\src\Events;

class ContactImportCompleted extends ContactEvent
{
    public function __construct(
        string $tenantId,
        public readonly string $batchId,
        public readonly int $importedCount,
        public readonly int $failedCount,
        ?string $userId = null,
    ) {
        parent::__construct($tenantId, '', $userId);
    }
}
