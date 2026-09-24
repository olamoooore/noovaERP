<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceActivityLog;
use App\Services\Chatter\ChatterService;

/**
 * The single writer of invoice_activity_log (MRD FR-10).
 *
 * Every state-changing invoice service must record its transition here so the
 * timeline endpoint and audit trail stay complete. The log is append-only.
 */
class InvoiceActivityService
{
    public function __construct(
        private ChatterService $chatterService,
    ) {}

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

        $row = InvoiceActivityLog::create([
            'invoice_id' => $invoice->id,
            'event' => $event,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'from_state' => $fromState,
            'to_state' => $toState,
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);

        $this->mirrorToChatter($invoice, $event, $metadata, $fromState, $toState, $actorId);

        return $row;
    }

    private function mirrorToChatter(
        Invoice $invoice,
        string $event,
        array $metadata,
        ?string $fromState,
        ?string $toState,
        ?int $actorId,
    ): void {
        $chatterEvent = match ($event) {
            InvoiceActivityLog::EVENT_CREATED => 'created',
            InvoiceActivityLog::EVENT_UPDATED => 'updated',
            InvoiceActivityLog::EVENT_SUBMITTED => 'status_changed',
            InvoiceActivityLog::EVENT_APPROVED => 'status_changed',
            InvoiceActivityLog::EVENT_REJECTED => 'status_changed',
            InvoiceActivityLog::EVENT_WITHDRAWN => 'status_changed',
            InvoiceActivityLog::EVENT_EXPIRED => 'status_changed',
            InvoiceActivityLog::EVENT_SENT => 'status_changed',
            InvoiceActivityLog::EVENT_POSTED => 'posted',
            InvoiceActivityLog::EVENT_PAID => 'paid',
            InvoiceActivityLog::EVENT_CANCELLED => 'cancelled',
            InvoiceActivityLog::EVENT_VOIDED => 'reversed',
            InvoiceActivityLog::EVENT_EVIDENCE_ADDED => 'attachment_uploaded',
            InvoiceActivityLog::EVENT_EVIDENCE_CONFIRMED => 'status_changed',
            InvoiceActivityLog::EVENT_PAYMENT_RECORDED => 'status_changed',
            InvoiceActivityLog::EVENT_PAYMENT_LINK_ISSUED => 'status_changed',
            InvoiceActivityLog::EVENT_RESET_TO_DRAFT => 'updated',
            default => 'updated',
        };

        $description = $this->chatterDescription($event, $fromState, $toState, $metadata);

        // Only emit properties a human benefits from reading. The raw event
        // name, actor id and the metadata blob are internal — the description
        // already carries the narrative, and dumping the array rendered as
        // "[object Object]" in the chatter panel.
        $properties = [];
        if (! empty($metadata['review_notes'])) {
            $properties['review_notes'] = $metadata['review_notes'];
        }
        if (! empty($metadata['approval_request_number'])) {
            $properties['approval_request'] = $metadata['approval_request_number'];
        }
        if (isset($metadata['total']) && is_numeric($metadata['total'])) {
            $properties['total'] = number_format((float) $metadata['total'], 2);
        }
        if (! empty($metadata['fields'])) {
            // Names of the fields an edit actually touched.
            $properties['fields'] = (string) $metadata['fields'];
        }
        if ($fromState !== $toState && $fromState !== null && $toState !== null) {
            $properties['state'] = "{$fromState} → {$toState}";
        }

        try {
            $this->chatterService->logEvent(
                Invoice::class,
                (int) $invoice->id,
                $chatterEvent,
                $description,
                $properties,
                $actorId,
                $actorId !== null ? \App\Models\User::class : null,
            );
        } catch (\Throwable) {
            // never break invoice flows if chatter mirroring fails
        }
    }

    private function chatterDescription(string $event, ?string $from, ?string $to, array $metadata): string
    {
        $invoiceNumber = $metadata['invoice_number'] ?? null;
        $ref = $invoiceNumber ? " {$invoiceNumber}" : '';

        return match ($event) {
            InvoiceActivityLog::EVENT_SUBMITTED => "Submitted{$ref} for approval",
            InvoiceActivityLog::EVENT_APPROVED => "Approved{$ref}".(isset($metadata['review_notes']) ? ': '.$metadata['review_notes'] : ''),
            InvoiceActivityLog::EVENT_REJECTED => "Rejected{$ref}".(isset($metadata['review_notes']) ? ': '.$metadata['review_notes'] : ''),
            InvoiceActivityLog::EVENT_WITHDRAWN => "Withdrew approval{$ref}",
            InvoiceActivityLog::EVENT_EXPIRED => "Approval expired{$ref}",
            InvoiceActivityLog::EVENT_POSTED => "Posted{$ref} to GL",
            InvoiceActivityLog::EVENT_PAID => "Marked{$ref} as paid",
            InvoiceActivityLog::EVENT_SENT => "Sent{$ref} to customer",
            InvoiceActivityLog::EVENT_CANCELLED => "Cancelled{$ref}",
            InvoiceActivityLog::EVENT_VOIDED => "Voided{$ref}",
            InvoiceActivityLog::EVENT_EVIDENCE_ADDED => "Added evidence{$ref}",
            InvoiceActivityLog::EVENT_EVIDENCE_CONFIRMED => "Confirmed evidence{$ref}",
            InvoiceActivityLog::EVENT_PAYMENT_RECORDED => "Recorded payment{$ref}",
            InvoiceActivityLog::EVENT_PAYMENT_LINK_ISSUED => "Issued payment link{$ref}",
            InvoiceActivityLog::EVENT_RESET_TO_DRAFT => "Reset{$ref} to draft",
            InvoiceActivityLog::EVENT_CREATED => "Created{$ref}",
            InvoiceActivityLog::EVENT_UPDATED => "Updated{$ref}",
            default => ucfirst(str_replace('_', ' ', $event)).$ref,
        };
    }
}
