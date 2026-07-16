<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VendorDebitNote;
use App\Services\VendorDebitNoteService;
use Illuminate\Http\Request;

class VendorDebitNotesController extends Controller
{
    public function __construct(
        private VendorDebitNoteService $debitNoteService
    ) {}

    public function index(Request $request)
    {
        $query = VendorDebitNote::with(['vendor', 'bill', 'lines'])
            ->orderBy('debit_note_date', 'desc');

        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->has('bill_id')) {
            $query->where('bill_id', $request->bill_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('start_date')) {
            $query->where('debit_note_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('debit_note_date', '<=', $request->end_date);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('debit_note_number', 'ILIKE', "%{$search}%")
                    ->orWhereHas('vendor', function ($vq) use ($search) {
                        $vq->where('name', 'ILIKE', "%{$search}%");
                    });
            });
        }

        $debitNotes = $query->paginate(20);

        return response()->json($debitNotes);
    }

    public function show($debitNote)
    {
        $debitNote = VendorDebitNote::findOrFail($debitNote);

        return response()->json(
            $debitNote->load([
                'vendor',
                'bill',
                'lines.product',
                'lines.account',
                'journalEntry.lines.account',
                'createdBy',
                'postedBy',
            ])
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'bill_id' => 'required|exists:bills,id',
            'debit_note_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'reason' => 'nullable|string',
            'notes' => 'nullable|string',
            'currency_code' => 'nullable|string|size:3',
            'lines' => 'required|array|min:1',
            'lines.*.bill_line_id' => 'nullable|exists:bill_lines,id',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.description' => 'required|string',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.account_id' => 'nullable|exists:chart_of_accounts,id',
        ]);

        try {
            $debitNote = $this->debitNoteService->createDebitNote($validated);

            return response()->json($debitNote, 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create debit note',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $debitNote)
    {
        $debitNote = VendorDebitNote::findOrFail($debitNote);
        if ($debitNote->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft debit notes can be edited',
            ], 403);
        }

        $validated = $request->validate([
            'debit_note_date' => 'date',
            'due_date' => 'nullable|date',
            'reason' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $debitNote->update($validated);

        return response()->json($debitNote->fresh(['vendor', 'bill', 'lines']));
    }

    public function destroy($debitNote)
    {
        $debitNote = VendorDebitNote::findOrFail($debitNote);
        if ($debitNote->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft debit notes can be deleted. Use reversal for posted debit notes.',
                'suggestion' => 'Create a reversal journal entry to correct this debit note.',
            ], 403);
        }

        $debitNote->delete();

        return response()->json(['message' => 'Debit note deleted successfully']);
    }

    public function post($debitNote)
    {
        $debitNote = VendorDebitNote::findOrFail($debitNote);
        if ($debitNote->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft debit notes can be posted',
            ], 403);
        }

        try {
            $posted = $this->debitNoteService->postDebitNote($debitNote);

            return response()->json([
                'message' => 'Debit note posted successfully',
                'debit_note' => $posted,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to post debit note',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getByBill($billId)
    {
        $debitNotes = VendorDebitNote::where('bill_id', $billId)
            ->with(['lines', 'createdBy', 'postedBy'])
            ->orderBy('debit_note_date', 'desc')
            ->get();

        return response()->json(['data' => $debitNotes]);
    }

    public function validateDebitNote(Request $request)
    {
        $validated = $request->validate([
            'bill_id' => 'required|exists:bills,id',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $validation = $this->debitNoteService->validateDebitNote(
            $validated['bill_id'],
            $validated['amount']
        );

        return response()->json($validation);
    }
}
