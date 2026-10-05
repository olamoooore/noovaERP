<?php

namespace Modules\Sales\Services;

use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Platform\Workflow\Contracts\WorkflowSubject;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Events\SalesEventCatalogue;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesEventOutboxEvent;
use Modules\Sales\Models\SalesOrder;

/**
 * SalesDomainEventEmitter — the single writer of Sales domain events
 * (issue #901, PC7).
 *
 * Called at the sales transition choke point (SalesWorkflowService::transition)
 * and from the non-workflow sales money paths (credit-note posting, payment
 * posting/refund), it maps the business fact to a SalesEventCatalogue name,
 * builds the documented payload and writes a durable sales_event_outbox row
 * **inside the caller's transaction**. Nothing is published from here: after
 * the surrounding transaction commits (DB::afterCommit), the SalesOutboxPublisher
 * dispatches the row onto the platform Domain Event Bus; the scheduled
 * `sales:publish-outbox` command sweeps anything the immediate hook missed.
 * A rolled-back transaction therefore leaves no event at all.
 */
class SalesDomainEventEmitter
{
    public function emitForTransition(
        WorkflowSubject $subject,
        string $action,
        array $result,
        array $context = [],
    ): ?SalesEventOutboxEvent {
        if (! ($result['success'] ?? false)) {
            return null;
        }

        $eventName = SalesEventCatalogue::eventForTransition($subject->workflowEntityType(), $action);

        if ($eventName === null) {
            return null;
        }

        return $this->record(
            $eventName,
            $this->describeDocument($subject),
            ['action' => $action, 'reason' => $context['reason'] ?? null],
            $result['old_status'] ?? null,
            $result['new_status'] ?? null,
        );
    }

    public function emitCreditNotePosted(CustomerCreditNote $creditNote): ?SalesEventOutboxEvent
    {
        $creditNote = $creditNote->fresh() ?? $creditNote;

        return $this->record(
            SalesEventCatalogue::CREDIT_NOTE_POSTED,
            $this->describeDocument($creditNote),
            ['invoice_id' => $creditNote->invoice_id ? (int) $creditNote->invoice_id : null],
            'draft',
            'posted',
        );
    }

    public function emitPaymentReceived(Payment $payment, ?string $statusBefore = null): ?SalesEventOutboxEvent
    {
        $payment = $payment->fresh() ?? $payment;

        return $this->record(
            SalesEventCatalogue::PAYMENT_RECEIVED,
            $this->describeDocument($payment),
            ['action' => 'post'],
            $statusBefore,
            $payment->payment_status,
        );
    }

    public function emitPaymentRefunded(Payment $refundPayment, ?string $statusBefore = 'applied'): ?SalesEventOutboxEvent
    {
        $refundPayment = $refundPayment->fresh() ?? $refundPayment;

        return $this->record(
            SalesEventCatalogue::PAYMENT_REFUNDED,
            $this->describeDocument($refundPayment),
            [
                'action' => 'refund',
                'original_payment_id' => $refundPayment->original_payment_id
                    ? (int) $refundPayment->original_payment_id
                    : null,
            ],
            $statusBefore,
            $refundPayment->payment_status,
        );
    }

    /**
     * Persist the outbox row and arrange post-commit publication. Returns null
     * when the outbox table has not been migrated for this tenant yet (schema
     * guard: a rolling deployment must not break sales operations).
     */
    private function record(
        string $eventName,
        array $document,
        array $extra,
        ?string $statusBefore,
        ?string $statusAfter,
    ): ?SalesEventOutboxEvent {
        if (! Schema::hasTable('sales_event_outbox')) {
            return null;
        }

        $eventId = (string) Str::uuid();
        $correlationId = (string) Str::uuid();
        $occurredAt = now();
        $tenantId = $this->currentTenantId();
        $actorId = $this->currentActorId();

        $payload = array_merge([
            'event_id' => $eventId,
            'event_name' => $eventName,
            'version' => SalesEventCatalogue::EVENT_VERSION,
            'tenant_id' => $tenantId,
            'source_module' => 'sales',
            'occurred_at' => $occurredAt->toIso8601String(),
            'actor_user_id' => $actorId,
            'correlation_id' => $correlationId,
            'status_before' => $statusBefore,
            'status_after' => $statusAfter,
        ], $document, $extra);

        $row = SalesEventOutboxEvent::create([
            'id' => $eventId,
            'tenant_id' => $tenantId,
            'event_name' => $eventName,
            'version' => SalesEventCatalogue::EVENT_VERSION,
            'document_type' => $document['document_type'] ?? 'unknown',
            'document_id' => isset($document['document_id']) ? (string) $document['document_id'] : '',
            'document_number' => $document['document_number'] ?? null,
            'actor_user_id' => $actorId,
            'correlation_id' => $correlationId,
            'occurred_at' => $occurredAt,
            'payload' => $payload,
        ]);

        DB::afterCommit(
            fn () => app(SalesOutboxPublisher::class)->publishRow($row->id)
        );

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeDocument(object $document): array
    {
        $base = [
            'document_id' => method_exists($document, 'getKey') ? $document->getKey() : null,
            'legal_entity_id' => $document->getAttribute('legal_entity_id') !== null
                ? (int) $document->getAttribute('legal_entity_id')
                : null,
            'customer_id' => $document->getAttribute('customer_id') !== null
                ? (int) $document->getAttribute('customer_id')
                : null,
            'currency_code' => $document->getAttribute('currency_code')
                ?? $document->getAttribute('currency'),
            'base_currency_code' => $document->getAttribute('base_currency_code'),
            'exchange_rate' => $document->getAttribute('exchange_rate') !== null
                ? (float) $document->getAttribute('exchange_rate')
                : null,
        ];

        if ($document instanceof Invoice) {
            return $base + [
                'document_type' => 'invoice',
                'document_number' => $document->invoice_number,
                'total' => (float) $document->total,
                'journal_entry_id' => $document->journal_entry_id ? (int) $document->journal_entry_id : null,
                'due_date' => $document->due_date?->toDateString(),
                'paid_amount' => (float) $document->paid_amount,
                'balance' => (float) $document->balance,
            ];
        }

        if ($document instanceof Quotation) {
            return $base + [
                'document_type' => 'quotation',
                'document_number' => $document->quotation_number,
                'total' => (float) $document->total,
                'sales_order_id' => $document->sales_order_id ? (int) $document->sales_order_id : null,
                'valid_until' => $document->valid_until?->toDateString(),
            ];
        }

        if ($document instanceof SalesOrder) {
            return $base + [
                'document_type' => 'sales_order',
                'document_number' => $document->sales_order_number,
                'total' => (float) $document->total,
            ];
        }

        if ($document instanceof DeliveryOrder) {
            return $base + [
                'document_type' => 'delivery_order',
                'document_number' => $document->delivery_order_number,
                'total' => (float) $document->total,
                'sales_order_id' => $document->sales_order_id ? (int) $document->sales_order_id : null,
                'warehouse_id' => $document->warehouse_id ? (int) $document->warehouse_id : null,
            ];
        }

        if ($document instanceof CustomerCreditNote) {
            return $base + [
                'document_type' => 'credit_note',
                'document_number' => $document->credit_note_number,
                'total' => (float) $document->total_amount,
                'journal_entry_id' => $document->journal_entry_id ? (int) $document->journal_entry_id : null,
            ];
        }

        if ($document instanceof Payment) {
            return $base + [
                'document_type' => 'payment',
                'document_number' => $document->payment_number,
                'total' => (float) $document->amount,
                'invoice_id' => $document->invoice_id ? (int) $document->invoice_id : null,
                'method' => (string) ($document->method ?? ''),
                'journal_entry_id' => $document->journal_entry_id ? (int) $document->journal_entry_id : null,
                'original_payment_id' => $document->original_payment_id ? (int) $document->original_payment_id : null,
            ];
        }

        return $base + [
            'document_type' => class_basename($document),
            'document_number' => null,
            'total' => null,
        ];
    }

    private function currentActorId(): ?int
    {
        $actorId = Auth::id();

        return is_numeric($actorId) ? (int) $actorId : null;
    }

    private function currentTenantId(): string
    {
        $tenantId = function_exists('tenant') && tenant()
            ? (string) (tenant('id') ?? '')
            : '';

        return $tenantId !== ''
            ? $tenantId
            : '00000000-0000-0000-0000-000000000000';
    }
}
