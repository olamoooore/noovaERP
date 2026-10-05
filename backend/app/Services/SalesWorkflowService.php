<?php

namespace App\Services;

use App\Models\DeliveryOrder;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Platform\Workflow\Contracts\WorkflowSubject;
use App\Platform\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Services\SalesOrderService;

/**
 * SalesWorkflowService — the Sales domain's registration of document status
 * schemas with the shared WorkflowEngine (#900, PC6).
 *
 * Every sales status transition is declared here once: allowed from/to pairs,
 * guards (permission / approval / period-lock) and side-effect handlers that
 * wrap the EXISTING services (reversal + stock return, linked order
 * cancellation, …). Controllers and services call transition() instead of
 * writing `status` literals.
 */
class SalesWorkflowService
{
    public const INVOICE = 'invoice';

    public const QUOTATION = 'quotation';

    public const SALES_ORDER = 'sales_order';

    public const DELIVERY_ORDER = 'delivery_order';

    public const SALES_ORDER_RECURRENCE = 'sales_order_recurrence';

    private bool $registered = false;

    public function __construct(private WorkflowEngine $engine)
    {
        $this->register();
    }

    /**
     * Register every Sales document schema with the engine. Idempotent.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registerInvoiceSchema();
        $this->registerQuotationSchema();
        $this->registerSalesOrderSchema();
        $this->registerDeliveryOrderSchema();
        $this->registerRecurrenceSchema();

        $this->registered = true;
    }

    /**
     * Apply a sales transition through the engine.
     *
     * @return array{success: bool, old_status: string, new_status: string|null, reason: string|null}
     */
    public function transition(WorkflowSubject $subject, string $action, array $context = [], ?string $reason = null): array
    {
        return $this->engine->transitionSubject($subject, $action, $context, $reason);
    }

    /**
     * @return array{allowed: bool, reason: string|null}
     */
    public function can(WorkflowSubject $subject, string $action, array $context = []): array
    {
        return $this->engine->canTransitionSubject($subject, $action, $context);
    }

    /**
     * Record a newly created sales document on the transition log.
     *
     * @return array{success: bool, new_status: string, reason: string|null}
     */
    public function initialize(WorkflowSubject $subject, array $context = []): array
    {
        return $this->engine->initializeSubject($subject, $context);
    }

    // ─── Schemas ──────────────────────────────────────────────────

    private function registerInvoiceSchema(): void
    {
        $this->engine->defineSchema(self::INVOICE, 'draft', [
            [
                'from' => 'draft',
                'to' => 'posted',
                'action' => 'post',
                'label' => 'Post to ledger',
                'permission' => 'accounting.post',
                'guards' => [$this->invoiceApprovalGuard(), $this->periodLockGuard()],
            ],
            [
                'from' => 'sent',
                'to' => 'posted',
                'action' => 'post',
                'label' => 'Post to ledger',
                'permission' => 'accounting.post',
                'guards' => [$this->invoiceApprovalGuard(), $this->periodLockGuard()],
            ],
            [
                'from' => 'posted',
                'to' => 'cancelled',
                'action' => 'cancel',
                'label' => 'Cancel invoice',
                'permission' => 'accounting.post',
            ],
            [
                'from' => 'sent',
                'to' => 'cancelled',
                'action' => 'cancel',
                'label' => 'Cancel invoice',
                'permission' => 'accounting.post',
            ],
            [
                'from' => 'partial',
                'to' => 'cancelled',
                'action' => 'cancel',
                'label' => 'Cancel invoice',
                'permission' => 'accounting.post',
            ],
            [
                'from' => 'overdue',
                'to' => 'cancelled',
                'action' => 'cancel',
                'label' => 'Cancel invoice',
                'permission' => 'accounting.post',
            ],
            [
                'from' => '*',
                'to' => 'draft',
                'action' => 'reset_to_draft',
                'label' => 'Reset to draft',
                'permission' => 'documents.reset_to_draft',
                'handler' => $this->invoiceResetToDraftHandler(),
            ],
            // Derived settlement states (payment sync). These never touch the
            // posting lifecycle — settlement_status remains the dimension that
            // drives the display status (ADR-005).
            [
                'from' => '*',
                'to' => 'sent',
                'action' => 'sync_sent',
                'label' => 'Settlement sync',
            ],
            [
                'from' => '*',
                'to' => 'partial',
                'action' => 'sync_partial',
                'label' => 'Settlement sync',
            ],
            [
                'from' => '*',
                'to' => 'paid',
                'action' => 'sync_paid',
                'label' => 'Settlement sync',
            ],
            [
                'from' => '*',
                'to' => 'overdue',
                'action' => 'sync_overdue',
                'label' => 'Settlement sync',
            ],
            // #879 (SA6): full write-off is a settlement state like paid —
            // recorded through the engine so no literal status write remains.
            [
                'from' => '*',
                'to' => 'written_off',
                'action' => 'sync_written_off',
                'label' => 'Settlement sync',
            ],
        ], 'Customer invoice: draft → posted → cancelled, with settlement sync.');
    }

    private function registerQuotationSchema(): void
    {
        $cancelLinkedOrder = function (WorkflowSubject $subject): ?string {
            if (! $subject instanceof Quotation) {
                return null;
            }

            $order = $subject->salesOrder;

            if ($order && $order->status !== 'cancelled') {
                app(SalesOrderService::class)->cancelOrder(
                    $order,
                    "Quotation {$subject->quotation_number} cancelled",
                );
            }

            return null;
        };

        $this->engine->defineSchema(self::QUOTATION, 'draft', [
            [
                'from' => 'draft',
                'to' => 'confirmed',
                'action' => 'confirm',
                'label' => 'Confirm quotation',
                'permission' => 'quotations.write',
            ],
            [
                'from' => 'sent',
                'to' => 'confirmed',
                'action' => 'confirm',
                'label' => 'Confirm quotation',
                'permission' => 'quotations.write',
            ],
            [
                'from' => 'draft',
                'to' => 'cancelled',
                'action' => 'cancel',
                'label' => 'Cancel quotation',
                'permission' => 'quotations.write',
                'handler' => $cancelLinkedOrder,
            ],
            [
                'from' => 'sent',
                'to' => 'cancelled',
                'action' => 'cancel',
                'label' => 'Cancel quotation',
                'permission' => 'quotations.write',
                'handler' => $cancelLinkedOrder,
            ],
            [
                'from' => 'confirmed',
                'to' => 'cancelled',
                'action' => 'cancel',
                'label' => 'Cancel quotation',
                'permission' => 'quotations.write',
                'handler' => $cancelLinkedOrder,
            ],
            [
                'from' => '*',
                'to' => 'draft',
                'action' => 'reset_to_draft',
                'label' => 'Reset to draft',
                'permission' => 'documents.reset_to_draft',
                'handler' => function (WorkflowSubject $subject): ?string {
                    if (! $subject instanceof Quotation) {
                        return null;
                    }

                    $order = $subject->salesOrder;

                    if ($order && ! $order->isCancelled()) {
                        app(SalesOrderService::class)->cancelOrder(
                            $order,
                            "Quotation {$subject->quotation_number} reset to draft",
                        );
                    }

                    return null;
                },
            ],
        ], 'Customer quotation: draft → sent → confirmed/cancelled.');
    }

    private function registerSalesOrderSchema(): void
    {
        // Mirrors OrderLifecycleService::TRANSITIONS (issue #141/#378) — the
        // service keeps its existing guards and status history; the engine owns
        // the allowed edges and the audit trail.
        $edges = [
            ['draft', 'confirmed', 'confirm', 'Confirm order'],
            ['confirmed', 'allocated', 'allocate', 'Allocate stock'],
            ['allocated', 'fulfilled', 'fulfil', 'Mark fulfilled'],
            ['confirmed', 'invoiced', 'mark_invoiced', 'Mark invoiced'],
            ['allocated', 'invoiced', 'mark_invoiced', 'Mark invoiced'],
            ['fulfilled', 'invoiced', 'mark_invoiced', 'Mark invoiced'],
            ['invoiced', 'invoiced', 'mark_invoiced', 'Mark invoiced'],
            ['draft', 'cancelled', 'cancel', 'Cancel order'],
            ['confirmed', 'cancelled', 'cancel', 'Cancel order'],
            ['allocated', 'cancelled', 'cancel', 'Cancel order'],
            ['fulfilled', 'cancelled', 'cancel', 'Cancel order'],
            ['cancelled', 'draft', 'reset_to_draft', 'Reset to draft'],
        ];

        $transitions = array_map(fn (array $edge) => [
            'from' => $edge[0],
            'to' => $edge[1],
            'action' => $edge[2],
            'label' => $edge[3],
            'permission' => $edge[2] === 'reset_to_draft' ? 'documents.reset_to_draft' : null,
        ], $edges);

        $this->engine->defineSchema(self::SALES_ORDER, 'draft', $transitions, 'Sales order lifecycle (issue #141/#378).');
    }

    private function registerDeliveryOrderSchema(): void
    {
        $approvalGuard = function (WorkflowSubject $subject): ?string {
            if (! $subject instanceof DeliveryOrder) {
                return null;
            }

            if ($subject->requires_approval && $subject->approval_status !== 'approved') {
                return 'This delivery order requires approval before it can be confirmed.';
            }

            return null;
        };

        $this->engine->defineSchema(self::DELIVERY_ORDER, 'draft', [
            [
                'from' => 'draft',
                'to' => 'confirmed',
                'action' => 'confirm',
                'label' => 'Confirm delivery order',
                'guards' => [$approvalGuard],
            ],
            [
                'from' => 'draft',
                'to' => 'done',
                'action' => 'complete',
                'label' => 'Complete delivery order',
            ],
            [
                'from' => 'confirmed',
                'to' => 'done',
                'action' => 'complete',
                'label' => 'Complete delivery order',
            ],
            [
                'from' => 'confirmed',
                'to' => 'cancelled',
                'action' => 'reverse',
                'label' => 'Reverse delivery order',
            ],
            [
                'from' => 'done',
                'to' => 'cancelled',
                'action' => 'reverse',
                'label' => 'Reverse delivery order',
            ],
            [
                'from' => 'draft',
                'to' => 'cancelled',
                'action' => 'cancel',
                'label' => 'Cancel delivery order',
            ],
            [
                'from' => 'confirmed',
                'to' => 'cancelled',
                'action' => 'cancel',
                'label' => 'Cancel delivery order',
            ],
            [
                'from' => 'cancelled',
                'to' => 'draft',
                'action' => 'reset_to_draft',
                'label' => 'Reset to draft',
                'permission' => 'documents.reset_to_draft',
            ],
            [
                'from' => 'done',
                'to' => 'draft',
                'action' => 'reset_to_draft',
                'label' => 'Reset to draft',
                'permission' => 'documents.reset_to_draft',
            ],
            [
                'from' => 'draft',
                'to' => 'draft',
                'action' => 'reject',
                'label' => 'Reject approval (back to draft)',
            ],
        ], 'Delivery order: draft → confirmed → done / cancelled.');
    }

    private function registerRecurrenceSchema(): void
    {
        $this->engine->defineSchema(self::SALES_ORDER_RECURRENCE, 'active', [
            ['from' => 'active', 'to' => 'paused', 'action' => 'pause', 'label' => 'Pause recurrence'],
            ['from' => 'paused', 'to' => 'active', 'action' => 'resume', 'label' => 'Resume recurrence'],
            ['from' => 'active', 'to' => 'cancelled', 'action' => 'cancel', 'label' => 'Cancel recurrence'],
            ['from' => 'paused', 'to' => 'cancelled', 'action' => 'cancel', 'label' => 'Cancel recurrence'],
            ['from' => 'active', 'to' => 'completed', 'action' => 'complete', 'label' => 'Complete recurrence'],
        ], 'Recurring sales order schedule: active → paused → cancelled/completed.');
    }

    // ─── Guards ───────────────────────────────────────────────────

    private function invoiceApprovalGuard(): callable
    {
        return function (WorkflowSubject $subject): ?string {
            if (! $subject instanceof Invoice || ! $subject->requires_approval) {
                return null;
            }

            $guard = app(InvoiceApprovalService::class)->checkGuard($subject);

            return ($guard['blocked'] ?? false)
                ? ($guard['message'] ?? 'Invoice requires approval before posting.')
                : null;
        };
    }

    private function periodLockGuard(): callable
    {
        return function (WorkflowSubject $subject): ?string {
            if (! $subject instanceof Invoice || ! $subject->invoice_date) {
                return null;
            }

            if (! Schema::hasTable('lock_dates') && ! Schema::hasTable('company_settings')) {
                return null;
            }

            $result = app(LockDateValidationService::class)
                ->validateTransactionDate('sales', $subject->invoice_date);

            return ($result['is_locked'] ?? false)
                ? ($result['message'] ?? 'The invoice date falls in a locked period.')
                : null;
        };
    }

    // ─── Handlers (wrap existing services) ────────────────────────

    private function invoiceResetToDraftHandler(): callable
    {
        return function (WorkflowSubject $subject): void {
            if (! $subject instanceof Invoice) {
                return;
            }

            $cancellation = app(InvoiceCancellationService::class);

            if ($subject->journal_entry_id) {
                $cancellation->reverseJournalEntry(
                    $subject,
                    "Reset to draft: Invoice #{$subject->invoice_number}",
                    'RESET_TO_DRAFT',
                );
            }

            $cancellation->returnIssuedStockOnReset($subject);
        };
    }
}
