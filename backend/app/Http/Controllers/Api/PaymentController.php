<?php

namespace App\Http\Controllers\Api;

use App\Exports\PaymentsExport;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceService;
use App\Services\LockDateValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class PaymentController extends Controller
{
    public function __construct(
        private InvoiceService $invoiceService,
        private LockDateValidationService $lockDateValidator,
        private \App\Services\PaymentPostingService $paymentPostingService,
    ) {}

    /**
     * List payments with full filter / search support.
     *
     * Filters:
     *   - invoice_id, customer_id
     *   - payment_status (single value or comma-separated)
     *   - method         (single value or comma-separated)
     *   - date_from, date_to  (ISO date strings, inclusive bounds)
     *   - search         (matches payment_number, reference, customer name,
     *                     and joined invoice.invoice_number)
     */
    public function index(Request $request)
    {
        $query = Payment::with(['customer', 'invoice', 'journalEntry'])
            ->orderBy('payment_date', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->invoice_id);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('payment_status')) {
            $statuses = array_filter(array_map('trim', explode(',', (string) $request->payment_status)));
            if (! empty($statuses)) {
                $query->whereIn('payment_status', $statuses);
            }
        }

        if ($request->filled('method')) {
            $methods = array_filter(array_map('trim', explode(',', (string) $request->method)));
            if (! empty($methods)) {
                $query->whereIn('method', $methods);
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('payment_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('payment_date', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = (string) $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('payment_number', 'ILIKE', "%{$search}%")
                    ->orWhere('reference', 'ILIKE', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'ILIKE', "%{$search}%")
                            ->orWhere('code', 'ILIKE', "%{$search}%");
                    })
                    ->orWhereHas('invoice', function ($iq) use ($search) {
                        $iq->where('invoice_number', 'ILIKE', "%{$search}%");
                    });
            });
        }

        $perPage = (int) ($request->per_page ?? 20);
        $perPage = max(1, min($perPage, 200));

        $payments = $query->paginate($perPage);

        return response()->json($payments);
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'columns' => ['required', 'array', 'min:1', 'max:'.count(PaymentsExport::COLUMNS)],
            'columns.*' => ['required', 'string', 'distinct', 'in:'.implode(',', array_keys(PaymentsExport::COLUMNS))],
            'document_ids' => ['nullable', 'array'],
            'document_ids.*' => ['integer', 'distinct', 'exists:payments,id'],
        ]);
        $query = Payment::query();
        if ($request->filled('document_ids')) $query->whereIn('id', $request->input('document_ids'));
        if ($request->filled('customer_id')) $query->where('customer_id', $request->customer_id);
        if ($request->filled('payment_status')) $query->whereIn('payment_status', array_filter(array_map('trim', explode(',', (string) $request->payment_status))));
        if ($request->filled('method')) $query->whereIn('method', array_filter(array_map('trim', explode(',', (string) $request->method))));
        if ($request->filled('date_from')) $query->whereDate('payment_date', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('payment_date', '<=', $request->date_to);
        if ($request->filled('search')) {
            $search = (string) $request->search;
            $query->where(fn ($q) => $q->where('payment_number', 'ILIKE', "%{$search}%")
                ->orWhere('reference', 'ILIKE', "%{$search}%")
                ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'ILIKE', "%{$search}%"))
                ->orWhereHas('invoice', fn ($iq) => $iq->where('invoice_number', 'ILIKE', "%{$search}%")));
        }
        return Excel::download(new PaymentsExport($query->orderByDesc('payment_date')->orderByDesc('id'), $validated['columns']), 'payments_'.now()->format('Y-m-d').'.xlsx');
    }

    /**
     * Get eligible invoices for recording a customer payment.
     * Only posted, sent, or partially paid invoices with a positive balance are eligible.
     */
    public function eligibleInvoices(Request $request)
    {
        $request->validate([
            'customer_id' => 'nullable|integer|exists:customers,id',
        ]);

        $query = Invoice::with('customer')
            ->whereIn('status', ['posted', 'sent', 'partial'])
            ->where(function ($q) {
                $q->whereNull('balance')
                    ->orWhere('balance', '>', 0);
            })
            ->orderBy('invoice_date', 'desc');

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $invoices = $query->get()->map(function ($invoice) {
            return [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date,
                'total' => (float) $invoice->total,
                'balance' => (float) ($invoice->balance ?? $invoice->total),
                'status' => $invoice->status,
                'customer_id' => $invoice->customer_id,
                'customer_name' => $invoice->customer?->name ?? 'Unknown',
            ];
        });

        if ($invoices->isEmpty()) {
            return response()->json([
                'data' => [],
                'message' => 'No eligible invoices found. Only posted unpaid invoices for the selected customer can be paid.',
            ]);
        }

        return response()->json([
            'data' => $invoices,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'payment_date' => 'nullable|date',
            'amount' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'method' => 'required|in:cash,card,bank_transfer,check,other',
            'reference' => 'nullable|string',
            'notes' => 'nullable|string',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
        ]);

        try {
            $invoice = Invoice::findOrFail($validated['invoice_id']);
            $discountAmount = $validated['discount_amount'] ?? 0;
            $totalApplied = $validated['amount'] + $discountAmount;

            if ($totalApplied > $invoice->balance) {
                return response()->json([
                    'message' => 'Payment amount exceeds invoice balance',
                ], 422);
            }

            // Lock-date check: block recording payment dated in a closed period
            $paymentDate = $validated['payment_date'] ?? now()->toDateString();
            $lockResult = $this->lockDateValidator->validateTransactionDate('payments', $paymentDate);
            if ($lockResult['is_locked']) {
                return response()->json([
                    'message' => "Cannot record payment dated in a closed period. {$lockResult['message']}",
                    'lock_date' => $lockResult['lock_date'],
                    'transaction_date' => $paymentDate,
                ], 422);
            }

            // Validate bank balance if paying from a bank account
            if (! empty($validated['bank_account_id'])) {
                $bankAccount = \App\Models\BankAccount::find($validated['bank_account_id']);
                if ($bankAccount) {
                    $currentBalance = $bankAccount->getCurrentBalance();
                    if ($validated['amount'] > $currentBalance) {
                        return response()->json([
                            'message' => 'Payment amount exceeds bank account balance',
                            'bank_balance' => $currentBalance,
                            'requested_amount' => $validated['amount'],
                        ], 422);
                    }
                }
            }

            $payment = $this->invoiceService->recordPayment($invoice, $validated);

            return response()->json([
                'message' => 'Payment recorded successfully',
                'id' => $payment->id,
                'data' => $payment,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to record payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($payment)
    {
        $payment = Payment::findOrFail($payment);

        return response()->json($payment->load(['customer', 'invoice', 'journalEntry', 'receivedBy']));
    }

    /**
     * Bulk-post draft payments to the ledger (draft → posted).
     *
     * Idempotent: already-posted payments are counted as success. Payments
     * with a legacy journal entry but a draft status are repaired. Failures
     * (e.g. default GL accounts not configured) are reported per-row.
     */
    public function bulkPost(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        try {
            $result = $this->paymentPostingService->postMany($validated['ids']);

            return response()->json([
                'message' => count($result['posted']).' payment(s) posted.',
                'posted' => $result['posted'],
                'skipped' => $result['skipped'],
            ]);
        } catch (\Exception $e) {
            \Log::error('Bulk payment posting failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Bulk post failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Post a single payment to the ledger (draft → posted).
     */
    public function post(Request $request, $paymentId)
    {
        $payment = Payment::findOrFail($paymentId);

        try {
            $posted = $this->paymentPostingService->post($payment);

            return response()->json([
                'message' => 'Payment posted successfully',
                'data' => $posted,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Payment posting failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to post payment: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Edit a payment.
     *
     * Backend rule (mirrors §9 of the spec):
     *   - Draft payment (no journal_entry_id) → fully editable
     *   - Posted payment (journal_entry_id set) → LOCKED.
     *     Corrections must go through the reversal/refund endpoints, not direct
     *     mutation of historical accounting values.
     *
     * If the linked invoice changes, the previous invoice is un-allocated
     * (paid_amount reduced) and the new invoice is allocated (paid_amount
     * increased), keeping both invoice balances consistent.
     */
    public function update(Request $request, $paymentId)
    {
        $payment = Payment::findOrFail($paymentId);

        if (! $payment->isEditable()) {
            return response()->json([
                'message' => 'Posted payments cannot be edited directly. Use the refund or reversal endpoint to correct them.',
                'payment_status' => $payment->payment_status,
                'has_journal_entry' => $payment->journal_entry_id !== null,
            ], 403);
        }

        $validated = $request->validate([
            'payment_date' => 'nullable|date',
            'invoice_id' => 'nullable|integer|exists:invoices,id',
            'amount' => 'nullable|numeric|min:0.01',
            'method' => 'nullable|in:cash,card,bank_transfer,check,other',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if (array_key_exists('payment_date', $validated)) {
            $lockResult = $this->lockDateValidator->validateTransactionDate('payments', $validated['payment_date']);
            if ($lockResult['is_locked']) {
                return response()->json([
                    'message' => "Cannot edit a payment dated in a closed period. {$lockResult['message']}",
                    'lock_date' => $lockResult['lock_date'],
                    'transaction_date' => $validated['payment_date'],
                ], 422);
            }
        }

        try {
            DB::transaction(function () use ($payment, $validated) {
                $previousInvoiceId = $payment->invoice_id;
                $previousAmount = (float) $payment->amount;

                if (array_key_exists('payment_date', $validated)) {
                    $payment->payment_date = $validated['payment_date'];
                }
                if (array_key_exists('method', $validated)) {
                    $payment->method = $validated['method'];
                }
                if (array_key_exists('reference', $validated)) {
                    $payment->reference = $validated['reference'];
                }
                if (array_key_exists('notes', $validated)) {
                    $payment->notes = $validated['notes'];
                }

                $newInvoiceId = array_key_exists('invoice_id', $validated)
                    ? $validated['invoice_id']
                    : $previousInvoiceId;
                $newAmount = array_key_exists('amount', $validated)
                    ? (float) $validated['amount']
                    : $previousAmount;

                // When invoice or amount changes we re-allocate so both
                // invoices stay balanced. Validate the new allocation fits.
                $invoiceChanged = (int) $newInvoiceId !== (int) $previousInvoiceId;
                $amountChanged = abs($newAmount - $previousAmount) > 0.005;

                if ($invoiceChanged || $amountChanged) {
                    // Remove the existing allocation first. This makes the
                    // payment's current amount available when editing it on
                    // the same invoice and prevents double-counting.
                    if ($previousInvoiceId !== null) {
                        $previous = Invoice::lockForUpdate()->find($previousInvoiceId);
                        if ($previous) {
                            $previous->paid_amount = max(0, (float) $previous->paid_amount - $previousAmount);
                            $previous->balance = max(0, (float) $previous->total - (float) $previous->paid_amount);
                            $previous->status = $this->resolveInvoiceStatus($previous);
                            $previous->settlement_status = $previous->paid_amount <= 0 ? 'unpaid' : 'partially_paid';
                            $previous->save();
                        }
                    }

                    if ($newInvoiceId !== null) {
                        $target = Invoice::lockForUpdate()->findOrFail($newInvoiceId);

                        if ($newAmount > (float) $target->balance) {
                            throw new \RuntimeException(
                                "Payment amount ({$newAmount}) exceeds invoice {$target->invoice_number} balance (".(float) $target->balance.')'
                            );
                        }

                        $target->paid_amount = (float) $target->paid_amount + $newAmount;
                        $target->balance = max(0, (float) $target->total - (float) $target->paid_amount);
                        $target->status = $this->resolveInvoiceStatus($target);
                        $target->settlement_status = $target->paid_amount <= 0 ? 'unpaid' : ($target->balance <= 0 ? 'paid' : 'partially_paid');
                        $target->save();

                        $payment->customer_id = $target->customer_id;
                    }

                    $payment->invoice_id = $newInvoiceId;
                    $payment->amount = $newAmount;
                }

                $payment->save();
            });

            return response()->json([
                'message' => 'Payment updated successfully',
                'data' => $payment->fresh(['customer', 'invoice', 'journalEntry']),
            ]);

        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk-delete payments.
     *
     * Only draft payments (status='draft' AND no journal_entry_id) are removed.
     * Posted/recorded payments are reported back as skipped so the UI can show
     * the user which ones were protected by accounting rules. The backend
     * enforces this — UI restrictions alone would not satisfy §10.
     */
    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $ids = $validated['ids'];
        $deletedIds = [];
        $skipped = [];

        foreach ($ids as $id) {
            $payment = Payment::find($id);
            if (! $payment) {
                $skipped[] = ['id' => $id, 'reason' => 'Payment not found'];
                continue;
            }

            if (! $payment->isDeletable()) {
                $reason = $payment->journal_entry_id !== null
                    ? 'Posted payments cannot be deleted. Use refund or reversal.'
                    : "Payment in status '{$payment->payment_status}' cannot be deleted.";
                $skipped[] = ['id' => $id, 'reason' => $reason];
                continue;
            }

            try {
                DB::transaction(function () use ($payment) {
                    if ($payment->invoice_id) {
                        $invoice = Invoice::find($payment->invoice_id);
                        if ($invoice) {
                            $invoice->paid_amount = max(0, (float) $invoice->paid_amount - (float) $payment->amount);
                            $invoice->balance = $invoice->total - $invoice->paid_amount;
                            $invoice->status = $this->resolveInvoiceStatus($invoice);
                            $invoice->settlement_status = $invoice->paid_amount <= 0 ? 'unpaid' : 'partially_paid';
                            $invoice->save();

                            if ($invoice->customer) {
                                $invoice->customer->updateOutstanding();
                            }
                        }
                    }

                    $payment->delete();
                });

                $deletedIds[] = $id;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => count($deletedIds).' payment(s) deleted.',
            'deleted' => $deletedIds,
            'skipped' => $skipped,
        ]);
    }

    public function destroy($payment)
    {
        $payment = Payment::findOrFail($payment);

        if (! $payment->isDeletable()) {
            return response()->json([
                'message' => $payment->journal_entry_id !== null
                    ? 'Posted payments cannot be deleted. Use refund or reversal.'
                    : "Payment in status '{$payment->payment_status}' cannot be deleted.",
            ], 403);
        }

        DB::transaction(function () use ($payment) {
            if ($payment->invoice_id) {
                $invoice = Invoice::find($payment->invoice_id);
                if ($invoice) {
$invoice->paid_amount = max(0, (float) $invoice->paid_amount - (float) $payment->amount);
                $invoice->balance = $invoice->total - $invoice->paid_amount;
                $invoice->status = $this->resolveInvoiceStatus($invoice);
                $invoice->settlement_status = $invoice->paid_amount <= 0 ? 'unpaid' : 'partially_paid';
                $invoice->save();
                }
            }
            $payment->delete();
        });

        return response()->json(['message' => 'Payment deleted successfully']);
    }

    /**
     * Cancel a recorded payment (recorded → cancelled).
     * Unapplies the payment from the linked invoice, restoring the invoice balance.
     * Routes through PaymentCorrectionService for proper GL reversal (ADR-005 §15.3).
     */
    public function cancel($paymentId)
    {
        $payment = Payment::findOrFail($paymentId);

        if ($payment->payment_status !== 'recorded') {
            return response()->json(['message' => 'Only recorded payments can be cancelled'], 403);
        }

        try {
            $correctionRequest = new \App\Services\CorrectionRequest(
                reasonCode: 'PAYMENT_CANCELLATION',
                reasonText: 'Payment cancelled by user ' . auth()->id(),
                userId: auth()->id(),
            );

            $result = app(\App\Services\PaymentCorrectionService::class)
                ->unapplyPayment($payment, $correctionRequest);

            return response()->json([
                'message' => 'Payment cancelled and unapplied from invoice',
                'data' => $result->payment->fresh(['customer', 'invoice']),
                'reversal_journal_entry_id' => $result->reversalJournalEntry?->id,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to cancel payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Compute the invoice status string based on the canonical balance math
     * (single source of truth for "Partially Paid" vs "Paid").
     */
    private function resolveInvoiceStatus(Invoice $invoice): string
    {
        $paid = (float) $invoice->paid_amount;
        $total = (float) $invoice->total;

        if ($paid <= 0.005) {
            // Restore original "sent/posted" state when there were no payments
            return $invoice->journal_entry_id ? 'posted' : 'draft';
        }

        if ($paid >= $total - 0.005) {
            return 'paid';
        }

        return 'partial';
    }
}
