<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\Journal;
use App\Models\LockDate;
use App\Services\AccountingService;
use App\Services\LockDateValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Journal Entries Controller - Manages manual journal entries
 */
class JournalEntriesController extends Controller
{
    public function __construct(
        private AccountingService $accountingService,
        private LockDateValidationService $lockDateService
    ) {}

    /**
     * Get all journal entries with filters
     */
    public function index(Request $request)
    {
        $query = JournalEntry::with(['lines.account', 'journal', 'createdBy', 'postedBy']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by journal
        if ($request->has('journal_id')) {
            $query->where('journal_id', $request->journal_id);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('entry_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('entry_date', '<=', $request->end_date);
        }

        // Search by entry number or description
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('entry_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%");
            });
        }

        $entries = $query->orderBy('entry_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($request->input('per_page', 50));

        return response()->json($entries);
    }

    /**
     * Get a single journal entry
     */
    public function show($id)
    {
        $entry = JournalEntry::with([
            'lines.account',
            'lines.currency',
            'lines.department',
            'lines.project',
            'lines.location',
            'journal',
            'createdBy',
            'postedBy',
            'reversedEntry',
            'reversalEntry'
        ])->findOrFail($id);

        return response()->json($entry);
    }

    /**
     * Create a new journal entry
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'journal_id' => 'nullable|exists:journals,id',
            'entry_date' => 'required|date',
            'reference' => 'nullable|string|max:100',
            'description' => 'required|string',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|exists:chart_of_accounts,id',
            'lines.*.debit' => 'required|numeric|min:0',
            'lines.*.credit' => 'required|numeric|min:0',
            'lines.*.description' => 'nullable|string',
            'lines.*.reference' => 'nullable|string',
            'lines.*.currency_code' => 'nullable|exists:currencies,code',
            'lines.*.original_amount' => 'nullable|numeric',
            'lines.*.exchange_rate' => 'nullable|numeric',
            'lines.*.department_id' => 'nullable|exists:analytic_accounts,id',
            'lines.*.project_id' => 'nullable|exists:analytic_accounts,id',
            'lines.*.location_id' => 'nullable|exists:analytic_accounts,id',
        ]);

        // Validate that each line has either debit or credit (not both)
        foreach ($validated['lines'] as $index => $line) {
            if (($line['debit'] > 0 && $line['credit'] > 0) || ($line['debit'] == 0 && $line['credit'] == 0)) {
                return response()->json([
                    'message' => "Line " . ($index + 1) . " must have either debit or credit, not both or neither",
                ], 422);
            }
        }

        try {
            $entry = $this->accountingService->createJournalEntry([
                'journal_id' => $validated['journal_id'] ?? null,
                'entry_date' => $validated['entry_date'],
                'type' => 'manual',
                'reference' => $validated['reference'] ?? null,
                'description' => $validated['description'],
                'status' => 'draft',
            ], $validated['lines']);

            return response()->json([
                'message' => 'Journal entry created successfully',
                'entry' => $entry->load('lines.account'),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error creating journal entry',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update a journal entry (draft only)
     */
    public function update(Request $request, $id)
    {
        $entry = JournalEntry::findOrFail($id);

        if ($entry->status === 'posted') {
            return response()->json([
                'message' => 'Cannot edit posted journal entries',
            ], 422);
        }

        $validated = $request->validate([
            'journal_id' => 'nullable|exists:journals,id',
            'entry_date' => 'required|date',
            'reference' => 'nullable|string|max:100',
            'description' => 'required|string',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|exists:chart_of_accounts,id',
            'lines.*.debit' => 'required|numeric|min:0',
            'lines.*.credit' => 'required|numeric|min:0',
            'lines.*.description' => 'nullable|string',
            'lines.*.reference' => 'nullable|string',
            'lines.*.currency_code' => 'nullable|exists:currencies,code',
            'lines.*.original_amount' => 'nullable|numeric',
            'lines.*.exchange_rate' => 'nullable|numeric',
            'lines.*.department_id' => 'nullable|exists:analytic_accounts,id',
            'lines.*.project_id' => 'nullable|exists:analytic_accounts,id',
            'lines.*.location_id' => 'nullable|exists:analytic_accounts,id',
        ]);

        DB::beginTransaction();
        try {
            // Update entry
            $entry->update([
                'journal_id' => $validated['journal_id'] ?? null,
                'entry_date' => $validated['entry_date'],
                'reference' => $validated['reference'] ?? null,
                'description' => $validated['description'],
            ]);

            // Delete existing lines
            $entry->lines()->delete();

            // Create new lines
            foreach ($validated['lines'] as $line) {
                $entry->lines()->create($line);
            }

            DB::commit();

            return response()->json([
                'message' => 'Journal entry updated successfully',
                'entry' => $entry->fresh('lines.account'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error updating journal entry',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Delete a journal entry (draft only)
     */
    public function destroy($id)
    {
        $entry = JournalEntry::findOrFail($id);

        if ($entry->status === 'posted') {
            return response()->json([
                'message' => 'Cannot delete posted journal entries. Use reverse instead.',
            ], 422);
        }

        $entry->delete();

        return response()->json([
            'message' => 'Journal entry deleted successfully',
        ]);
    }

    /**
     * Post a journal entry
     */
    public function post($id, Request $request)
    {
        $entry = JournalEntry::with('lines')->findOrFail($id);

        if ($entry->status === 'posted') {
            return response()->json([
                'message' => 'Journal entry is already posted',
            ], 422);
        }

        // Validate lock date
        $lockValidation = $this->lockDateService->validateTransactionDate('journal_entries', $entry->entry_date);
        
        if ($lockValidation['is_locked']) {
            // Check if override is provided
            if (!$request->has('override_reason')) {
                return response()->json([
                    'message' => $lockValidation['message'],
                    'requires_override' => true,
                    'lock_date' => $lockValidation['lock_date'],
                ], 422);
            }

            // Log the override
            $this->lockDateService->logOverride(
                auth()->id(),
                'journal_entry',
                $entry->id,
                $entry->entry_date,
                $request->override_reason,
                auth()->id()
            );
        }

        try {
            $entry->post();

            return response()->json([
                'message' => 'Journal entry posted successfully',
                'entry' => $entry->fresh('lines.account'),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error posting journal entry',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reverse a journal entry
     */
    public function reverse($id, Request $request)
    {
        $entry = JournalEntry::with('lines')->findOrFail($id);

        $validated = $request->validate([
            'reason' => 'nullable|string',
        ]);

        try {
            $reversalEntry = $entry->reverse($validated['reason'] ?? null);

            return response()->json([
                'message' => 'Journal entry reversed successfully',
                'reversal_entry' => $reversalEntry->load('lines.account'),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error reversing journal entry',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Validate entry date against lock dates
     */
    public function validateDate(Request $request)
    {
        $validated = $request->validate([
            'entry_date' => 'required|date',
        ]);

        $result = $this->lockDateService->validateTransactionDate('journal_entries', $validated['entry_date']);

        return response()->json($result);
    }
}
