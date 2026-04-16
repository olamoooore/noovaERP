<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use App\SuperAdmin\Domain\SubscriptionInvoice;
use App\SuperAdmin\Domain\TenantOperation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectionsService
{
    // Ordered lifecycle stages
    public const STAGES = [
        'reminder',
        'warning',
        'final_notice',
        'suspended',
    ];

    public function __construct(
        private readonly AdminAuditLogger $auditLogger,
        private readonly TenantOperationService $operationService,
    ) {}

    /**
     * Return overdue invoices (past due date, unpaid) or any invoice in an active collection stage.
     * Collections tracks individual invoices, not subscriptions — a subscription can have
     * multiple invoices in different states.
     */
    public function listAtRisk(): Collection
    {
        return SubscriptionInvoice::with(['subscription.planVersion.family'])
            ->where(function ($q) {
                $q->where(function ($overdue) {
                    // Unpaid and past due date
                    $overdue->whereNotIn('status', ['paid', 'void'])
                            ->where('due_date', '<', now());
                })->orWhereNotNull('collection_stage');
            })
            ->orderBy('collection_stage_updated_at')
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Advance the collection lifecycle for a specific invoice.
     * Lifecycle: null → reminder → warning → final_notice → suspended
     */
    public function advanceStage(SubscriptionInvoice $invoice, ?string $note = null): SubscriptionInvoice
    {
        $currentStage = $invoice->collection_stage;

        if ($currentStage === 'suspended') {
            throw ValidationException::withMessages([
                'stage' => 'This invoice is already at the suspended stage.',
            ]);
        }

        $nextStage = $this->nextStage($currentStage);

        // Idempotency guard — already at target stage
        if ($nextStage === $currentStage) {
            throw ValidationException::withMessages([
                'stage' => "Invoice is already at the [{$currentStage}] stage.",
            ]);
        }

        $before = [
            'collection_stage' => $currentStage,
            'invoice_status'   => $invoice->status,
        ];

        DB::transaction(function () use ($invoice, $nextStage, $note) {
            $invoice->update([
                'collection_stage'            => $nextStage,
                'collection_stage_updated_at' => now(),
                'collection_notes'            => $note,
            ]);

            // Auto-suspend the tenant when an invoice reaches suspended stage
            if ($nextStage === 'suspended') {
                $tenantId = $invoice->tenant_id;
                $this->operationService->suspend(
                    $tenantId,
                    "Invoice #{$invoice->invoice_number} escalated to suspension."
                );

                TenantOperation::create([
                    'tenant_id'     => $tenantId,
                    'admin_user_id' => Auth::id(),
                    'operation'     => 'collection_suspend',
                    'payload'       => ['invoice_id' => $invoice->id, 'collection_note' => $note],
                    'result'        => ['collection_stage' => 'suspended'],
                    'status'        => 'success',
                    'created_at'    => now(),
                ]);
            }
        });

        $this->auditLogger->log(
            action: 'collection.stage_advanced',
            target: $invoice,
            before: $before,
            after: ['collection_stage' => $nextStage],
            reason: $note,
            status: 'success',
        );

        return $invoice->fresh();
    }

    /**
     * Clear the collection stage — invoice is resolved (paid or arrangement made).
     */
    public function clear(SubscriptionInvoice $invoice, ?string $note = null): SubscriptionInvoice
    {
        $before = [
            'collection_stage' => $invoice->collection_stage,
            'invoice_status'   => $invoice->status,
        ];

        $wasAtSuspended = $invoice->collection_stage === 'suspended';

        $invoice->update([
            'collection_stage'            => null,
            'collection_stage_updated_at' => now(),
            'collection_notes'            => $note,
        ]);

        // Reinstate the tenant if this invoice caused the suspension
        if ($wasAtSuspended) {
            $this->operationService->reinstate(
                $invoice->tenant_id,
                "Invoice #{$invoice->invoice_number} collection cleared — account reinstated."
            );
        }

        $this->auditLogger->log(
            action: 'collection.cleared',
            target: $invoice,
            before: $before,
            after: ['collection_stage' => null],
            reason: $note,
            status: 'success',
        );

        return $invoice->fresh();
    }

    private function nextStage(?string $current): string
    {
        if ($current === null) {
            return 'reminder';
        }

        $idx = array_search($current, self::STAGES, true);

        if ($idx === false) {
            return 'reminder';
        }

        return self::STAGES[min($idx + 1, count(self::STAGES) - 1)];
    }
}
