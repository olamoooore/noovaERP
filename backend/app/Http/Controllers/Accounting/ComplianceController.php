<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\Services\ComplianceService;
use App\Exports\ComplianceReportExport;
use App\Http\Controllers\Controller;
use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\ComplianceSubmission;
use App\Models\Accounting\CountryConfiguration;
use App\Models\Bill;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\VendorDebitNote;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class ComplianceController extends Controller
{
    protected ComplianceService $complianceService;

    public function __construct(ComplianceService $complianceService)
    {
        $this->complianceService = $complianceService;
        $this->middleware('auth:sanctum');
    }

    private function currentTenantId(): ?string
    {
        return tenancy()->tenant?->id;
    }

    private function findTenantComplianceDocument(string|int $id): ComplianceDocument
    {
        $tenantId = $this->currentTenantId();
        $query = ComplianceDocument::where('tenant_id', $tenantId);

        $document = (clone $query)->find($id);
        if ($document) {
            return $document;
        }

        if (is_numeric($id)) {
            $fallback = (clone $query)
                ->where('document_id', (int) $id)
                ->orderByDesc('id')
                ->first();

            if ($fallback) {
                return $fallback;
            }
        }

        throw (new ModelNotFoundException)->setModel(ComplianceDocument::class, [$id]);
    }

    /**
     * Enforce authorization when an ability/policy is configured.
     * Fallback to authenticated access for environments without policy wiring.
     */
    private function authorizeWhenConfigured(string $ability, string $modelClass): void
    {
        /** @var GateContract $gate */
        $gate = app(GateContract::class);

        if ($gate->has($ability) || $gate->getPolicyFor($modelClass)) {
            $this->authorize($ability, $modelClass);

            return;
        }

        if (! Auth::check()) {
            abort(Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        }
    }

    /**
     * List compliance documents for tenant
     * GET /api/v1/accounting/compliance/documents
     */
    public function listDocuments(Request $request)
    {
        // Seed compliance document stubs for MY invoices and credit notes.
        $country = strtoupper((string) $request->get('country_code', ''));
        if ($country === 'MY') {
            $this->seedMissingMyComplianceDocuments();
            $this->seedMissingMyCreditNotes();
            $this->seedMissingMyBills();
            $this->seedMissingMyVendorDebitNotes();
            $this->seedMissingMyJournalEntries();
        }

        $query = ComplianceDocument::where('tenant_id', $this->currentTenantId());

        // Filters
        if ($request->has('country_code')) {
            $query->where('country_code', strtoupper($request->get('country_code')));
        }
        if ($request->has('status')) {
            $query->where('compliance_status', $request->get('status'));
        }
        if ($request->has('document_type')) {
            $query->where('document_type', $request->get('document_type'));
        }

        // Pagination
        $perPage = min($request->get('per_page', 20), 100);
        $documents = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // #670 (EINV-UX1): list rows show the effective e-invoice status, which
        // can live on the latest submission (an accepted document with an open
        // buyer rejection request is not "valid"). One extra query for the
        // whole page instead of one per row.
        if ($request->boolean('with_latest_submission') && $documents->isNotEmpty()) {
            $latest = ComplianceSubmission::whereIn('document_id', $documents->pluck('id'))
                ->orderByDesc('id')
                ->get()
                ->groupBy('document_id')
                ->map(fn ($group) => $group->first());

            foreach ($documents as $document) {
                $document->setAttribute('latest_submission', $latest->get($document->id));
            }
        }

        return response()->json([
            'success' => true,
            'data' => $documents->items(),
            'pagination' => [
                'total' => $documents->total(),
                'per_page' => $documents->perPage(),
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
            ],
        ]);
    }

    /**
     * Backfill missing MY compliance documents from existing invoices.
     */
    private function seedMissingMyComplianceDocuments(): void
    {
        $tenantId = $this->currentTenantId();
        if (! $tenantId) {
            return;
        }

        $existingInvoiceIds = ComplianceDocument::query()
            ->where('tenant_id', $tenantId)
            ->where('country_code', 'MY')
            ->where('document_type', 'invoice')
            ->pluck('document_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $candidateInvoices = Invoice::query()
            ->when(! empty($existingInvoiceIds), fn ($q) => $q->whereNotIn('id', $existingInvoiceIds))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        foreach ($candidateInvoices as $invoice) {
            ComplianceDocument::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'country_code' => 'MY',
                    'document_type' => 'invoice',
                    'document_id' => $invoice->id,
                ],
                [
                    'document_number' => $invoice->invoice_number,
                    'issued_date' => $invoice->invoice_date,
                    'total_amount' => $invoice->total,
                    'currency_code' => 'MYR',
                    'mapped_payload' => null,
                    'compliance_status' => 'pending',
                    'created_by' => Auth::id(),
                ]
            );
        }
    }

    /**
     * Backfill missing MY compliance document stubs from existing credit notes.
     */
    private function seedMissingMyCreditNotes(): void
    {
        $tenantId = $this->currentTenantId();
        if (! $tenantId) {
            return;
        }

        $existingIds = ComplianceDocument::query()
            ->where('tenant_id', $tenantId)
            ->where('country_code', 'MY')
            ->where('document_type', 'credit_note')
            ->pluck('document_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $candidates = CustomerCreditNote::query()
            ->when(! empty($existingIds), fn ($q) => $q->whereNotIn('id', $existingIds))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        foreach ($candidates as $cn) {
            ComplianceDocument::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'country_code' => 'MY',
                    'document_type' => 'credit_note',
                    'document_id' => $cn->id,
                ],
                [
                    'document_number' => $cn->credit_note_number,
                    'issued_date' => $cn->credit_note_date,
                    'total_amount' => $cn->total_amount,
                    'currency_code' => $cn->currency_code ?? 'MYR',
                    'mapped_payload' => null,
                    'compliance_status' => 'pending',
                    'created_by' => Auth::id(),
                ]
            );
        }
    }

    /**
     * Backfill missing MY compliance document stubs from existing vendor bills.
     */
    private function seedMissingMyBills(): void
    {
        $tenantId = $this->currentTenantId();
        if (! $tenantId) {
            return;
        }

        $existingIds = ComplianceDocument::query()
            ->where('tenant_id', $tenantId)
            ->where('country_code', 'MY')
            ->where('document_type', 'bill')
            ->pluck('document_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $candidates = Bill::query()
            ->when(! empty($existingIds), fn ($q) => $q->whereNotIn('id', $existingIds))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        foreach ($candidates as $bill) {
            ComplianceDocument::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'country_code' => 'MY',
                    'document_type' => 'bill',
                    'document_id' => $bill->id,
                ],
                [
                    'document_number' => $bill->bill_number,
                    'issued_date' => $bill->bill_date,
                    'total_amount' => $bill->total,
                    'currency_code' => $bill->currency_code ?? 'MYR',
                    'mapped_payload' => null,
                    'compliance_status' => 'pending',
                    'created_by' => Auth::id(),
                ]
            );
        }
    }

    /**
     * Backfill missing MY compliance document stubs from existing vendor debit notes.
     */
    private function seedMissingMyVendorDebitNotes(): void
    {
        $tenantId = $this->currentTenantId();
        if (! $tenantId) {
            return;
        }

        $existingIds = ComplianceDocument::query()
            ->where('tenant_id', $tenantId)
            ->where('country_code', 'MY')
            ->where('document_type', 'vendor_debit_note')
            ->pluck('document_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $candidates = VendorDebitNote::query()
            ->when(! empty($existingIds), fn ($q) => $q->whereNotIn('id', $existingIds))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        foreach ($candidates as $vdn) {
            ComplianceDocument::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'country_code' => 'MY',
                    'document_type' => 'vendor_debit_note',
                    'document_id' => $vdn->id,
                ],
                [
                    'document_number' => $vdn->debit_note_number,
                    'issued_date' => $vdn->debit_note_date,
                    'total_amount' => $vdn->total_amount,
                    'currency_code' => $vdn->currency_code ?? 'MYR',
                    'mapped_payload' => null,
                    'compliance_status' => 'pending',
                    'created_by' => Auth::id(),
                ]
            );
        }
    }

    /**
     * Backfill missing MY compliance document stubs from posted journal entries
     * that have at least one debit line against an expense-type account.
     */
    private function seedMissingMyJournalEntries(): void
    {
        $tenantId = $this->currentTenantId();
        if (! $tenantId) {
            return;
        }

        // Get IDs of JEs that qualify (posted + at least one expense-type debit line)
        $qualifyingJeIds = \App\Models\JournalEntry::query()
            ->where('status', 'posted')
            ->whereHas('lines', function ($q) {
                $q->where('debit', '>', 0)
                    ->whereHas('account', function ($q2) {
                        $q2->where('type', 'expense');
                    });
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Remove pending stubs for JEs that no longer qualify (e.g. seeded before this filter existed)
        ComplianceDocument::query()
            ->where('tenant_id', $tenantId)
            ->where('country_code', 'MY')
            ->where('document_type', 'journal_entry')
            ->where('compliance_status', 'pending')
            ->when(! empty($qualifyingJeIds), fn ($q) => $q->whereNotIn('document_id', $qualifyingJeIds))
            ->delete();

        $existingIds = ComplianceDocument::query()
            ->where('tenant_id', $tenantId)
            ->where('country_code', 'MY')
            ->where('document_type', 'journal_entry')
            ->pluck('document_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $candidates = \App\Models\JournalEntry::query()
            ->whereIn('id', $qualifyingJeIds)
            ->when(! empty($existingIds), fn ($q) => $q->whereNotIn('id', $existingIds))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        foreach ($candidates as $je) {
            ComplianceDocument::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'country_code' => 'MY',
                    'document_type' => 'journal_entry',
                    'document_id' => $je->id,
                ],
                [
                    'document_number' => $je->entry_number,
                    'issued_date' => $je->entry_date,
                    'total_amount' => $je->getTotalDebit(),
                    'currency_code' => 'MYR',
                    'mapped_payload' => null,
                    'compliance_status' => 'pending',
                    'created_by' => Auth::id(),
                ]
            );
        }
    }

    /**
     * Submit an internal-expense journal entry as a self-billed invoice.
     * POST /api/v1/accounting/compliance/journal-entries/{journalEntryId}/submit
     */
    public function submitJournalEntry(Request $request, int $journalEntryId)
    {
        $this->authorizeWhenConfigured('submit', ComplianceDocument::class);

        $validated = $request->validate([
            'country_code' => 'required|string|size:2|uppercase',
        ]);

        $je = \App\Models\JournalEntry::with(['lines.account'])->findOrFail($journalEntryId);

        if ($je->status !== 'posted') {
            return response()->json([
                'success' => false,
                'message' => 'Only posted journal entries can be submitted to MyInvois',
            ], 422);
        }

        $tenantId = $this->currentTenantId();

        $document = ComplianceDocument::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'country_code' => strtoupper($validated['country_code']),
                'document_type' => 'journal_entry',
                'document_id' => $je->id,
            ],
            [
                'document_number' => $je->entry_number,
                'issued_date' => $je->entry_date,
                'total_amount' => $je->getTotalDebit(),
                'currency_code' => 'MYR',
                'mapped_payload' => null,
                'compliance_status' => 'pending',
                'created_by' => Auth::id(),
            ]
        );

        $result = $this->complianceService->submitComplianceDocument(
            $document,
            strtoupper($validated['country_code']),
            $je,
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Submit a vendor bill directly (creates compliance document if needed).
     * POST /api/v1/accounting/compliance/bills/{billId}/submit
     */
    public function submitBill(Request $request, int $billId)
    {
        $this->authorizeWhenConfigured('submit', ComplianceDocument::class);

        $validated = $request->validate([
            'country_code' => 'required|string|size:2|uppercase',
        ]);

        $bill = Bill::with(['vendor', 'lines'])->findOrFail($billId);
        $tenantId = $this->currentTenantId();

        $document = ComplianceDocument::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'country_code' => strtoupper($validated['country_code']),
                'document_type' => 'bill',
                'document_id' => $bill->id,
            ],
            [
                'document_number' => $bill->bill_number,
                'issued_date' => $bill->bill_date,
                'total_amount' => $bill->total,
                'currency_code' => $bill->currency_code ?? 'MYR',
                'compliance_status' => 'pending',
                'created_by' => Auth::id(),
            ]
        );

        $result = $this->complianceService->submitComplianceDocument(
            $document,
            $validated['country_code'],
            $bill
        );

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'data' => [
                    'document_id' => $result['document_id'],
                    'submission_id' => $result['submission_id'],
                    'provider_reference' => $result['provider_reference'],
                ],
                'message' => $result['message'],
            ]);
        }

        return response()->json(['success' => false, 'message' => $result['message']], 400);
    }

    /**
     * Submit a vendor debit note directly (creates compliance document if needed).
     * POST /api/v1/accounting/compliance/vendor-debit-notes/{debitNoteId}/submit
     */
    public function submitVendorDebitNote(Request $request, int $debitNoteId)
    {
        $this->authorizeWhenConfigured('submit', ComplianceDocument::class);

        $validated = $request->validate([
            'country_code' => 'required|string|size:2|uppercase',
        ]);

        $vdn = VendorDebitNote::with(['vendor', 'bill', 'lines'])->findOrFail($debitNoteId);
        $tenantId = $this->currentTenantId();

        $document = ComplianceDocument::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'country_code' => strtoupper($validated['country_code']),
                'document_type' => 'vendor_debit_note',
                'document_id' => $vdn->id,
            ],
            [
                'document_number' => $vdn->debit_note_number,
                'issued_date' => $vdn->debit_note_date,
                'total_amount' => $vdn->total_amount,
                'currency_code' => $vdn->currency_code ?? 'MYR',
                'compliance_status' => 'pending',
                'created_by' => Auth::id(),
            ]
        );

        $result = $this->complianceService->submitComplianceDocument(
            $document,
            $validated['country_code'],
            $vdn
        );

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'data' => [
                    'document_id' => $result['document_id'],
                    'submission_id' => $result['submission_id'],
                    'provider_reference' => $result['provider_reference'],
                ],
                'message' => $result['message'],
            ]);
        }

        return response()->json(['success' => false, 'message' => $result['message']], 400);
    }

    /**
     * Submit a credit note directly (creates compliance document if needed).
     * POST /api/v1/accounting/compliance/credit-notes/{creditNoteId}/submit
     */
    public function submitCreditNote(Request $request, int $creditNoteId)
    {
        $this->authorizeWhenConfigured('submit', ComplianceDocument::class);

        $validated = $request->validate([
            'country_code' => 'required|string|size:2|uppercase',
        ]);

        $creditNote = CustomerCreditNote::with(['customer', 'invoice', 'lines'])->findOrFail($creditNoteId);

        // #516 (CN7): the compliance document is created by the service, which
        // is also what the automatic post-submission workflow calls — one
        // implementation for the manual and the automatic path.
        $result = $this->complianceService->submitCreditNote($creditNote, $validated['country_code']);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'data' => [
                    'document_id' => $result['document_id'],
                    'submission_id' => $result['submission_id'],
                    'provider_reference' => $result['provider_reference'],
                ],
                'message' => $result['message'],
            ]);
        }

        return response()->json(['success' => false, 'message' => $result['message']], 400);
    }

    /**
     * Get specific compliance document
     * GET /api/v1/accounting/compliance/documents/{id}
     */
    public function getDocument($id)
    {
        $document = $this->findTenantComplianceDocument($id)->load('submissions.events');

        return response()->json(['success' => true, 'data' => $document]);
    }

    /**
     * Submit document for compliance
     * POST /api/v1/accounting/compliance/documents/{id}/submit
     */
    public function submitDocument(Request $request, $id)
    {
        $this->authorizeWhenConfigured('submit', ComplianceDocument::class);

        $validated = $request->validate([
            'country_code' => 'required|string|size:2|uppercase',
        ]);

        $document = $this->findTenantComplianceDocument($id);

        // For invoices, check the source exists here to return a proper 404.
        // For other document types (credit_note, etc.) the service resolves and handles them.
        $sourceDocument = null;
        if ($document->document_type === 'invoice') {
            $sourceDocument = $document->invoice
                ?? ($document->document_id ? Invoice::find($document->document_id) : null);
            if (! $sourceDocument) {
                return response()->json(['success' => false, 'message' => 'Associated invoice not found'], 404);
            }
        }

        $result = $this->complianceService->submitComplianceDocument($document, $validated['country_code'], $sourceDocument);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'data' => [
                    'document_id' => $result['document_id'],
                    'submission_id' => $result['submission_id'],
                    'provider_reference' => $result['provider_reference'],
                ],
                'message' => $result['message'],
            ]);
        } else {
            return response()->json(['success' => false, 'message' => $result['message']], 400);
        }
    }

    /**
     * Generate digital signature artifacts for a document without submitting it.
     * POST /api/v1/accounting/compliance/documents/{id}/sign
     */
    public function signDocument(Request $request, $id)
    {
        $this->authorizeWhenConfigured('submit', ComplianceDocument::class);

        $validated = $request->validate([
            'country_code' => 'required|string|size:2|uppercase',
            'handwritten_signature_data_url' => 'nullable|string|starts_with:data:image/png;base64,|max:1048576',
            'signer_name' => 'nullable|string|max:120',
        ]);

        try {
            $document = $this->findTenantComplianceDocument($id);
        } catch (ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Compliance document not found for this tenant'], 404);
        }

        $invoice = $document->invoice;
        if (! $invoice && $document->document_type === 'invoice' && $document->document_id) {
            $invoice = Invoice::find($document->document_id);
        }

        try {
            $result = $this->complianceService->signComplianceDocument(
                $document,
                $validated['country_code'],
                $invoice,
                [
                    'handwritten_signature_data_url' => $validated['handwritten_signature_data_url'] ?? null,
                    'signer_name' => $validated['signer_name'] ?? null,
                ]
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('signDocument: unexpected exception', [
                'document_id' => $id,
                'country_code' => $validated['country_code'],
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json(['success' => false, 'message' => 'Signature generation failed: '.$e->getMessage()], 500);
        }

        if (! $result['success']) {
            return response()->json(['success' => false, 'message' => $result['message'] ?? 'Unable to generate signature artifacts.'], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $result['document'],
            'message' => $result['message'],
        ]);
    }

    /**
     * List submissions for document
     * GET /api/v1/accounting/compliance/documents/{id}/submissions
     */
    public function getDocumentSubmissions($id)
    {
        $document = $this->findTenantComplianceDocument($id);

        $submissions = $document->submissions()
            ->with(['submittedByUser:id,name', 'cancelledByUser:id,name', 'buyerRejectionRespondedByUser:id,name'])
            ->get();

        return response()->json(['success' => true, 'data' => $submissions]);
    }

    /**
     * Get submission details
     * GET /api/v1/accounting/compliance/submissions/{id}
     */
    public function getSubmission($id)
    {
        $submission = ComplianceSubmission::where('tenant_id', $this->currentTenantId())
            ->with([
                'document',
                'events.createdByUser:id,name',
                'submittedByUser:id,name',
                'cancelledByUser:id,name',
                'buyerRejectionRespondedByUser:id,name',
            ])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $submission]);
    }

    /**
     * Retry failed submission
     * POST /api/v1/accounting/compliance/submissions/{id}/retry
     */
    public function retrySubmission($id)
    {
        $this->authorizeWhenConfigured('submit', ComplianceDocument::class);

        $submission = ComplianceSubmission::where('tenant_id', $this->currentTenantId())
            ->findOrFail($id);

        $result = $this->complianceService->retrySubmission($submission->id);

        if ($result['success']) {
            return response()->json(['success' => true, 'message' => $result['message']]);
        } else {
            return response()->json(['success' => false, 'message' => $result['message']], 400);
        }
    }

    /**
     * Cancel a submission/document.
     * POST /api/v1/accounting/compliance/submissions/{id}/cancel
     */
    public function cancelSubmission(Request $request, $id)
    {
        $this->authorizeWhenConfigured('submit', ComplianceDocument::class);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $submission = ComplianceSubmission::where('tenant_id', $this->currentTenantId())
            ->findOrFail($id);

        $result = $this->complianceService->cancelSubmission($submission->id, $validated['reason'] ?? null);

        if ($result['success']) {
            return response()->json(['success' => true, 'message' => $result['message'], 'data' => $result]);
        }

        return response()->json(['success' => false, 'message' => $result['message']], 400);
    }

    /**
     * Respond to a buyer rejection request.
     * POST /api/v1/accounting/compliance/submissions/{id}/rejection-response
     */
    public function respondToRejectionRequest(Request $request, $id)
    {
        $this->authorizeWhenConfigured('submit', ComplianceDocument::class);

        $validated = $request->validate([
            'decision' => 'required|string|in:cancel,decline',
            'reason' => 'nullable|string|max:500',
        ]);

        $submission = ComplianceSubmission::where('tenant_id', $this->currentTenantId())
            ->findOrFail($id);

        $result = $this->complianceService->respondToBuyerRejection(
            $submission->id,
            $validated['decision'],
            $validated['reason'] ?? null,
        );

        if ($result['success']) {
            return response()->json(['success' => true, 'message' => $result['message'], 'data' => $result]);
        }

        return response()->json(['success' => false, 'message' => $result['message']], 400);
    }

    /**
     * Sync submission status from provider
     * POST /api/v1/accounting/compliance/submissions/{id}/sync
     */
    public function syncSubmission($id)
    {
        $submission = ComplianceSubmission::where('tenant_id', $this->currentTenantId())
            ->findOrFail($id);

        $result = $this->complianceService->syncSubmissionStatusDetailed($submission->id);

        // Reload submission with updated status
        $submission->refresh();

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'data' => $submission,
                'message' => $result['message'] ?? 'Unable to sync status',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $submission,
            'updated' => (bool) ($result['updated'] ?? false),
            'message' => $result['message'] ?? ((bool) ($result['updated'] ?? false) ? 'Status updated' : 'No changes from provider'),
        ]);
    }

    /**
     * Bulk sync pending submissions
     * POST /api/v1/accounting/compliance/sync-pending
     */
    public function syncPending(Request $request)
    {
        $validated = $request->validate([
            'country_code' => 'required|string|size:2|uppercase',
        ]);

        $synced = $this->complianceService->syncPendingSubmissions($validated['country_code']);

        return response()->json([
            'success' => true,
            'synced_count' => $synced,
            'message' => "{$synced} submissions synced",
        ]);
    }

    /**
     * Get country configuration
     * GET /api/v1/accounting/compliance/config/{country_code}
     *
     * Sensitive credential fields (client_secret, private_key, certificate,
     * passphrase) are never returned to the browser — only a boolean
     * indicating whether each is currently set. This prevents secrets from
     * appearing in browser devtools, access logs, or XSS payloads.
     */
    public function getConfig($countryCode)
    {
        $config = $this->complianceService->getCountryConfig($countryCode);

        if (! $config) {
            return response()->json([
                'success' => true,
                'data' => [
                    'country_code' => strtoupper($countryCode),
                    'is_enabled' => false,
                    'test_mode' => true,
                    'credentials' => [],
                    'credentials_complete' => false,
                    'missing_credentials' => ['LHDN API Client ID', 'LHDN API Client Secret', 'Supplier TIN', 'Supplier Name'],
                ],
            ]);
        }

        $creds = $this->readEncryptedConfigArray($config, 'api_credentials');
        $settings = $this->readEncryptedConfigArray($config, 'settings');
        $merged = array_merge($settings, $creds);

        // Check completeness before masking so we can inspect raw secret presence.
        $requiredForSubmission = [
            'myinvoice_client_id' => 'LHDN API Client ID',
            'myinvoice_client_secret' => 'LHDN API Client Secret',
            'supplier_tin' => 'Supplier TIN',
            'supplier_name' => 'Supplier Name',
        ];
        $missingCredentials = [];
        foreach ($requiredForSubmission as $key => $label) {
            if (empty($merged[$key])) {
                $missingCredentials[] = $label;
            }
        }
        $credentialsComplete = empty($missingCredentials);

        // Fields that must never leave the server. Replace each with a
        // boolean "is_set" indicator so the frontend can show ✓/✗ without
        // ever receiving the raw secret value.
        $sensitiveKeys = [
            'myinvoice_client_secret',
            'signature_private_key',
            'signature_private_key_passphrase',
            'signature_certificate',
        ];
        foreach ($sensitiveKeys as $key) {
            $merged[$key.'_set'] = ! empty($merged[$key]);
            unset($merged[$key]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $config->id,
                'country_code' => $config->country_code,
                'provider_name' => $config->provider_name,
                'is_enabled' => $config->is_enabled,
                'test_mode' => $config->test_mode,
                'credentials' => $merged,
                'credentials_complete' => $credentialsComplete,
                'missing_credentials' => $missingCredentials,
            ],
        ]);
    }

    /**
     * Update country configuration
     * PUT /api/v1/accounting/compliance/config/{country_code}
     *
     * For MY the credentials object is stored as a single encrypted blob in
     * api_credentials. The frontend sends individual named fields; this
     * method merges them into the existing blob so that omitting a secret
     * field does NOT clear a previously-saved value (a blank field = keep
     * what was there).
     */
    public function updateConfig(Request $request, $countryCode)
    {
        $this->authorizeWhenConfigured('manageCompliance', ComplianceDocument::class);

        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
            'test_mode' => 'boolean',
            'provider_name' => 'required|string',
            'credentials' => 'nullable|array',
            // MY-specific credential fields (all optional so callers can do partial updates)
            'credentials.myinvoice_client_id' => 'nullable|string|max:500',
            'credentials.myinvoice_client_secret' => 'nullable|string|max:500',
            'credentials.myinvoice_scope' => 'nullable|string|max:100',
            'credentials.supplier_tin' => 'nullable|string|max:20',
            'credentials.supplier_name' => 'nullable|string|max:300',
            'credentials.supplier_registration_number' => 'nullable|string|max:100',
            'credentials.supplier_id_type' => 'nullable|string|in:BRN,NRIC,PASSPORT,ARMY,TIN',
            'credentials.supplier_id_number' => 'nullable|string|max:100',
            'credentials.supplier_is_individual' => 'nullable|boolean',
            'credentials.supplier_msic_code' => 'nullable|string|max:10',
            'credentials.supplier_business_activity_description' => 'nullable|string|max:300',
            'credentials.supplier_phone' => 'nullable|string|max:30',
            'credentials.supplier_email' => 'nullable|email|max:200',
            'credentials.supplier_address' => 'nullable|string|max:300',
            'credentials.supplier_city' => 'nullable|string|max:100',
            'credentials.supplier_state' => 'nullable|string|max:10',
            'credentials.supplier_postal_code' => 'nullable|string|max:10',
            'credentials.signature_enabled' => 'nullable|boolean',
            'credentials.signature_use_for_submission' => 'nullable|boolean',
            'credentials.signature_key_id' => 'nullable|string|max:200',
            'credentials.signature_name' => 'nullable|string|max:200',
            'credentials.signature_private_key' => 'nullable|string',
            'credentials.signature_private_key_passphrase' => 'nullable|string|max:500',
            'credentials.signature_certificate' => 'nullable|string',
        ]);

        $incoming = $validated['credentials'] ?? [];

        // Merge with existing credentials: blank/missing secrets keep the old value.
        $existing = [];
        $current = $this->complianceService->getCountryConfig($countryCode);
        if ($current) {
            $existing = array_merge(
                $this->readEncryptedConfigArray($current, 'settings'),
                $this->readEncryptedConfigArray($current, 'api_credentials'),
            );
        }

        $secretKeys = [
            'myinvoice_client_secret',
            'signature_private_key',
            'signature_private_key_passphrase',
            'signature_certificate',
        ];
        foreach ($secretKeys as $key) {
            if (empty($incoming[$key])) {
                // Frontend did not send a new value — keep what is already stored.
                if (! empty($existing[$key])) {
                    $incoming[$key] = $existing[$key];
                }
                unset($incoming[$key.'_set']); // strip any accidentally forwarded indicator
            }
        }

        $config = $this->complianceService->updateCountryConfig($countryCode, [
            'is_enabled' => $validated['is_enabled'],
            'test_mode' => $validated['test_mode'] ?? false,
            'provider_name' => $validated['provider_name'],
            'api_credentials' => $incoming,
            'settings' => [],
        ]);

        // Return the masked representation (same as getConfig).
        return $this->getConfig($countryCode);
    }

    /**
     * Read an encrypted array without preventing credential recovery when a
     * legacy row was encrypted with another APP_KEY or stored as plaintext.
     */
    private function readEncryptedConfigArray(CountryConfiguration $config, string $attribute): array
    {
        try {
            $value = $config->getAttribute($attribute);

            return is_array($value) ? $value : [];
        } catch (DecryptException $exception) {
            Log::warning('Unreadable encrypted country configuration; awaiting replacement', [
                'configuration_id' => $config->id,
                'country_code' => $config->country_code,
                'attribute' => $attribute,
            ]);

            return [];
        }
    }

    /**
     * List supported countries
     * GET /api/v1/accounting/compliance/countries
     */
    public function listCountries()
    {
        // Issue #100 drive-by fix: the previous `->groupBy('country_code')`
        // produced invalid Postgres SQL (selected columns not aggregated) and
        // 500'd on every call. One row per country, portable across drivers.
        $countries = CountryConfiguration::where('tenant_id', $this->currentTenantId())
            ->select('country_code', 'provider_name', 'is_enabled', 'test_mode')
            ->orderBy('country_code')
            ->get()
            ->unique('country_code')
            ->values();

        return response()->json([
            'success' => true,
            'data' => $countries,
            'total' => count($countries),
        ]);
    }

    /**
     * Export statutory report
     * GET /api/v1/accounting/compliance/reports/{country_code}/{report_type}
     */
    public function exportReport(Request $request, $countryCode, $reportType)
    {
        $validated = $request->validate([
            'as_of_date' => 'required|date',
            'start_date' => 'date',
            'format' => 'string|in:json,xbrl,xml',
        ]);

        $result = $this->complianceService->exportStatutoryReport(
            $countryCode,
            $reportType,
            new \DateTime($validated['as_of_date']),
            $validated
        );

        if ($result['success']) {
            return response()->json(['success' => true, 'data' => $result['report']]);
        } else {
            return response()->json(['success' => false, 'errors' => $result['errors'] ?? $result['message']], 400);
        }
    }

    public function downloadReport(Request $request, $countryCode, $reportType)
    {
        $validated = $request->validate([
            'as_of_date' => 'required|date',
            'start_date' => 'date',
            'strict_schema_validation' => 'boolean',
            'format' => 'string|in:xbrl,excel,xlsx',
        ]);

        $strict = (bool) ($validated['strict_schema_validation'] ?? false);
        $downloadFormat = strtolower((string) ($validated['format'] ?? 'xbrl'));

        $result = $this->complianceService->exportStatutoryReport(
            $countryCode,
            $reportType,
            new \DateTime($validated['as_of_date']),
            array_merge($validated, [
                'format' => $downloadFormat === 'xbrl' ? 'xbrl' : 'json',
                'validate_taxonomy' => $strict,
                'allow_unbalanced' => true,
            ])
        );

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'errors' => $result['errors'] ?? $result['message'] ?? 'Failed to generate report',
            ], 400);
        }

        if (in_array($downloadFormat, ['excel', 'xlsx'], true)) {
            [$headings, $rows] = $this->buildExcelRows($result['report'] ?? [], (string) $reportType);

            $country = strtoupper((string) $countryCode);
            $report = strtolower((string) $reportType);
            $date = (new \DateTime($validated['as_of_date']))->format('Ymd');
            $filename = sprintf('%s_%s_%s.xlsx', $country, $report, $date);

            return Excel::download(
                new ComplianceReportExport($headings, $rows),
                $filename,
                ExcelFormat::XLSX
            );
        }

        $xml = $result['xbrl_xml'] ?? null;
        if (! $xml) {
            return response()->json([
                'success' => false,
                'errors' => ['XBRL XML was not generated by exporter'],
            ], 500);
        }

        $xbrlValidation = $result['xbrl_validation'] ?? null;
        if ($strict && (! is_array($xbrlValidation) || ! ($xbrlValidation['valid'] ?? false))) {
            return response()->json([
                'success' => false,
                'errors' => is_array($xbrlValidation)
                    ? ($xbrlValidation['errors'] ?? ['Schema validation failed'])
                    : ['XBRL taxonomy validation metadata unavailable'],
                'xbrl_validation' => $xbrlValidation,
            ], 422);
        }

        $country = strtoupper((string) $countryCode);
        $report = strtolower((string) $reportType);
        $date = (new \DateTime($validated['as_of_date']))->format('Ymd');
        $filename = sprintf('%s_%s_%s.xbrl', $country, $report, $date);

        $headers = [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Compliance-Country' => $country,
            'X-XBRL-Taxonomy-Validated' => is_array($xbrlValidation) ? (($xbrlValidation['validated'] ?? false) ? 'true' : 'false') : 'false',
            'X-XBRL-Taxonomy-Valid' => is_array($xbrlValidation) ? (($xbrlValidation['valid'] ?? false) ? 'true' : 'false') : 'false',
        ];

        return response($xml, Response::HTTP_OK, $headers);
    }

    private function buildExcelRows(array $report, string $reportType): array
    {
        return match ($reportType) {
            'trial_balance' => $this->buildTrialBalanceExcelRows($report),
            'balance_sheet' => $this->buildBalanceSheetExcelRows($report),
            'income_statement' => $this->buildIncomeStatementExcelRows($report),
            default => [['Metric', 'Value'], [['report_type', $reportType]]],
        };
    }

    private function buildTrialBalanceExcelRows(array $report): array
    {
        $headings = [
            'Account Code',
            'Account Name',
            'Prefix',
            'Element Name',
            'Debit',
            'Credit',
            'Net Balance',
        ];

        $rows = [];

        foreach ($report['accounts'] ?? [] as $account) {
            [$prefix, $element] = self::splitTaxonomyElement((string) ($account['taxonomy_element'] ?? ''));
            $rows[] = [
                $account['account_code'] ?? '',
                $account['account_name'] ?? '',
                $prefix,
                $element,
                $account['debit'] ?? 0,
                $account['credit'] ?? 0,
                $account['net_balance'] ?? 0,
            ];
        }

        $rows[] = ['', 'TOTALS', '', '', $report['totals']['total_debits'] ?? 0, $report['totals']['total_credits'] ?? 0, ''];
        $rows[] = ['', 'Balanced', '', '', ($report['totals']['balanced'] ?? false) ? 'YES' : 'NO', '', ''];

        return [$headings, $rows];
    }

    private function buildBalanceSheetExcelRows(array $report): array
    {
        $headings = [
            'Section',
            'Description',
            'Prefix',
            'Element Name',
            'Balance',
        ];

        $rows = [];

        $rows[] = ['', 'Assets', '', '', ''];

        $rows[] = ['', '  Current assets', '', '', ''];
        $currentAssetsTotal = 0;
        foreach ($report['assets']['current_assets']['items'] ?? [] as $item) {
            $balance = $item['net_balance'] ?? ($item['signed_balance'] ?? 0);
            $currentAssetsTotal += $balance;
            $taxonomyElement = (string) ($item['mapping']['element'] ?? ($item['taxonomy_element'] ?? ''));
            [$prefix, $element] = self::splitTaxonomyElement($taxonomyElement);
            $rows[] = [
                '',
                '    '.($item['account_name'] ?? $item['mapping']['label'] ?? ''),
                $prefix,
                $element,
                $balance,
            ];
        }
        $rows[] = ['', '  Total Current Assets', '', '', $currentAssetsTotal];

        $rows[] = ['', '  Non-current assets', '', '', ''];
        $nonCurrentAssetsTotal = 0;
        foreach ($report['assets']['non_current_assets']['items'] ?? [] as $item) {
            $balance = $item['net_balance'] ?? ($item['signed_balance'] ?? 0);
            $nonCurrentAssetsTotal += $balance;
            $taxonomyElement = (string) ($item['mapping']['element'] ?? ($item['taxonomy_element'] ?? ''));
            [$prefix, $element] = self::splitTaxonomyElement($taxonomyElement);
            $rows[] = [
                '',
                '    '.($item['account_name'] ?? $item['mapping']['label'] ?? ''),
                $prefix,
                $element,
                $balance,
            ];
        }
        $rows[] = ['', '  Total Non-current Assets', '', '', $nonCurrentAssetsTotal];

        $rows[] = ['', 'Total Assets', '', '', $report['totals']['total_assets'] ?? 0];

        $rows[] = ['', '', '', '', ''];
        $rows[] = ['', 'Liabilities', '', '', ''];

        $rows[] = ['', '  Current liabilities', '', '', ''];
        $currentLiabilitiesTotal = 0;
        foreach ($report['liabilities']['current_liabilities']['items'] ?? [] as $item) {
            $balance = $item['net_balance'] ?? ($item['signed_balance'] ?? 0);
            $currentLiabilitiesTotal += $balance;
            $taxonomyElement = (string) ($item['mapping']['element'] ?? ($item['taxonomy_element'] ?? ''));
            [$prefix, $element] = self::splitTaxonomyElement($taxonomyElement);
            $rows[] = [
                '',
                '    '.($item['account_name'] ?? $item['mapping']['label'] ?? ''),
                $prefix,
                $element,
                $balance,
            ];
        }
        $rows[] = ['', '  Total Current Liabilities', '', '', $currentLiabilitiesTotal];

        $rows[] = ['', '  Non-current liabilities', '', '', ''];
        $nonCurrentLiabilitiesTotal = 0;
        foreach ($report['liabilities']['non_current_liabilities']['items'] ?? [] as $item) {
            $balance = $item['net_balance'] ?? ($item['signed_balance'] ?? 0);
            $nonCurrentLiabilitiesTotal += $balance;
            $taxonomyElement = (string) ($item['mapping']['element'] ?? ($item['taxonomy_element'] ?? ''));
            [$prefix, $element] = self::splitTaxonomyElement($taxonomyElement);
            $rows[] = [
                '',
                '    '.($item['account_name'] ?? $item['mapping']['label'] ?? ''),
                $prefix,
                $element,
                $balance,
            ];
        }
        $rows[] = ['', '  Total Non-current Liabilities', '', '', $nonCurrentLiabilitiesTotal];

        $rows[] = ['', 'Total Liabilities', '', '', $report['totals']['total_liabilities'] ?? 0];

        $rows[] = ['', '', '', '', ''];
        $rows[] = ['', 'Equity', '', '', ''];

        $rows[] = ['', '  Share capital', '', '', ''];
        $shareCapital = 0;
        foreach ($report['equity']['capital_and_reserves']['items'] ?? [] as $item) {
            if (str_contains(strtolower($item['account_name'] ?? ''), 'share') ||
                str_contains(strtolower($item['mapping']['label'] ?? ''), 'share')) {
                $balance = $item['net_balance'] ?? ($item['signed_balance'] ?? 0);
                $shareCapital += $balance;
                $taxonomyElement = (string) ($item['mapping']['element'] ?? ($item['taxonomy_element'] ?? ''));
                [$prefix, $element] = self::splitTaxonomyElement($taxonomyElement);
                $rows[] = [
                    '',
                    '    '.($item['account_name'] ?? $item['mapping']['label'] ?? ''),
                    $prefix,
                    $element,
                    $balance,
                ];
            }
        }
        if ($shareCapital > 0 || ! empty($report['equity']['capital_and_reserves']['items'] ?? [])) {
            $rows[] = ['', '  Total Share Capital', '', '', $shareCapital];
        }

        $rows[] = ['', '  Accumulated profits (losses)', '', '', ''];
        $accumulatedProfits = 0;
        foreach ($report['equity']['capital_and_reserves']['items'] ?? [] as $item) {
            if ((str_contains(strtolower($item['account_name'] ?? ''), 'accum') ||
                 str_contains(strtolower($item['mapping']['label'] ?? ''), 'accum') ||
                 str_contains(strtolower($item['account_name'] ?? ''), 'retain') ||
                 str_contains(strtolower($item['mapping']['label'] ?? ''), 'retain')) &&
                ! str_contains(strtolower($item['account_name'] ?? ''), 'share')) {
                $balance = $item['net_balance'] ?? ($item['signed_balance'] ?? 0);
                $accumulatedProfits += $balance;
                $taxonomyElement = (string) ($item['mapping']['element'] ?? ($item['taxonomy_element'] ?? ''));
                [$prefix, $element] = self::splitTaxonomyElement($taxonomyElement);
                $rows[] = [
                    '',
                    '    '.($item['account_name'] ?? $item['mapping']['label'] ?? ''),
                    $prefix,
                    $element,
                    $balance,
                ];
            }
        }
        if ($accumulatedProfits > 0 || ! empty($report['equity']['capital_and_reserves']['items'] ?? [])) {
            $rows[] = ['', '  Total Accumulated Profits', '', '', $accumulatedProfits];
        }

        $rows[] = ['', 'Total Equity', '', '', $report['totals']['total_equity'] ?? 0];

        $rows[] = ['', '', '', '', ''];
        $rows[] = ['', 'Total Liabilities and Equity', '', '', $report['totals']['total_liabilities_and_equity'] ?? 0];

        return [$headings, $rows];
    }

    private function buildIncomeStatementExcelRows(array $report): array
    {
        $headings = [
            'Section',
            'Description',
            'Prefix',
            'Element Name',
            'Balance',
        ];

        $rows = [];

        $rows[] = ['', 'Revenue', '', '', ''];
        $revenueTotal = 0;
        foreach ($report['revenue']['items'] ?? [] as $item) {
            $balance = $item['signed_balance'] ?? ($item['net_balance'] ?? 0);
            $revenueTotal += $balance;
            $taxonomyElement = (string) ($item['mapping']['element'] ?? ($item['taxonomy_element'] ?? ''));
            [$prefix, $element] = self::splitTaxonomyElement($taxonomyElement);
            $rows[] = [
                '',
                '  '.($item['account_name'] ?? $item['mapping']['label'] ?? ''),
                $prefix,
                $element,
                $balance,
            ];
        }
        $rows[] = ['', 'Total Revenue', '', '', $revenueTotal];

        $rows[] = ['', '', '', '', ''];
        $rows[] = ['', 'Cost of Goods Sold', '', '', ''];
        $cogTotal = 0;
        foreach ($report['cost_of_goods_sold']['items'] ?? [] as $item) {
            $balance = $item['signed_balance'] ?? ($item['net_balance'] ?? 0);
            $cogTotal += $balance;
            $taxonomyElement = (string) ($item['mapping']['element'] ?? ($item['taxonomy_element'] ?? ''));
            [$prefix, $element] = self::splitTaxonomyElement($taxonomyElement);
            $rows[] = [
                '',
                '  '.($item['account_name'] ?? $item['mapping']['label'] ?? ''),
                $prefix,
                $element,
                $balance,
            ];
        }
        $rows[] = ['', 'Total Cost of Goods Sold', '', '', $cogTotal];

        $rows[] = ['', 'Gross Profit', '', '', $report['gross_profit'] ?? 0];

        $rows[] = ['', '', '', '', ''];
        $rows[] = ['', 'Operating Expenses', '', '', ''];
        $opexTotal = 0;
        foreach ($report['operating_expenses']['items'] ?? [] as $item) {
            $balance = $item['signed_balance'] ?? ($item['net_balance'] ?? 0);
            $opexTotal += $balance;
            $taxonomyElement = (string) ($item['mapping']['element'] ?? ($item['taxonomy_element'] ?? ''));
            [$prefix, $element] = self::splitTaxonomyElement($taxonomyElement);
            $rows[] = [
                '',
                '  '.($item['account_name'] ?? $item['mapping']['label'] ?? ''),
                $prefix,
                $element,
                $balance,
            ];
        }
        $rows[] = ['', 'Total Operating Expenses', '', '', $opexTotal];

        $rows[] = ['', 'Operating Income', '', '', $report['operating_income'] ?? 0];

        $rows[] = ['', '', '', '', ''];
        $rows[] = ['', 'Other Income', '', '', ''];
        $otherIncomeTotal = 0;
        foreach ($report['other_income']['items'] ?? [] as $item) {
            $balance = $item['signed_balance'] ?? ($item['net_balance'] ?? 0);
            $otherIncomeTotal += $balance;
            $taxonomyElement = (string) ($item['mapping']['element'] ?? ($item['taxonomy_element'] ?? ''));
            [$prefix, $element] = self::splitTaxonomyElement($taxonomyElement);
            $rows[] = [
                '',
                '  '.($item['account_name'] ?? $item['mapping']['label'] ?? ''),
                $prefix,
                $element,
                $balance,
            ];
        }
        if ($otherIncomeTotal > 0 || ! empty($report['other_income']['items'] ?? [])) {
            $rows[] = ['', 'Total Other Income', '', '', $otherIncomeTotal];
        }

        $rows[] = ['', '', '', '', ''];
        $rows[] = ['', 'Other Expenses', '', '', ''];
        $otherExpenseTotal = 0;
        foreach ($report['other_expenses']['items'] ?? [] as $item) {
            $balance = $item['signed_balance'] ?? ($item['net_balance'] ?? 0);
            $otherExpenseTotal += $balance;
            $taxonomyElement = (string) ($item['mapping']['element'] ?? ($item['taxonomy_element'] ?? ''));
            [$prefix, $element] = self::splitTaxonomyElement($taxonomyElement);
            $rows[] = [
                '',
                '  '.($item['account_name'] ?? $item['mapping']['label'] ?? ''),
                $prefix,
                $element,
                $balance,
            ];
        }
        if ($otherExpenseTotal > 0 || ! empty($report['other_expenses']['items'] ?? [])) {
            $rows[] = ['', 'Total Other Expenses', '', '', $otherExpenseTotal];
        }

        $rows[] = ['', '', '', '', ''];
        $rows[] = ['', 'Net Income', '', '', $report['net_income'] ?? 0];

        return [$headings, $rows];
    }

    private static function splitTaxonomyElement(string $taxonomyElement): array
    {
        if ($taxonomyElement === '') {
            return ['', ''];
        }

        if (! str_contains($taxonomyElement, ':')) {
            return ['sg-sfrs', $taxonomyElement];
        }

        [$prefix, $element] = explode(':', $taxonomyElement, 2);

        return [$prefix, $element];
    }

    /**
     * Get submission events (audit trail)
     * GET /api/v1/accounting/compliance/submissions/{id}/events
     */
    public function getSubmissionEvents($id)
    {
        $submission = ComplianceSubmission::where('tenant_id', $this->currentTenantId())
            ->findOrFail($id);

        $events = $submission->events()
            ->with('createdByUser:id,name')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json(['success' => true, 'data' => $events]);
    }
}
