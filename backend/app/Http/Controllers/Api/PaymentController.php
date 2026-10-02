<?php

namespace App\Http\Controllers\Api;

use App\Exports\PaymentsExport;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\CustomerWideSearchService;
use App\Services\DocumentWideSearchService;
use App\Services\InvoiceService;
use App\Services\LockDateValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

        $search = $request->filled('search') ? trim((string) $request->search) : '';
        if ($search !== '') {
            app(DocumentWideSearchService::class)->apply($query, $search, $this->wideSearchConfig());
            $query->with(app(DocumentWideSearchService::class)->eagerLoads($this->wideSearchConfig()));
        }

        if ($request->boolean('ids_only')) {
            $ids = $query->pluck('id');

            return response()->json(['data' => $ids, 'total' => $ids->count()]);
        }

        $perPage = (int) ($request->per_page ?? 20);
        $perPage = max(1, min($perPage, 200));

        $payments = $query->paginate($perPage);

        if ($search !== '') {
            app(DocumentWideSearchService::class)->annotate($payments, $search, $this->wideSearchConfig());
        }

        return response()->json($payments);
    }

    /**
     * Wide-search configuration for the payment list — matches the payment
     * number/reference/notes, the customer's full surface (contacts,
     * addresses, tags), the paid invoice number and the payment's tags.
     */
    private function wideSearchConfig(): array
    {
        return [
            'columns' => ['payment_number', 'reference', 'notes'],
            'party' => [
                'relation' => 'customer',
                'service' => CustomerWideSearchService::class,
                'contacts' => 'customerContacts',
                'addresses' => 'addresses',
            ],
            'related' => ['invoice' => 'invoice_number'],
            'tagAlias' => 'payment',
        ];
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'columns' => ['required', 'array', 'min:1', 'max:'.count(PaymentsExport::COLUMNS)],
            'columns.*' => ['required', 'string', 'distinct', 'in:'.implode(',', array_keys(PaymentsExport::COLUMNS))],
            'document_ids' => ['nullable', 'array'],
            'document_ids.*' => ['integer', 'distinct', 'exists:payments,id'],
            'format' => ['nullable', 'in:csv,xlsx'],
        ]);
        $query = Payment::query();
        if ($request->filled('document_ids')) {
            $query->whereIn('id', $request->input('document_ids'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('payment_status')) {
            $query->whereIn('payment_status', array_filter(array_map('trim', explode(',', (string) $request->payment_status))));
        }
        if ($request->filled('method')) {
            $query->whereIn('method', array_filter(array_map('trim', explode(',', (string) $request->method))));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('payment_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('payment_date', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            app(DocumentWideSearchService::class)->apply(
                $query,
                trim((string) $request->search),
                $this->wideSearchConfig(),
            );
        }

        $format = $validated['format'] ?? 'xlsx';

        return Excel::download(
            new PaymentsExport($query->orderByDesc('payment_date')->orderByDesc('id'), $validated['columns'], $format),
            'payments_'.now()->format('Y-m-d').'.'.$format,
            $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
        );
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
            // Issue #96: a voided/reversed/superseded invoice keeps its legacy
            // status+balance, but must never be selectable for payment.
            ->notSuperseded()
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
            'method' => ['required', 'string', Rule::in($this->methodCodes())],
            'reference' => 'nullable|string',
            'notes' => 'nullable|string',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
        ]);

        // #486: field requirements come from the method master, not a
        // hardcoded guess (e.g. Cheque requires a reference).
        $methodRow = PaymentMethod::query()->active()->where('code', $validated['method'])->first();
        if ($methodRow?->requires_reference && blank($validated['reference'] ?? null)) {
            return response()->json([
                'message' => "A reference number is required for {$methodRow->label} payments.",
            ], 422);
        }

        try {
            $invoice = Invoice::findOrFail($validated['invoice_id']);
            $discountAmount = $validated['discount_amount'] ?? 0;
            $totalApplied = $validated['amount'] + $discountAmount;

            if ($totalApplied <= 0) {
                return response()->json([
                    'message' => 'Payment amount must be greater than zero.',
                ], 422);
            }

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

            // #479: NO bank-balance ceiling on an incoming receipt. That guard
            // is an OUTFLOW rule (vendor payments/refunds) — applying it here
            // rejected exactly the deposits that fund a new/low-balance account.

            // Manual recording creates a DRAFT payment (no journal entry). The
            // operator reviews and posts it from the Payments page — creating
            // it previously posted to the GL immediately, which surprised
            // operators who expected a draft to review first.
            $payment = $this->invoiceService->recordPayment($invoice, $validated, postImmediately: false);

            return response()->json([
                'message' => 'Draft payment recorded — post it from the Payments page to hit the ledger',
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

    /**
     * Active payment-method codes from the tenant master (#486).
     * Falls back to the historical hardcoded list when the master has not
     * been seeded yet, so behaviour is unchanged for unseeded tenants.
     */
    private function methodCodes(): array
    {
        $codes = PaymentMethod::query()->active()->pluck('code')->all();

        return $codes ?: ['cash', 'card', 'bank_transfer', 'check', 'other'];
    }

    public function show($payment)
    {
        $payment = Payment::findOrFail($payment);

        $payment->load(['customer', 'invoice', 'journalEntry', 'receivedBy', 'bankAccount.glAccount']);

        // #485: the detail view labels the receiving account by name AND GL
        // code — the join the list endpoint does, done explicitly here.
        // NOTE: relations live in $payment->relations under the CAMEL key, so
        // read via $payment->bankAccount; the JSON key is snake_cased on
        // serialization by Laravel.
        if ($payment->bankAccount) {
            $payment->bankAccount->setAttribute('gl_account_code', $payment->bankAccount->glAccount?->code);
        }

        return response()->json($payment);
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
            'method' => ['nullable', 'string', Rule::in($this->methodCodes())],
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            // #485: the edit dialog must be able to move the deposit account.
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
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
                if (array_key_exists('bank_account_id', $validated)) {
                    $payment->bank_account_id = $validated['bank_account_id'];
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

                // #477 (Decision 2026-10-02): a draft payment does not touch any
                // invoice, so editing it (amount or linked invoice) must NOT
                // mutate invoice balances either. Settlement — paid_amount,
                // balance, status, AR — happens only when the payment is posted.
                // Only drafts reach here (posted payments are locked above).
                if ((int) $newInvoiceId !== (int) $previousInvoiceId && $newInvoiceId !== null) {
                    $target = Invoice::find($newInvoiceId);
                    if ($target) {
                        $payment->customer_id = $target->customer_id;
                    }
                }

                $payment->invoice_id = $newInvoiceId;
                $payment->amount = $newAmount;

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
                // #477/#482: new drafts never touched the invoice. A LEGACY
                // draft may still carry live applications (it settled before
                // #477) — reverse them through the canonical allocation path
                // (restores each invoice + writes the settlement audit rows)
                // before removing the payment.
                DB::transaction(function () use ($payment) {
                    $this->reverseLiveApplications($payment);
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

        // #477/#482: a new draft never touched the invoice, but a LEGACY draft
        // may still carry live applications — reverse them canonically before
        // deleting so the invoices (and their audit trail) are restored.
        DB::transaction(function () use ($payment) {
            $this->reverseLiveApplications($payment);
            $payment->delete();
        });

        return response()->json(['message' => 'Payment deleted successfully']);
    }

    /**
     * Cancel a recorded payment (recorded → cancelled).
     * Unapplies the payment from the linked invoice, restoring the invoice balance.
     * Routes through PaymentCorrectionService for proper GL reversal (ADR-005 §15.3).
     */
    /**
     * Issue #155: list a payment's applications + unapplied amount.
     */
    public function allocations($paymentId)
    {
        $payment = Payment::with('applications.invoice')->find($paymentId);

        if (! $payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        $applied = round((float) $payment->applications->sum('applied_amount'), 2);

        return response()->json([
            'data' => $payment->applications,
            'amount' => round((float) $payment->amount, 2),
            'applied_amount' => $applied,
            'unapplied_amount' => app(\App\Services\PaymentAllocationService::class)->unappliedAmount($payment),
        ]);
    }

    /**
     * Issue #155: split a payment across invoices (or hold it on account).
     */
    public function allocate(Request $request, $paymentId)
    {
        $payment = Payment::find($paymentId);

        if (! $payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        $validated = $request->validate([
            'applications' => 'required|array|min:1',
            'applications.*.invoice_id' => 'required|integer|exists:invoices,id',
            'applications.*.amount' => 'required|numeric|min:0.01',
        ]);

        try {
            $payment = app(\App\Services\PaymentAllocationService::class)
                ->allocate($payment, $validated['applications']);

            return response()->json([
                'message' => 'Payment allocated successfully',
                'data' => $payment->applications,
                'applied_amount' => round((float) $payment->applications->sum('applied_amount'), 2),
                'unapplied_amount' => app(\App\Services\PaymentAllocationService::class)->unappliedAmount($payment),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'INVALID_ALLOCATION',
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to allocate payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function cancel($paymentId)
    {
        $payment = Payment::findOrFail($paymentId);

        if ($payment->payment_status !== 'recorded') {
            return response()->json(['message' => 'Only recorded payments can be cancelled'], 403);
        }

        // #483: a draft has no journal entry — there is nothing for the posted
        // payment correction flow to unapply. Cancel it through the delete path
        // (reversing any legacy allocation); no reversal JE is created.
        if ($payment->journal_entry_id === null) {
            DB::transaction(function () use ($payment) {
                $this->reverseLiveApplications($payment);
                $payment->delete();
            });

            return response()->json(['message' => 'Draft payment cancelled']);
        }

        try {
            // #483: the actor is the TENANT user — auth()->id() is the central
            // public.users id and must not be recorded on corrections.
            $actorId = \Modules\OCRBookkeeping\Helpers\TenantUserHelper::requireTenantUserId();

            $correctionRequest = new \App\Services\CorrectionRequest(
                reasonCode: 'PAYMENT_CANCELLATION',
                reasonText: 'Payment cancelled by user '.$actorId,
                userId: $actorId,
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
    /**
     * #482: reverse any live payment_applications before a draft is removed.
     * Legacy drafts (created before #477) settled their invoices directly and
     * still carry applications; deleting them without reversing left the
     * invoices marked paid. Runs through PaymentAllocationService (canonical)
     * so every invoice is restored and a settlement audit row is written.
     */
    private function reverseLiveApplications(Payment $payment): void
    {
        if ($payment->applications()->whereNull('reversed_at')->exists()) {
            app(\App\Services\PaymentAllocationService::class)->reverseAllocations($payment);
        }
    }

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
