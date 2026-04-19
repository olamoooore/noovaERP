<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\SettlementAuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\InventoryService;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\Sales\Models\SalesOrder;

/**
 * Invoice Service - Handles invoice workflow
 * Auto-posts to accounting ledger
 * Updates inventory
 */
class InvoiceService
{
    public function __construct(
        private PostingEngineInterface $postingEngine,
        private InventoryService $inventoryService,
    ) {}

    /**
     * Create invoice with lines
     */
    public function createInvoice(array $data): Invoice
    {
        DB::beginTransaction();
        
        try {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $data['created_by'] = TenantUserHelper::requireTenantUserId($data['created_by'] ?? Auth::id());
            $data['invoice_date'] = $data['invoice_date'] ?? now();
            $data['due_date'] = $data['due_date'] ?? now()->addDays(30);
            $data['status'] = 'draft';

            $invoice = Invoice::create($data);

            // Add lines
            foreach ($lines as $lineData) {
                $invoice->lines()->create([
                    'product_id' => $lineData['product_id'] ?? null,
                    'description' => $lineData['description'],
                    'quantity' => $lineData['quantity'],
                    'unit_price' => $lineData['unit_price'],
                    'tax_rate' => $lineData['tax_rate'] ?? 0,
                    'discount_amount' => $lineData['discount_amount'] ?? 0,
                ]);
            }

            $invoice->refresh();
            $invoice->calculateTotals();

            DB::commit();
            
            return $invoice->load(['customer', 'lines.product']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Post invoice (creates journal entry with COGS, reduces inventory)
     */
    public function postInvoice(Invoice $invoice): Invoice
    {
        DB::beginTransaction();
        
        try {
            // Re-fetch with lock to prevent concurrent posting
            $invoice = Invoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status !== 'draft') {
                throw new \Exception('Only draft invoices can be posted');
            }
            // ── Build posting lines ──────────────────────────
            $lines = [];

            // DR Accounts Receivable (total)
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'accounts_receivable',
                accountId: null,
                debit: (float) $invoice->total,
                credit: 0,
                description: "Invoice {$invoice->invoice_number}",
            );

            // CR Sales Revenue (subtotal)
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'sales_revenue',
                accountId: null,
                debit: 0,
                credit: (float) $invoice->subtotal,
                description: "Sales - Invoice {$invoice->invoice_number}",
            );

            // CR Sales Tax Payable
            if ((float) $invoice->tax_amount > 0) {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'sales_tax_payable',
                    accountId: null,
                    debit: 0,
                    credit: (float) $invoice->tax_amount,
                    description: "Tax - Invoice {$invoice->invoice_number}",
                );
            }

            // ── COGS lines for inventory items ───────────────
            $totalCogs = 0;
            foreach ($invoice->lines as $line) {
                if ($line->product && $line->product->track_inventory) {
                    $unitCost = (float) ($line->product->cost ?? 0);
                    $lineCogs = $unitCost * $line->quantity;

                    if ($lineCogs > 0) {
                        $totalCogs += $lineCogs;
                    }
                }
            }

            if ($totalCogs > 0) {
                // DR Cost of Goods Sold
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'cogs',
                    accountId: null,
                    debit: $totalCogs,
                    credit: 0,
                    description: "COGS - Invoice {$invoice->invoice_number}",
                );

                // CR Inventory
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'inventory',
                    accountId: null,
                    debit: 0,
                    credit: $totalCogs,
                    description: "Inventory - Invoice {$invoice->invoice_number}",
                );
            }

            // ── Post via PostingEngine ───────────────────────
            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'invoice',
                documentId: $invoice->id,
                postingDate: $invoice->invoice_date->toDateString(),
                description: "Invoice #{$invoice->invoice_number} - {$invoice->customer->name}",
                lines: $lines,
                reference: $invoice->invoice_number,
                userId: TenantUserHelper::requireTenantUserId($invoice->created_by),
            ));

            if (!$result->success) {
                throw new \Exception($result->error);
            }

            // Status transitions to 'posted' (accounting released to GL).
            // The operational 'sent' status is set separately via InvoiceController::send().
            $invoice->update([
                'status'           => 'posted',
                'journal_entry_id' => $result->journalEntryId,
            ]);

            $this->synchronizeSalesOrderPosting($invoice->fresh(['salesOrder', 'lines']));

            // Reduce inventory for product lines via canonical InventoryService path.
            // Source-linked invoices must carry explicit warehouse context; do not inject defaults.
            $requiresInventoryMovement = $invoice->lines->contains(fn ($line) => $line->product && $line->product->track_inventory)
                && !$this->shouldSkipInventoryAdjustment($invoice);
            $warehouseId = $requiresInventoryMovement ? $this->resolveInventoryWarehouseId($invoice) : null;
            if ($requiresInventoryMovement && $warehouseId === null) {
                throw new \RuntimeException(
                    "Cannot post invoice {$invoice->invoice_number}: no inventory warehouse context available."
                );
            }

            if ($warehouseId !== null) {
                foreach ($invoice->lines as $line) {
                    if ($line->product && $line->product->track_inventory) {
                        $this->inventoryService->adjustStock(
                            productId:     $line->product_id,
                            warehouseId:   $warehouseId,
                            quantity:      $line->quantity,
                            type:          'sale',
                            reference:     $invoice->invoice_number,
                            referenceType: 'Invoice',
                            referenceId:   $invoice->id,
                        );
                    }
                }
            }

            // Update customer outstanding
            $invoice->customer->updateOutstanding();

            DB::commit();
            
            return $invoice->fresh(['journalEntry', 'lines']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Record payment against invoice
     */
    public function recordPayment(Invoice $invoice, array $paymentData): Payment
    {
        DB::beginTransaction();
        
        try {
            $paymentData['customer_id'] = $invoice->customer_id;
            $paymentData['invoice_id']  = $invoice->id;
            $paymentData['payment_date'] = $paymentData['payment_date'] ?? now();
            $paymentData['received_by'] = TenantUserHelper::requireTenantUserId($paymentData['received_by'] ?? Auth::id() ?? $invoice->created_by);
            // bank_account_id is passed through from the caller if provided

            $payment = Payment::create($paymentData);

            // Post to accounting ledger via PostingEngine (replaces AccountingService::postPayment)
            $lines = [];

            // Resolve the cash/bank GL account based on payment method + bank account.
            // bank_account_id (if provided) takes precedence and gives the exact GL account.
            // Otherwise fall back to a mapping key derived from the payment method.
            $bankAccountId  = $paymentData['bank_account_id'] ?? null;
            $bankGlAccountId = null;

            if ($bankAccountId) {
                $bankAccount = BankAccount::find($bankAccountId);
                $bankGlAccountId = $bankAccount?->gl_account_id;
            }

            $cashMappingKey = match ($payment->method ?? 'cash') {
                'bank_transfer' => 'bank',
                default         => 'cash',
            };

            // DR Cash/Bank
            $lines[] = new PostingRequestLine(
                accountMappingKey: $cashMappingKey,
                accountId: $bankGlAccountId,   // explicit GL account wins when bank account is set
                debit: (float) $payment->amount,
                credit: 0,
                description: "Payment {$payment->payment_number}",
            );

            // CR Accounts Receivable
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'accounts_receivable',
                accountId: null,
                debit: 0,
                credit: (float) $payment->amount,
                description: "Payment received - {$payment->payment_number}",
            );

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'payment',
                documentId: $payment->id,
                postingDate: $payment->payment_date instanceof \DateTimeInterface
                    ? $payment->payment_date->format('Y-m-d')
                    : (string) $payment->payment_date,
                description: "Payment #{$payment->payment_number} - {$invoice->customer->name}",
                lines: $lines,
                reference: $payment->payment_number,
                userId: TenantUserHelper::requireTenantUserId($payment->received_by),
            ));

            if (!$result->success) {
                throw new \Exception($result->error);
            }

            $payment->update([
                'journal_entry_id' => $result->journalEntryId,
            ]);

            // Update invoice paid amount and balance — single canonical path
            $this->syncInvoicePaymentState($invoice, (float) $payment->amount, $payment);

            DB::commit();
            
            return $payment->fresh('journalEntry');
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function resolveInventoryWarehouseId(Invoice $invoice): ?int
    {
        $posOrder = $invoice->posOrder()->with('terminal')->first();
        if ($posOrder?->terminal?->warehouse_id) {
            return (int) $posOrder->terminal->warehouse_id;
        }

        $webOrder = $invoice->webOrder()->first();
        $webWarehouseId = $webOrder?->resolveInventoryWarehouseId();
        if ($webWarehouseId) {
            return (int) $webWarehouseId;
        }

        $salesOrder = $invoice->salesOrder()->first();
        return $this->resolveSalesOrderWarehouseId($salesOrder);
    }

    private function resolveSalesOrderWarehouseId(?SalesOrder $salesOrder): ?int
    {
        if (!$salesOrder) {
            return null;
        }

        return match ($salesOrder->source_document_type) {
            'PosOrder' => (function () use ($salesOrder): ?int {
                $posOrder = \Modules\POS\Models\PosOrder::with('terminal')
                    ->find($salesOrder->source_document_id);

                return $posOrder?->terminal?->warehouse_id
                    ? (int) $posOrder->terminal->warehouse_id
                    : null;
            })(),
            'WebOrder' => (function () use ($salesOrder): ?int {
                $webOrder = \Modules\Ecommerce\Models\WebOrder::find($salesOrder->source_document_id);

                return $webOrder?->resolveInventoryWarehouseId();
            })(),
            default => null,
        };
    }

    private function synchronizeSalesOrderPosting(Invoice $invoice): void
    {
        $salesOrder = $invoice->salesOrder;
        if (!$salesOrder) {
            return;
        }

        $activeLinks = $salesOrder->invoiceLinks()->where('link_state', 'active')->pluck('invoice_id');

        $postedCount = Invoice::query()
            ->whereIn('id', $activeLinks)
            ->whereNotNull('journal_entry_id')
            ->count();

        $activeCount = $activeLinks->count();

        $accountingStatus = match (true) {
            $activeCount === 0, $postedCount === 0 => 'not_posted',
            $postedCount < $activeCount => 'partially_posted',
            default => 'fully_posted',
        };

        $salesOrder->forceFill([
            'accounting_status' => $accountingStatus,
        ])->saveQuietly();
    }

    private function shouldSkipInventoryAdjustment(Invoice $invoice): bool
    {
        $salesOrder = $invoice->salesOrder;

        return $salesOrder?->source_document_type === 'WorkOrder';
    }

    /**
     * Record a channel-originated payment against an invoice WITHOUT posting
     * a separate payment-side GL entry.
     *
     * Use this when the channel (POS / ecommerce webhook / FixStuckWebOrders)
     * has ALREADY posted GL via PosPostingService or WebOrderPostingService.
     * This creates the Payment row and atomically syncs the invoice balances.
     */
    public function recordChannelPayment(Invoice $invoice, array $paymentData): Payment
    {
        $paymentData['customer_id'] = $paymentData['customer_id'] ?? $invoice->customer_id;
        $paymentData['invoice_id']  = $invoice->id;
        $paymentData['payment_date'] = $paymentData['payment_date'] ?? now();
        $paymentData['received_by'] = TenantUserHelper::resolveTenantUserId($paymentData['received_by'] ?? $invoice->created_by);

        $payment = Payment::create($paymentData);

        $this->syncInvoicePaymentState($invoice->fresh(), (float) $payment->amount, $payment);

        return $payment;
    }

    /**
     * Apply a refund amount to an invoice using atomic operations.
     *
     * Reverses paid_amount, recalculates balance, and transitions status.
     * Does NOT create a separate GL reversal entry — callers that need one
     * should invoke PostingEngine::reverse() with a credit-memo document.
     */
    public function applyRefundToInvoice(Invoice $invoice, float $refundAmount): Invoice
    {
        Invoice::where('id', $invoice->id)
            ->increment('balance', $refundAmount);
        Invoice::where('id', $invoice->id)
            ->decrement('paid_amount', $refundAmount);

        $invoice->refresh();

        $status = match (true) {
            $invoice->paid_amount <= 0 => 'sent',
            $invoice->balance > 0 && $invoice->paid_amount > 0 => 'partial',
            default => $invoice->status,
        };

        Invoice::where('id', $invoice->id)->update(['status' => $status]);

        $invoice->refresh();

        if ($invoice->customer) {
            $invoice->customer->updateOutstanding();
        }

        return $invoice;
    }

    /**
     * Atomically sync invoice paid_amount / balance / status after a payment.
     *
     * This is the single canonical path for invoice balance reconciliation
     * — used by both recordPayment (which also posts GL) and
     * recordChannelPayment (which skips GL because the channel already posted).
     */
    private function syncInvoicePaymentState(Invoice $invoice, float $paymentAmount, ?Payment $payment = null): void
    {
        $beforeState = [
            'paid_amount' => $invoice->paid_amount,
            'balance' => $invoice->balance,
            'status' => $invoice->status,
        ];

        Invoice::where('id', $invoice->id)
            ->increment('paid_amount', $paymentAmount);

        $invoice->refresh();

        $newBalance = $invoice->total - $invoice->paid_amount;

        Invoice::where('id', $invoice->id)->update([
            'balance' => $newBalance,
            'status'  => $newBalance <= 0 ? 'paid' : ($invoice->paid_amount > 0 ? 'partial' : $invoice->status),
        ]);

        $invoice->refresh();

        // Create settlement audit log
        SettlementAuditLog::create([
            'invoice_id' => $invoice->id,
            'payment_id' => $payment?->id,
            'payment_amount' => $paymentAmount,
            'before_paid_amount' => $beforeState['paid_amount'],
            'after_paid_amount' => $invoice->paid_amount,
            'before_balance' => $beforeState['balance'],
            'after_balance' => $invoice->balance,
            'before_status' => $beforeState['status'],
            'after_status' => $invoice->status,
            'actor_user_id' => TenantUserHelper::resolveTenantUserId(),
            'settlement_method' => $payment?->payment_method ?? 'unknown',
        ]);

        if ($invoice->customer) {
            $invoice->customer->updateOutstanding();
        }
    }
}
