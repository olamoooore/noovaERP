<?php

namespace Modules\Contact\src\Events;

class ContactImportCompleted extends ContactEvent
{
    protected string $eventName = 'contact.import.completed';

    public function __construct(
        string $tenantId,
        public readonly string $batchId,
        public readonly int $importedCount,
        public readonly int $failedCount,
        ?string $userId = null,
    ) {
        parent::__construct($tenantId, '', $userId);
    }

    public function getPayload(): array
    {
        return [
            'batch_id' => $this->batchId,
            'imported_count' => $this->importedCount,
            'failed_count' => $this->failedCount,
        ];
    }
}
