<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceActivityLog;

/**
 * The single writer of invoice_activity_log (MRD FR-10).
 *
 * Every state-changing invoice service must record its transition here so the
 * timeline endpoint and audit trail stay complete. The log is append-only.
 */
class InvoiceActivityService
{
    public function log(
        Invoice $invoice,
        string $event,
        array $metadata = [],
        ?string $fromState = null,
        ?string $toState = null,
        ?string $actorType = null,
        ?int $actorId = null,
    ): InvoiceActivityLog {
        $actorId ??= auth()->id();
        $actorType ??= $actorId !== null
            ? InvoiceActivityLog::ACTOR_USER
            : InvoiceActivityLog::ACTOR_SYSTEM;

        return InvoiceActivityLog::create([
            'invoice_id' => $invoice->id,
            'event' => $event,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'from_state' => $fromState,
            'to_state' => $toState,
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);
    }
}
