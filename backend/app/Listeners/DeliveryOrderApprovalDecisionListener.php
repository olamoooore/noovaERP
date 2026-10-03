<?php

namespace App\Listeners;

use App\Accounting\Events\ApprovalDecisionEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Services\DeliveryOrderService;

/**
 * Reflects approval decisions taken in the approvals inbox (/approvals) onto
 * the delivery order they belong to — mirroring InvoiceApprovalDecisionListener
 * for the invoice flow.
 */
class DeliveryOrderApprovalDecisionListener
{
    public function __construct(
        private DeliveryOrderService $deliveryService,
    ) {}

    public function handle(ApprovalDecisionEvent $event): void
    {
        $approvalRequest = $event->approvalRequest;

        if ($approvalRequest->request_type !== 'delivery_order_approval'
            || $approvalRequest->subject_type !== DeliveryOrder::class) {
            return;
        }

        $deliveryOrder = DeliveryOrder::find($approvalRequest->subject_id);

        if (! $deliveryOrder) {
            Log::warning('DeliveryOrderApprovalDecisionListener: delivery order not found', [
                'approval_request_id' => $approvalRequest->id,
                'subject_id' => $approvalRequest->subject_id,
            ]);

            return;
        }

        $actor = $event->actor
            ?? ($approvalRequest->reviewed_by ? User::find($approvalRequest->reviewed_by) : null)
            ?? User::find($approvalRequest->requested_by);

        if (! $actor) {
            Log::warning('DeliveryOrderApprovalDecisionListener: no actor resolvable', [
                'approval_request_id' => $approvalRequest->id,
            ]);

            return;
        }

        try {
            match ($event->action) {
                // The decision was already authorised by ApprovalService —
                // apply the outcome directly (the checked entry points would
                // correctly refuse a request that is no longer pending).
                ApprovalDecisionEvent::ACTION_APPROVED => $this->deliveryService->applyApprovedDecision($deliveryOrder, $actor),
                ApprovalDecisionEvent::ACTION_REJECTED => $this->deliveryService->applyRejectedDecision($deliveryOrder, $actor, $event->reason ?? 'No reason provided'),
                ApprovalDecisionEvent::ACTION_EXPIRED => $this->deliveryService->applyRejectedDecision($deliveryOrder, $actor, 'Approval request expired'),
                ApprovalDecisionEvent::ACTION_WITHDRAWN => $deliveryOrder->update([
                    'requires_approval' => false,
                    'approval_status' => 'not_required',
                ]),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('DeliveryOrderApprovalDecisionListener: callback failed', [
                'delivery_order_id' => $deliveryOrder->id,
                'action' => $event->action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
