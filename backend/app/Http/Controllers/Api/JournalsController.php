<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalsController extends Controller
{
    /**
     * Get all journals
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Journal::with(['defaultDebitAccount', 'defaultCreditAccount']);

            // Filter by type
            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'ILIKE', "%{$search}%")
                        ->orWhere('name', 'ILIKE', "%{$search}%");
                });
            }

            $journals = $query->orderBy('code')->get();

            return response()->json([
                'data' => $journals,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch journals',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get single journal
     */
    public function show(int $id): JsonResponse
    {
        try {
            $journal = Journal::with(['defaultDebitAccount', 'defaultCreditAccount'])->findOrFail($id);

            return response()->json([
                'data' => $journal,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Journal not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Create new journal
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'code' => 'required|string|max:20|unique:journals,code',
                'name' => 'required|string|max:255',
                'type' => 'required|in:general,sales,purchase,cash,bank',
                'default_debit_account_id' => 'nullable|exists:chart_of_accounts,id',
                'default_credit_account_id' => 'nullable|exists:chart_of_accounts,id',
                'sequence_prefix' => 'required|string|max:10',
                'sequence_number' => 'integer|min:1',
                'is_active' => 'boolean',
            ]);

            $validated['sequence_number'] = $validated['sequence_number'] ?? 1;

            $journal = Journal::create($validated);

            return response()->json([
                'data' => $journal,
                'message' => 'Journal created successfully',
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create journal',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update journal
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $journal = Journal::findOrFail($id);

            $validated = $request->validate([
                'code' => 'required|string|max:20|unique:journals,code,'.$id,
                'name' => 'required|string|max:255',
                'type' => 'required|in:general,sales,purchase,cash,bank',
                'default_debit_account_id' => 'nullable|exists:chart_of_accounts,id',
                'default_credit_account_id' => 'nullable|exists:chart_of_accounts,id',
                'sequence_prefix' => 'required|string|max:10',
                'is_active' => 'boolean',
            ]);

            $journal->update($validated);

            return response()->json([
                'data' => $journal,
                'message' => 'Journal updated successfully',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update journal',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete journal
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $journal = Journal::findOrFail($id);

            // Check if journal has entries
            if ($journal->journalEntries()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete journal with entries',
                ], 403);
            }

            $journal->delete();

            return response()->json([
                'message' => 'Journal deleted successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete journal',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get next entry number for a journal
     */
    public function getNextEntryNumber(int $id): JsonResponse
    {
        try {
            $journal = Journal::findOrFail($id);
            $nextNumber = $journal->getNextEntryNumber();

            return response()->json([
                'data' => [
                    'entry_number' => $nextNumber,
                    'journal_id' => $journal->id,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate next entry number',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
