<?php

namespace App\Listeners;

use App\Accounting\Events\ApprovalDecisionEvent;
use App\Models\ApprovalRequest;
use App\Models\Quotation;
use App\Models\User;
use App\Platform\Notifications\NotificationReferences;
use App\Platform\Notifications\NotificationService;
use App\Services\SalesApprovalGate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;

/**
 * Reacts to approval decisions on threshold requests raised by
 * SalesApprovalGate — issue #650.
 *
 * Before this listener, only invoices and delivery orders were wired to
 * ApprovalDecisionEvent. A gate request for a quotation or sales order could
 * be approved and nobody acted on it: the requester was never told the
 * outcome, and the approved document sat in `draft` with no signal that the
 * gated confirm/convert could now proceed.
 *
 * Decisions follow the gate's recorded design (#180, #646): the document is
 * NOT advanced automatically — a gated quotation/sales order stays `draft`
 * and editable, and an edit invalidates a stale approval. Instead the
 * decision is evaluated for continuation and the approved request records
 * whether the gated action can now be completed (`metadata.resume`). The
 * requester is notified on the platform notification channel (in-app row +
 * mail) with a link to the document, whose existing confirm/convert action is
 * the one-click "Continue" that completes it — idempotently, because the
 * manual endpoints already guard on document status and reuse the approved
 * request instead of raising a fresh one.
 */
class SalesApprovalDecisionListener
{
    /**
     * Request types this listener owns. Invoice types belong to
     * InvoiceApprovalService's listener (which already notifies); delivery
     * orders resume through DeliveryOrderApprovalDecisionListener, so here
     * they only contribute the requester notification.
     *
     * @var string[]
     */
    private const HANDLED_TYPES = [
        SalesApprovalGate::REQUEST_TYPE_QUOTATION,
        SalesApprovalGate::REQUEST_TYPE_SALES_ORDER,
        SalesApprovalGate::REQUEST_TYPE_DELIVERY_ORDER,
    ];

    public function __construct(
        private SalesApprovalGate $gate,
        private NotificationService $notifications,
    ) {}

    public function handle(ApprovalDecisionEvent $event): void
    {
        $approvalRequest = $event->approvalRequest;

        if (! in_array($approvalRequest->request_type, self::HANDLED_TYPES, true)) {
            return;
        }

        $resume = ['available' => false, 'action' => null, 'reason' => null];

        if ($event->action === ApprovalDecisionEvent::ACTION_APPROVED) {
            $resume = $this->evaluateResume($approvalRequest);
            $this->recordResumeAvailability($approvalRequest, $resume);
        }

        $this->notifyRequester($approvalRequest, $event, $resume);
    }

    /**
     * Whether the gated action can be completed now, and which action that is.
     * Mirrors the gate's own rules: the document must still match the approved
     * snapshot (#646) and not have moved past the gated transition.
     *
     * @return array{available: bool, action: ?string, reason: ?string}
     */
    private function evaluateResume(ApprovalRequest $approvalRequest): array
    {
        return match ($approvalRequest->request_type) {
            SalesApprovalGate::REQUEST_TYPE_QUOTATION => $this->evaluateQuotationResume($approvalRequest),
            SalesApprovalGate::REQUEST_TYPE_SALES_ORDER => $this->evaluateSalesOrderResume($approvalRequest),
            // Delivery orders are resumed by DeliveryOrderApprovalDecisionListener
            // (approval_status is reflected on the document itself).
            default => ['available' => false, 'action' => null, 'reason' => 'handled_elsewhere'],
        };
    }

    /**
     * @return array{available: bool, action: ?string, reason: ?string}
     */
    private function evaluateQuotationResume(ApprovalRequest $approvalRequest): array
    {
        $quotation = Quotation::find($approvalRequest->subject_id);

        if (! $quotation) {
            return ['available' => false, 'action' => null, 'reason' => 'document_not_found'];
        }

        if ($quotation->sales_order_id !== null) {
            // Already released: the remaining continuation is the conversion
            // (invoice / delivery order) the operator was after.
            return $quotation->status === 'confirmed'
                ? ['available' => true, 'action' => 'convert', 'reason' => null]
                : ['available' => false, 'action' => null, 'reason' => 'already_advanced'];
        }

        if (! in_array($quotation->status, ['draft', 'sent'], true)) {
            return ['available' => false, 'action' => null, 'reason' => 'already_advanced'];
        }

        if (! $this->gate->requestMatchesDocument($approvalRequest, $quotation)) {
            return ['available' => false, 'action' => null, 'reason' => 'document_changed'];
        }

        return ['available' => true, 'action' => 'confirm', 'reason' => null];
    }

    /**
     * @return array{available: bool, action: ?string, reason: ?string}
     */
    private function evaluateSalesOrderResume(ApprovalRequest $approvalRequest): array
    {
        $order = SalesOrder::find($approvalRequest->subject_id);

        if (! $order) {
            return ['available' => false, 'action' => null, 'reason' => 'document_not_found'];
        }

        if (! $order->isDraft()) {
            return ['available' => false, 'action' => null, 'reason' => 'already_advanced'];
        }

        if (! $this->gate->requestMatchesDocument($approvalRequest, $order)) {
            return ['available' => false, 'action' => null, 'reason' => 'document_changed'];
        }

        return ['available' => true, 'action' => 'confirm', 'reason' => null];
    }

    /**
     * Persist the continuation verdict on the request so the approvals API
     * (list/show/approve responses include `metadata`) can show a Continue
     * affordance without re-deriving the gate rules.
     *
     * @param  array{available: bool, action: ?string, reason: ?string}  $resume
     */
    private function recordResumeAvailability(ApprovalRequest $approvalRequest, array $resume): void
    {
        try {
            $metadata = $approvalRequest->metadata ?? [];
            $metadata['resume'] = [
                'available' => $resume['available'],
                'action' => $resume['action'],
                'reason' => $resume['reason'],
                'checked_at' => now()->toIso8601String(),
            ];

            $approvalRequest->update(['metadata' => $metadata]);
        } catch (\Throwable $e) {
            Log::warning('SalesApprovalDecisionListener: failed to record resume availability', [
                'approval_request_id' => $approvalRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array{available: bool, action: ?string, reason: ?string}  $resume
     */
    private function notifyRequester(ApprovalRequest $approvalRequest, ApprovalDecisionEvent $event, array $resume): void
    {
        try {
            $requester = User::find($approvalRequest->requested_by);

            if (! $requester) {
                return;
            }

            $approved = $event->action === ApprovalDecisionEvent::ACTION_APPROVED;
            $document = $this->documentFor($approvalRequest);
            $label = $this->documentLabel($approvalRequest, $document);
            $number = $this->documentNumber($document, $approvalRequest);

            $references = $document !== null
                ? NotificationReferences::forDocument($document)
                : ['primary' => null, 'links' => []];

            $body = $approved
                ? "{$label} {$number} was approved."
                    .($resume['available']
                        ? ' Open the document and use its confirm/convert action to continue.'
                        : ' No automatic continuation is available — open the document for details.')
                : "{$label} {$number} approval was {$event->action}."
                    .($event->reason !== null ? " Reason: {$event->reason}" : '');

            $this->notifications->dispatch(
                $approved ? 'approval.approved' : 'approval.rejected',
                $requester,
                array_merge([
                    'subject' => "Approval {$event->action}: {$label} {$number}",
                    'body' => $body,
                    'type' => $approvalRequest->request_type,
                    'amount' => (float) $approvalRequest->amount,
                    'reason' => $event->reason ?? $approvalRequest->review_notes ?? 'No reason provided',
                    'decision' => $event->action,
                    'approved_by' => $event->actor?->name,
                    // The one-click Continue contract: the document is linked
                    // through `primary`/`links` and the gated action to run is
                    // named here (confirm | convert).
                    'resume_available' => $resume['available'],
                    'resume_action' => $resume['action'],
                    'resume_reason' => $resume['reason'],
                    'approval_request_id' => $approvalRequest->id,
                    'approval_request_number' => $approvalRequest->request_number,
                ], $references),
            );
        } catch (\Throwable $e) {
            Log::warning('SalesApprovalDecisionListener: failed to notify requester', [
                'approval_request_id' => $approvalRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function documentFor(ApprovalRequest $approvalRequest): ?Model
    {
        return match ($approvalRequest->request_type) {
            SalesApprovalGate::REQUEST_TYPE_QUOTATION => Quotation::find($approvalRequest->subject_id),
            SalesApprovalGate::REQUEST_TYPE_SALES_ORDER => SalesOrder::find($approvalRequest->subject_id),
            SalesApprovalGate::REQUEST_TYPE_DELIVERY_ORDER => DeliveryOrder::find($approvalRequest->subject_id),
            default => null,
        };
    }

    private function documentLabel(ApprovalRequest $approvalRequest, ?Model $document): string
    {
        if ($document instanceof Quotation) {
            return 'Quotation';
        }

        if ($document instanceof SalesOrder) {
            return 'Sales order';
        }

        if ($document instanceof DeliveryOrder) {
            return 'Delivery order';
        }

        return match ($approvalRequest->request_type) {
            SalesApprovalGate::REQUEST_TYPE_QUOTATION => 'Quotation',
            SalesApprovalGate::REQUEST_TYPE_SALES_ORDER => 'Sales order',
            default => 'Document',
        };
    }

    private function documentNumber(?Model $document, ApprovalRequest $approvalRequest): string
    {
        $number = $document?->quotation_number
            ?? $document?->sales_order_number
            ?? $document?->delivery_order_number
            ?? $document?->invoice_number;

        if ($number !== null && $number !== '') {
            return (string) $number;
        }

        return (string) ($approvalRequest->request_number ?? '#'.$approvalRequest->subject_id);
    }
}
