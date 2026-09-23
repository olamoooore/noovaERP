<?php

namespace App\Services;

use App\Accounting\Events\ApprovalDecisionEvent;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Platform\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Modules\Communication\Events\BusinessEvent;

class InvoiceApprovalService
{
    public const REQUEST_TYPE = 'invoice_approval';

    public function __construct(
        private InvoiceActivityService $activityLog,
        private NotificationService $notificationService,
    ) {}

    public function submit(Invoice $invoice, User $user, ?string $reason = null): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw new \InvalidArgumentException("Invoice must be in draft status to submit for approval. Current: {$invoice->status}");
        }

        if (! $invoice->requires_approval) {
            throw new \InvalidArgumentException('Invoice does not require approval. Set requires_approval=true before submitting.');
        }

        if ($invoice->approval_status === Invoice::APPROVAL_PENDING) {
            throw new \InvalidArgumentException('Invoice is already pending approval.');
        }

        if (! $invoice->lines()->exists()) {
            throw new \InvalidArgumentException('Invoice must have at least one line item before submitting for approval.');
        }

        $invoice->calculateTotals();

        $metadata = [
            'invoice_number' => $invoice->invoice_number,
            'customer_name' => $invoice->customer?->name,
            'total' => $invoice->total,
            'currency' => $invoice->currency_code,
            'requires_evidence' => $this->evidenceIsRequired($invoice),
        ];

        $approvalRequest = DB::transaction(function () use ($invoice, $user, $reason, $metadata) {
            $request = ApprovalRequest::create([
                'request_type' => self::REQUEST_TYPE,
                'subject_type' => Invoice::class,
                'subject_id' => $invoice->id,
                'amount' => $invoice->total,
                'reason' => $reason ?? "Invoice {$invoice->invoice_number} requires approval before posting.",
                'notes' => null,
                'metadata' => $metadata,
                'requested_by' => $user->id,
                'requested_at' => now(),
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
            ]);

            $invoice->update([
                'approval_status' => Invoice::APPROVAL_PENDING,
                'approval_request_id' => $request->id,
            ]);

            return $request;
        });

        $this->activityLog->log(
            $invoice,
            \App\Models\InvoiceActivityLog::EVENT_SUBMITTED,
            [
                'approval_request_id' => $approvalRequest->id,
                'approval_request_number' => $approvalRequest->request_number,
                'submitted_by' => $user->id,
                'reason' => $reason,
            ],
            $invoice->getOriginal('approval_status') ?? Invoice::APPROVAL_NOT_REQUIRED,
            Invoice::APPROVAL_PENDING,
            \App\Models\InvoiceActivityLog::ACTOR_USER,
            $user->id,
        );

        Log::info('Invoice submitted for approval', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'approval_request_id' => $approvalRequest->id,
            'submitted_by' => $user->id,
        ]);

        $this->notifyApprovers($invoice, $approvalRequest, $user);

        // Issue #84: route this domain event through the platform's
        // Communication module so the approval-submission appears in chatter
        // and any subscriber preferences (in-app, email, etc.) are honoured.
        Event::dispatch(new BusinessEvent(
            model: 'invoice',
            recordId: $invoice->id,
            event: 'invoice.approval_submitted',
            subject: "Invoice {$invoice->invoice_number} submitted for approval",
            body: "Invoice {$invoice->invoice_number} (total {$invoice->total} {$invoice->currency_code}) was submitted for approval by user #{$user->id}.",
            authorId: $user->id,
            authorType: 'user',
            tenantId: $invoice->tenant_id ?? null,
            context: [
                'invoice_number' => $invoice->invoice_number,
                'approval_request_id' => $approvalRequest->id,
                'approval_request_number' => $approvalRequest->request_number,
            ],
            metadata: [
                'source' => 'InvoiceApprovalService::submit',
            ],
        ));

        return $invoice->fresh();
    }

    public function withdraw(Invoice $invoice, User $user, ?string $reason = null): Invoice
    {
        $validStatuses = [Invoice::APPROVAL_PENDING, Invoice::APPROVAL_APPROVED];

        if (! in_array($invoice->approval_status, $validStatuses, true)) {
            throw new \InvalidArgumentException(
                "Invoice approval cannot be withdrawn. Current status: {$invoice->approval_status}"
            );
        }

        $approvalRequest = $invoice->approvalRequest;
        if (! $approvalRequest) {
            throw new \InvalidArgumentException('No active approval request found for this invoice.');
        }

        $fromStatus = $invoice->approval_status;

        DB::transaction(function () use ($approvalRequest, $reason) {
            $approvalRequest->update([
                'status' => 'withdrawn',
                'review_notes' => $reason,
                'reviewed_at' => now(),
                'reviewed_by' => $user->id,
            ]);
        });

        ApprovalDecisionEvent::dispatch($approvalRequest, ApprovalDecisionEvent::ACTION_WITHDRAWN, $user, $reason);

        Log::info('Invoice approval withdrawal initiated', [
            'invoice_id' => $invoice->id,
            'previous_status' => $fromStatus,
            'withdrawn_by' => $user->id,
        ]);

        return $invoice->fresh();
    }

    public function onApproved(ApprovalRequest $approvalRequest): void
    {
        $invoice = Invoice::find($approvalRequest->subject_id);
        if (! $invoice) {
            Log::warning('InvoiceApprovalService: approval subject not found', [
                'approval_request_id' => $approvalRequest->id,
            ]);

            return;
        }

        if ($invoice->approval_status !== Invoice::APPROVAL_PENDING) {
            Log::info('InvoiceApprovalService: invoice not pending, skipping approval sync', [
                'invoice_id' => $invoice->id,
                'approval_status' => $invoice->approval_status,
            ]);

            return;
        }

        DB::transaction(function () use ($invoice) {
            $invoice->update([
                'approval_status' => Invoice::APPROVAL_APPROVED,
            ]);
        });

        $this->activityLog->log(
            $invoice,
            \App\Models\InvoiceActivityLog::EVENT_APPROVED,
            [
                'approval_request_id' => $approvalRequest->id,
                'reviewed_by' => $approvalRequest->reviewed_by,
                'review_notes' => $approvalRequest->review_notes,
            ],
            Invoice::APPROVAL_PENDING,
            Invoice::APPROVAL_APPROVED,
            \App\Models\InvoiceActivityLog::ACTOR_USER,
            $approvalRequest->reviewed_by,
        );

        Log::info('Invoice approval status updated to approved', [
            'invoice_id' => $invoice->id,
            'approval_request_id' => $approvalRequest->id,
        ]);

        $this->notifyRequester($approvalRequest, 'approval.approved', [
            'type' => 'Invoice Approval',
            'invoice_number' => $invoice->invoice_number,
            'approved_by' => $approvalRequest->reviewedBy?->name ?? 'Unknown',
            'invoice_id' => $invoice->id,
        ]);
    }

    public function onRejected(ApprovalRequest $approvalRequest, string $reason): void
    {
        $invoice = Invoice::find($approvalRequest->subject_id);
        if (! $invoice) {
            Log::warning('InvoiceApprovalService: approval subject not found for rejection', [
                'approval_request_id' => $approvalRequest->id,
            ]);

            return;
        }

        if ($invoice->approval_status !== Invoice::APPROVAL_PENDING) {
            return;
        }

        DB::transaction(function () use ($invoice) {
            // A rejection returns the invoice to its draft state so the
            // submitter can amend and resubmit. We keep a distinct `rejected`
            // approval_status (rather than resetting to `not_required`) so the
            // list and detail views can show "Approval Rejected" unambiguously.
            // The decision itself is also kept on the ApprovalRequest row and in
            // the activity log, so nothing is lost from the audit trail.
            $invoice->update([
                'approval_status' => Invoice::APPROVAL_REJECTED,
                'approval_request_id' => null,
            ]);
        });

        $this->activityLog->log(
            $invoice,
            \App\Models\InvoiceActivityLog::EVENT_REJECTED,
            [
                'approval_request_id' => $approvalRequest->id,
                'reviewed_by' => $approvalRequest->reviewed_by,
                'reason' => $reason,
                'returned_to' => 'draft',
            ],
            Invoice::APPROVAL_PENDING,
            Invoice::APPROVAL_NOT_REQUIRED,
            \App\Models\InvoiceActivityLog::ACTOR_USER,
            $approvalRequest->reviewed_by,
        );

        Log::info('Invoice approval rejected', [
            'invoice_id' => $invoice->id,
            'approval_request_id' => $approvalRequest->id,
            'reason' => $reason,
        ]);

        $this->notifyRequester($approvalRequest, 'approval.rejected', [
            'type' => 'Invoice Approval',
            'reason' => $reason,
            'invoice_number' => $invoice->invoice_number,
            'invoice_id' => $invoice->id,
        ]);
    }

    public function onExpired(ApprovalRequest $approvalRequest): void
    {
        $invoice = Invoice::find($approvalRequest->subject_id);
        if (! $invoice) {
            return;
        }

        if ($invoice->approval_status !== Invoice::APPROVAL_PENDING) {
            return;
        }

        DB::transaction(function () use ($invoice) {
            $invoice->update([
                'approval_status' => Invoice::APPROVAL_EXPIRED,
            ]);
        });

        $this->activityLog->log(
            $invoice,
            \App\Models\InvoiceActivityLog::EVENT_EXPIRED,
            [
                'approval_request_id' => $approvalRequest->id,
                'expired_at' => $approvalRequest->expires_at?->toIso8601String(),
            ],
            Invoice::APPROVAL_PENDING,
            Invoice::APPROVAL_EXPIRED,
            \App\Models\InvoiceActivityLog::ACTOR_SYSTEM,
            null,
        );

        Log::info('Invoice approval expired', [
            'invoice_id' => $invoice->id,
            'approval_request_id' => $approvalRequest->id,
        ]);

        $this->notifyRequester($approvalRequest, 'approval.rejected', [
            'type' => 'Invoice Approval',
            'reason' => 'Approval request has expired.',
            'invoice_number' => $invoice->invoice_number,
            'invoice_id' => $invoice->id,
        ]);
    }

    public function onWithdrawn(ApprovalRequest $approvalRequest, ?User $actor = null): void
    {
        $invoice = Invoice::find($approvalRequest->subject_id);
        if (! $invoice) {
            return;
        }

        $previousStatus = $invoice->approval_status;

        DB::transaction(function () use ($invoice) {
            $invoice->update([
                'approval_status' => Invoice::APPROVAL_WITHDRAWN,
                'approval_request_id' => null,
            ]);
        });

        $this->activityLog->log(
            $invoice,
            \App\Models\InvoiceActivityLog::EVENT_WITHDRAWN,
            [
                'approval_request_id' => $approvalRequest->id,
                'previous_approval_status' => $previousStatus,
                'reason' => $approvalRequest->review_notes,
            ],
            $previousStatus,
            Invoice::APPROVAL_WITHDRAWN,
            \App\Models\InvoiceActivityLog::ACTOR_USER,
            $actor?->id ?? $approvalRequest->reviewed_by,
        );

        $this->notifyRequester($approvalRequest, 'approval.rejected', [
            'type' => 'Invoice Approval',
            'reason' => $approvalRequest->review_notes ?? 'Approval withdrawn by issuer.',
            'invoice_number' => $invoice->invoice_number,
            'invoice_id' => $invoice->id,
        ]);
    }

    public function checkGuard(Invoice $invoice): array
    {
        if ($invoice->requires_approval) {
            $blockedStatuses = [
                Invoice::APPROVAL_PENDING,
                Invoice::APPROVAL_REJECTED,
                Invoice::APPROVAL_EXPIRED,
                Invoice::APPROVAL_WITHDRAWN,
            ];

            if (in_array($invoice->approval_status, $blockedStatuses, true)) {
                return [
                    'blocked' => true,
                    'code' => 'APPROVAL_REQUIRED',
                    'message' => "Invoice requires approval before posting. Current approval status: {$invoice->approval_status}",
                    'approval_status' => $invoice->approval_status,
                ];
            }

            if ($invoice->approval_status !== Invoice::APPROVAL_APPROVED) {
                return [
                    'blocked' => true,
                    'code' => 'APPROVAL_REQUIRED',
                    'message' => "Invoice approval has not been granted. Status: {$invoice->approval_status}",
                    'approval_status' => $invoice->approval_status,
                ];
            }
        }

        return ['blocked' => false];
    }

    public function evidenceIsRequired(Invoice $invoice): bool
    {
        if (! $invoice->requires_approval) {
            return false;
        }

        return true;
    }

    public function syncFromApprovalRequest(ApprovalRequest $approvalRequest): void
    {
        match ($approvalRequest->status) {
            'approved' => $this->onApproved($approvalRequest),
            'rejected' => $this->onRejected($approvalRequest, $approvalRequest->review_notes ?? 'No reason provided'),
            'expired' => $this->onExpired($approvalRequest),
            'withdrawn' => $this->onWithdrawn($approvalRequest),
            default => null,
        };
    }

    private function notifyApprovers(Invoice $invoice, ApprovalRequest $approvalRequest, User $submitter): void
    {
        try {
            $approvers = $this->resolveReviewers($submitter);

            foreach ($approvers as $approver) {
                $this->notificationService->dispatch('approval.requested', $approver, [
                    'subject' => "Invoice {$invoice->invoice_number} requires approval",
                    'body' => "Invoice {$invoice->invoice_number} for {$invoice->currency_code} ".number_format($invoice->total, 2).' requires your approval.',
                    'type' => 'invoice_approval',
                    'amount' => $invoice->total,
                    'reason' => $approvalRequest->reason,
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'requested_by' => $submitter->name,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to notify approvers for invoice approval', [
                'invoice_id' => $invoice->id,
                'approval_request_id' => $approvalRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Users who should review invoice approvals.
     *
     * Prefers the reviewers configured in Sales Settings → Approval Workflow
     * (specific people first, then everyone holding one of the chosen roles).
     * Falls back to the `invoices.approve` permission when nothing is
     * configured, so existing tenants keep working unchanged. The submitter is
     * always excluded (no self-approval).
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function resolveReviewers(?User $submitter = null): \Illuminate\Support\Collection
    {
        $submitterId = $submitter?->id;

        $users = collect();

        // ── Explicit users ────────────────────────────────────────────────
        try {
            $configuredIds = Setting::getValue('sales.invoice.reviewer_user_ids', []);

            if (is_array($configuredIds) && $configuredIds !== []) {
                $users = $users->merge(
                    User::whereIn('id', array_map('intval', $configuredIds))->get()
                );
            }
        } catch (\Throwable $e) {
            Log::warning('InvoiceApprovalService: failed to resolve explicit reviewers', [
                'error' => $e->getMessage(),
            ]);
        }

        // ── Role-based reviewers ──────────────────────────────────────────
        // Settings store lowercase role slugs (owner, admin, sales, …) but the
        // Spatie role names are Title Case ("Owner", "Sales", …). Passing the
        // slug straight into User::role() throws RoleDoesNotExist and aborted
        // the whole resolution — which silently dropped the *configured*
        // approvers and fell back to the invoices.approve permission instead.
        try {
            $configuredRoles = Setting::getValue('sales.invoice.reviewer_roles', []);

            if (is_array($configuredRoles) && $configuredRoles !== []) {
                $spatieNames = collect($configuredRoles)
                    ->map(fn ($role) => \App\Enums\UserRole::tryFrom(strtolower((string) $role))?->spatieName())
                    ->filter()
                    ->values()
                    ->all();

                if ($spatieNames !== []) {
                    $users = $users->merge(
                        User::role($spatieNames)->get()
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('InvoiceApprovalService: failed to resolve role reviewers', [
                'error' => $e->getMessage(),
            ]);
        }

        $users = $users
            ->unique('id')
            ->reject(fn (User $u) => $submitterId !== null && $u->id === $submitterId)
            ->values();

        if ($users->isNotEmpty()) {
            return $users;
        }

        // Fallback: role/permission based approvers. Guarded because the
        // permission may not be seeded in a given tenant's schema — a missing
        // permission must not break invoice creation.
        try {
            $query = User::permission('invoices.approve');

            if ($submitterId !== null) {
                $query->where('id', '!=', $submitterId);
            }

            return $query->get();
        } catch (\Throwable $e) {
            Log::warning('InvoiceApprovalService: no reviewers resolved (permission fallback failed)', [
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    private function notifyRequester(ApprovalRequest $approvalRequest, string $event, array $data): void
    {
        try {
            $requester = User::find($approvalRequest->requested_by);
            if (! $requester) {
                return;
            }

            $this->notificationService->dispatch($event, $requester, $data);
        } catch (\Exception $e) {
            Log::warning('Failed to notify requester for approval event', [
                'approval_request_id' => $approvalRequest->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
