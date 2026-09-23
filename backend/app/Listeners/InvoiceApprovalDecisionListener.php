<?php

namespace App\Listeners;

use App\Accounting\Events\ApprovalDecisionEvent;
use App\Models\Invoice;
use App\Services\InvoiceApprovalService;
use Illuminate\Support\Facades\Log;

class InvoiceApprovalDecisionListener
{
    public function __construct(
        private InvoiceApprovalService $approvalService
    ) {}

    public function handle(ApprovalDecisionEvent $event): void
    {
        $approvalRequest = $event->approvalRequest;

        if ($approvalRequest->subject_type !== Invoice::class) {
            return;
        }

        $invoice = Invoice::find($approvalRequest->subject_id);
        if (! $invoice) {
            Log::warning('InvoiceApprovalDecisionListener: invoice not found', [
                'approval_request_id' => $approvalRequest->id,
                'subject_id' => $approvalRequest->subject_id,
            ]);

            return;
        }

        try {
            match ($event->action) {
                ApprovalDecisionEvent::ACTION_APPROVED => $this->approvalService->onApproved($approvalRequest),
                ApprovalDecisionEvent::ACTION_REJECTED => $this->approvalService->onRejected($approvalRequest, $event->reason ?? 'No reason provided'),
                ApprovalDecisionEvent::ACTION_EXPIRED => $this->approvalService->onExpired($approvalRequest),
                ApprovalDecisionEvent::ACTION_WITHDRAWN => $this->approvalService->onWithdrawn($approvalRequest, $event->actor),
            };
        } catch (\Exception $e) {
            Log::error('InvoiceApprovalDecisionListener: callback failed', [
                'invoice_id' => $invoice->id,
                'action' => $event->action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
