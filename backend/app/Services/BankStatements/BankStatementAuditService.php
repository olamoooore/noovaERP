<?php

namespace App\Services\BankStatements;

use App\Models\BankStatementAuditEvent;
use Illuminate\Support\Str;

class BankStatementAuditService
{
    public function record(string $event, array $context, ?string $correlationId = null): BankStatementAuditEvent
    {
        return BankStatementAuditEvent::create([
            ...$context,
            'event' => $event,
            'correlation_id' => $correlationId ?: (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }
}
