<?php

namespace App\Http\Controllers\Api;

use App\Accounting\Services\EInvoiceLifecycleGuard;
use App\Http\Controllers\Controller;
use App\Models\CustomerCreditNote;
use App\Services\CustomerCreditNoteService;
use App\Services\CustomerWideSearchService;
use App\Services\DocumentWideSearchService;
use Illuminate\Http\Request;

class CustomerCreditNotesController extends Controller
{
    public function __construct(
        private CustomerCreditNoteService $creditNoteService,
        private EInvoiceLifecycleGuard $eInvoiceLifecycle,
    ) {}

    /**
     * List all credit notes
     */
    public function index(Request $request)
    {
        $query = CustomerCreditNote::with(['customer', 'invoice', 'lines'])
            ->orderBy('credit_note_date', 'desc');

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filter by invoice
        if ($request->has('invoice_id')) {
            $query->where('invoice_id', $request->invoice_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('credit_note_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('credit_note_date', '<=', $request->end_date);
        }

        // Search — wide: document refs, customer's full surface, line contents
        // and the credited invoice number.
        $search = $request->filled('search') ? trim((string) $request->search) : '';
        if ($search !== '') {
            app(DocumentWideSearchService::class)->apply($query, $search, $this->wideSearchConfig());
            $query->with(app(DocumentWideSearchService::class)->eagerLoads($this->wideSearchConfig()));
        }

        $creditNotes = $query->paginate(20);

        if ($search !== '') {
            app(DocumentWideSearchService::class)->annotate($creditNotes, $search, $this->wideSearchConfig());
        }

        return response()->json($creditNotes);
    }

    /**
     * Wide-search configuration for the customer credit-note list.
     */
    private function wideSearchConfig(): array
    {
        return [
            'columns' => ['credit_note_number', 'reason', 'notes'],
            'party' => [
                'relation' => 'customer',
                'service' => CustomerWideSearchService::class,
                'contacts' => 'customerContacts',
                'addresses' => 'addresses',
            ],
            'lines' => ['relation' => 'lines', 'columns' => ['description']],
            'related' => ['invoice' => 'invoice_number'],
        ];
    }

    /**
     * Get single credit note
     */
    public function show($creditNote)
    {
        $creditNote = CustomerCreditNote::findOrFail($creditNote);

        return response()->json(
            $creditNote->load([
                'customer',
                'invoice',
                'lines.product',
                'lines.account',
                'journalEntry.lines.account',
                'createdBy',
                'postedBy',
            ])
        );
    }

    /**
     * Create draft credit note
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'invoice_id' => 'required|exists:invoices,id',
            'credit_note_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'reason' => 'nullable|string',
            'notes' => 'nullable|string',
            'currency_code' => 'nullable|string|size:3',
            'lines' => 'required|array|min:1',
            'lines.*.invoice_line_id' => 'nullable|exists:invoice_lines,id',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.description' => 'required|string',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.tax_inclusive' => 'nullable|boolean',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            'lines.*.account_id' => 'nullable|exists:chart_of_accounts,id',
        ]);

        try {
            $creditNote = $this->creditNoteService->createCreditNote($validated);

            return response()->json([
                'message' => 'Credit note created successfully',
                'id' => $creditNote->id,
                'data' => $creditNote,
            ], 201);

        } catch (\RuntimeException $e) {
            \Log::error('Credit note creation failed (runtime)', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to create credit note',
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Credit note creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to create credit note',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update draft credit note
     */
    public function update(Request $request, $creditNote)
    {
        $creditNote = CustomerCreditNote::findOrFail($creditNote);
        if ($creditNote->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft credit notes can be edited',
            ], 403);
        }

        $validated = $request->validate([
            'credit_note_date' => 'date',
            'due_date' => 'nullable|date',
            'reason' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $creditNote->update($validated);

        return response()->json($creditNote->fresh(['customer', 'invoice', 'lines']));
    }

    /**
     * Delete draft credit note
     */
    public function destroy($creditNote)
    {
        $creditNote = CustomerCreditNote::findOrFail($creditNote);
        if ($creditNote->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft credit notes can be deleted. Use reversal for posted credit notes.',
                'suggestion' => 'Create a reversal journal entry to correct this credit note.',
            ], 403);
        }

        $creditNote->delete();

        return response()->json(['message' => 'Credit note deleted successfully']);
    }

    /**
     * Post credit note (creates GL entries)
     */
    public function post($creditNote)
    {
        $creditNote = CustomerCreditNote::findOrFail($creditNote);
        if ($creditNote->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft credit notes can be posted',
            ], 403);
        }

        try {
            $posted = $this->creditNoteService->postCreditNote($creditNote);

            return response()->json([
                'message' => 'Credit note posted successfully',
                'credit_note' => $posted,
            ]);

        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => 'Failed to post credit note',
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to post credit note',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Void a posted credit note (reverses its GL entry, restores the invoice
     * balance, and unblocks invoice cancel/reset-to-draft — issue #228).
     */
    public function void($creditNote)
    {
        $creditNote = CustomerCreditNote::findOrFail($creditNote);

        if ($creditNote->status !== 'posted') {
            return response()->json([
                'message' => 'Only posted credit notes can be voided',
            ], 403);
        }

        if ($denied = $this->eInvoiceLifecycle->denial('credit_note', $creditNote->id, 'void')) {
            return $denied;
        }

        try {
            $voided = $this->creditNoteService->voidCreditNote($creditNote);

            return response()->json([
                'message' => 'Credit note voided successfully',
                'credit_note' => $voided,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to void credit note',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get credit notes for a specific invoice
     */
    public function getByInvoice($invoiceId)
    {
        $creditNotes = CustomerCreditNote::where('invoice_id', $invoiceId)
            ->with(['lines', 'createdBy', 'postedBy'])
            ->orderBy('credit_note_date', 'desc')
            ->get();

        return response()->json(['data' => $creditNotes]);
    }

    /**
     * Validate credit note amount
     */
    public function validateCreditNote(Request $request)
    {
        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $validation = $this->creditNoteService->validateCreditNote(
            $validated['invoice_id'],
            $validated['amount']
        );

        return response()->json($validation);
    }
}
