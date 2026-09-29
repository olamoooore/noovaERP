<?php

namespace App\Http\Controllers\Api;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\DraftJournalRequest;
use App\Accounting\DTOs\ReversalRequest;
use App\Exports\JournalEntriesExport;
use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Services\EntityAccessService;
use App\Services\FiscalPeriodValidator;
use App\Services\LockDateValidationService;
use App\Services\ManualJournalPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * Journal Entries Controller - Manages manual journal entries
 */
class JournalEntriesController extends Controller
{
    public function __construct(
        private LockDateValidationService $lockDateService,
        private FiscalPeriodValidator $fiscalValidator,
        private PostingEngineInterface $postingEngine,
        private ManualJournalPostingService $manualJournalService,
        private EntityAccessService $entityAccessService,
    ) {}

    private function getDraftJournalRequest(Request $request): DraftJournalRequest
    {
        $validated = $request->validate([
            'journal_id' => 'nullable|exists:journals,id',
            'entry_date' => 'required|date',
            'reference' => 'nullable|string|max:100',
            'description' => 'required|string',
            'remark' => 'nullable|string|max:255',
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

        return DraftJournalRequest::fromArray([
            ...$validated,
            'user_id' => $request->user()?->id,
            'legal_entity_id' => $request->attributes->get('legal_entity_id'),
            'entity_book_id' => $request->attributes->get('entity_book_id'),
        ]);
    }

    /**
     * Get all journal entries with filters
     */
    public function index(Request $request)
    {
        $query = JournalEntry::with(['lines.account', 'journal', 'createdBy', 'postedBy', 'reversedEntry:id,entry_number']);

        // Issue #163 (adjacent fix): filter by the user's accessible legal
        // entities. Fail-open when the user has no entity grants, matching
        // EnsureEntityAccess and the dashboard/sales-order scoping.
        $user = $request->user();
        $accessibleEntityIds = $user
            ? $this->entityAccessService->accessibleEntityIds((int) $user->id)
            : [];

        if ($accessibleEntityIds !== []) {
            $query->accessibleBy($accessibleEntityIds);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Reversal postings are their own state in the UI (RE- entries): they
        // show under the "Reversed" tab, never under "Posted" — they are
        // cancellations, not new posted entries. Reports already exclude the
        // reversed pairs via is_active_version, so this is list presentation.
        if ($request->input('status') === 'posted' && ! $request->boolean('is_reversal')) {
            // Reversed originals keep status='posted' (the status enum has no
            // 'reversed'); their lifecycle_status is the authoritative flag,
            // so they must not be listed as still-posted entries.
            $query->where('is_reversal', false)
                ->where('lifecycle_status', '!=', 'reversed');
        }

        // Filter by reversal
        if ($request->has('is_reversal')) {
            $query->where('is_reversal', filter_var($request->is_reversal, FILTER_VALIDATE_BOOLEAN));
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

        // Search across the fields users actually reference: entry number,
        // description, reference, remark, journal name, account code/name and
        // the creating/posting user. `ilike` keeps it case-insensitive on
        // Postgres (plain `like` is case-sensitive).
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $like = "%{$search}%";

            $query->where(function ($q) use ($like) {
                $q->where('entry_number', 'ilike', $like)
                    ->orWhere('description', 'ilike', $like)
                    ->orWhere('reference', 'ilike', $like)
                    ->orWhere('remark', 'ilike', $like)
                    ->orWhereHas('journal', fn ($journal) => $journal->where('name', 'ilike', $like))
                    ->orWhereHas('lines.account', function ($account) use ($like) {
                        $account->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like);
                    })
                    ->orWhereHas('createdBy', fn ($user) => $user->where('name', 'ilike', $like))
                    ->orWhereHas('postedBy', fn ($user) => $user->where('name', 'ilike', $like));
            });
        }

        if ($request->boolean('ids_only')) {
            $ids = $query->orderBy('entry_date', 'desc')->orderBy('id', 'desc')->pluck('id');

            return response()->json(['data' => $ids, 'total' => $ids->count()]);
        }

        $entries = $query->orderBy('entry_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($request->input('per_page', 50));

        $this->attachReferenceLinks($entries->getCollection());

        return response()->json($entries);
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'columns' => ['required', 'array', 'min:1', 'max:'.count(JournalEntriesExport::COLUMNS)],
            'columns.*' => ['required', 'string', 'distinct', 'in:'.implode(',', array_keys(JournalEntriesExport::COLUMNS))],
            'document_ids' => ['nullable', 'array'],
            'document_ids.*' => ['integer', 'distinct', 'exists:journal_entries,id'],
            'format' => ['nullable', 'in:csv,xlsx'],
        ]);
        $query = JournalEntry::query();
        if ($request->filled('document_ids')) {
            $query->whereIn('id', $request->input('document_ids'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('is_reversal')) {
            $query->where('is_reversal', filter_var($request->is_reversal, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('start_date')) {
            $query->whereDate('entry_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('entry_date', '<=', $request->end_date);
        }
        if ($request->filled('search')) {
            $search = (string) $request->search;
            $query->where(fn ($q) => $q->where('entry_number', 'LIKE', "%{$search}%")->orWhere('description', 'LIKE', "%{$search}%")->orWhere('reference', 'LIKE', "%{$search}%"));
        }

        $format = $validated['format'] ?? 'xlsx';

        return Excel::download(
            new JournalEntriesExport($query->orderByDesc('entry_date')->orderByDesc('id'), $validated['columns']),
            'journal_entries_'.now()->format('Y-m-d').'.'.$format,
            $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
        );
    }

    /**
     * Get a single journal entry.
     *
     * Issue #97 (CRITICAL, ENTITY-1): cross-entity data isolation. The query
     * is scoped to the requesting user's accessible legal entities so an
     * Accountant scoped to Entity A cannot read Entity B's journal entry by
     * ID. findOrFail returns 404 for cross-entity requests (the entry exists
     * but is not in the user's scope) — not 200 with the record's data.
     */
    public function show($id, Request $request)
    {
        $user = $request->user();
        // Issue #163 (adjacent fix): getAccessibleEntities returns legal_entities
        // rows whose key is `id`; the previous array_column('legal_entity_id')
        // always produced an empty set, so this endpoint 404'd for EVERY user
        // (including in-scope ones). Use the shared id helper.
        $accessibleEntityIds = $user
            ? $this->entityAccessService->accessibleEntityIds((int) $user->id)
            : [];

        $query = JournalEntry::with([
            'lines.account',
            'lines.currency',
            'lines.department',
            'lines.project',
            'lines.location',
            'journal',
            'createdBy',
            'postedBy',
            'reversedEntry',
            'reversalEntry',
        ]);

        // Only scope when the user actually has entity access — matching
        // index(). Calling accessibleBy([]) fails closed (1 = 0) and 404'd
        // every entry for users/tenants without user_entity_access rows.
        if ($accessibleEntityIds !== []) {
            $query->accessibleBy($accessibleEntityIds);
        }

        $entry = $query->findOrFail($id);

        // Same traceability payload as the list so the detail dialog's
        // Reference is clickable too.
        $this->attachReferenceLinks(collect([$entry]));

        return response()->json($entry);
    }

    /**
     * Import balanced draft journal entries from CSV.
     * Rows sharing an entry_key are grouped into one journal entry.
     */
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:10240']);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return response()->json(['message' => 'Unable to read the uploaded CSV file.'], 422);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return response()->json(['message' => 'The CSV file is empty.'], 422);
        }
        $header = array_map(fn ($value) => strtolower(trim((string) $value)), $header);
        $required = ['entry_key', 'entry_date', 'description', 'account_code', 'debit', 'credit'];
        $missing = array_values(array_diff($required, $header));
        if ($missing !== []) {
            fclose($handle);

            return response()->json(['message' => 'Missing required CSV columns: '.implode(', ', $missing)], 422);
        }

        $groups = [];
        $errors = [];
        $rowNumber = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $values = array_pad($values, count($header), '');
            $row = array_combine($header, array_slice($values, 0, count($header)));
            $key = trim((string) ($row['entry_key'] ?? ''));
            if ($key === '') {
                $errors[] = ['row' => $rowNumber, 'message' => 'entry_key is required.'];

                continue;
            }
            $groups[$key][] = ['row_number' => $rowNumber, 'data' => $row];
        }
        fclose($handle);

        $created = 0;
        foreach ($groups as $key => $rows) {
            try {
                $first = $rows[0]['data'];
                $description = trim((string) ($first['description'] ?? ''));
                $remark = trim((string) ($first['remark'] ?? ''));
                if ($description === '') {
                    throw new \DomainException('description is required.');
                }
                if (mb_strlen($remark) > 255) {
                    throw new \DomainException('remark may not exceed 255 characters.');
                }
                $entryDate = trim((string) $first['entry_date']);
                if ($entryDate === '' || strtotime($entryDate) === false) {
                    throw new \DomainException('entry_date must be a valid date.');
                }
                $entryDate = date('Y-m-d', strtotime($entryDate));
                $lockResult = $this->lockDateService->validateTransactionDate('journal_entries', $entryDate);
                if ($lockResult['is_locked']) {
                    throw new \DomainException($lockResult['message'] ?? "Entry date {$entryDate} is locked.");
                }
                $periodResult = $this->fiscalValidator->validateDate($entryDate);
                if (! ($periodResult['allowed'] ?? false)) {
                    throw new \DomainException($periodResult['reason'] ?? "No open fiscal period covers {$entryDate}.");
                }

                $journalId = null;
                $journalCode = trim((string) ($first['journal_code'] ?? ''));
                if ($journalCode !== '') {
                    $journalId = Journal::where('code', $journalCode)->value('id');
                    if ($journalId === null) {
                        throw new \DomainException("Journal code '{$journalCode}' was not found.");
                    }
                }

                $lines = [];
                $totalDebit = 0.0;
                $totalCredit = 0.0;
                foreach ($rows as $csvRow) {
                    $data = $csvRow['data'];
                    $accountCode = trim((string) ($data['account_code'] ?? ''));
                    $accountId = ChartOfAccount::where('code', $accountCode)->value('id');
                    if ($accountId === null) {
                        throw new \DomainException("Row {$csvRow['row_number']}: account code '{$accountCode}' was not found.");
                    }
                    $debit = is_numeric($data['debit']) ? (float) $data['debit'] : 0.0;
                    $credit = is_numeric($data['credit']) ? (float) $data['credit'] : 0.0;
                    if (($debit > 0 && $credit > 0) || ($debit <= 0 && $credit <= 0)) {
                        throw new \DomainException("Row {$csvRow['row_number']}: enter either a debit or a credit amount.");
                    }
                    $totalDebit += $debit;
                    $totalCredit += $credit;
                    $lines[] = [
                        'account_id' => $accountId,
                        'debit' => $debit,
                        'credit' => $credit,
                        'description' => trim((string) ($data['line_description'] ?? '')) ?: null,
                    ];
                }
                if (count($lines) < 2) {
                    throw new \DomainException('At least two lines are required.');
                }
                if (abs($totalDebit - $totalCredit) >= 0.01) {
                    throw new \DomainException('Entry is not balanced (debits must equal credits).');
                }

                $result = $this->postingEngine->createDraft(DraftJournalRequest::fromArray([
                    'journal_id' => $journalId,
                    'entry_date' => $entryDate,
                    'reference' => trim((string) ($first['reference'] ?? '')) ?: null,
                    'description' => $description,
                    'remark' => $remark ?: null,
                    'lines' => $lines,
                    'user_id' => $request->user()?->id,
                    'legal_entity_id' => $request->attributes->get('legal_entity_id'),
                    'entity_book_id' => $request->attributes->get('entity_book_id'),
                ]));
                if (! $result->success) {
                    throw new \DomainException($result->error ?? 'Unable to create draft.');
                }
                $created++;
            } catch (\Throwable $e) {
                $errors[] = ['entry_key' => $key, 'row' => $rows[0]['row_number'], 'message' => $e->getMessage()];
            }
        }

        return response()->json(['created' => $created, 'errors' => $errors]);
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
            'remark' => 'nullable|string|max:255',
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
                    'message' => 'Line '.($index + 1).' must have either debit or credit, not both or neither',
                ], 422);
            }
        }

        // FIX-01: guard draft creation against closed periods and lock dates
        // BEFORE any write — a draft dated into a locked period must never
        // be persisted.
        $entryDate = $validated['entry_date'];

        $lockResult = $this->lockDateService->validateTransactionDate('journal_entries', $entryDate);
        if ($lockResult['is_locked']) {
            return response()->json([
                'message' => $lockResult['message'] ?? "Entry date {$entryDate} is in a locked period.",
            ], 422);
        }

        $periodResult = $this->fiscalValidator->validateDate($entryDate);
        if (! ($periodResult['allowed'] ?? false)) {
            return response()->json([
                'message' => $periodResult['reason'] ?? "No open fiscal period covers {$entryDate}.",
            ], 422);
        }

        try {
            $draftRequest = $this->getDraftJournalRequest($request);

            $result = $this->postingEngine->createDraft($draftRequest);

            if (! $result->success) {
                \Log::warning('JournalEntriesController::store — createDraft failed', [
                    'error' => $result->error,
                    'request' => $request->except('password'),
                ]);

                return response()->json([
                    'message' => 'Error creating journal entry',
                    'error' => $result->error,
                ], 422);
            }

            return response()->json([
                'message' => 'Journal entry created successfully',
                'id' => $result->journalEntry->id,
                'entry' => $result->journalEntry->load('lines.account'),
            ], 201);

        } catch (\Illuminate\Database\QueryException $e) {
            \Log::error('JournalEntriesController::store — database error', [
                'error' => $e->getMessage(),
                'sql' => $e->getSql(),
            ]);

            return response()->json([
                'message' => 'Database error while creating journal entry',
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            \Log::error('JournalEntriesController::store — unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Error creating journal entry',
                'error' => $e->getMessage(),
            ], 500);
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
            'remark' => 'nullable|string|max:255',
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
                'remark' => $validated['remark'] ?? null,
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
     * Delete multiple draft journal entries. Posted entries are skipped so a
     * mixed selection cannot remove posted accounting history.
     */
    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $entries = JournalEntry::whereIn('id', $validated['ids'])->get()->keyBy('id');
        $deleted = [];
        $skipped = [];

        foreach ($validated['ids'] as $id) {
            $entry = $entries->get($id);
            if (! $entry) {
                $skipped[] = ['id' => $id, 'reason' => 'Journal entry not found'];
            } elseif ($entry->status !== 'draft') {
                $skipped[] = ['id' => $id, 'reason' => 'Only draft journal entries can be deleted'];
            } else {
                $entry->delete();
                $deleted[] = $id;
            }
        }

        return response()->json([
            'message' => count($deleted).' journal entry(s) deleted',
            'deleted' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Post a journal entry in-place via ManualJournalPostingService.
     *
     * The draft entry is transitioned to 'posted' WITHOUT being deleted and
     * recreated. This preserves audit continuity: the entry ID never changes.
     */
    public function post($id, Request $request)
    {
        $entry = JournalEntry::with('lines')->findOrFail($id);

        if ($entry->status === 'posted') {
            return response()->json([
                'message' => 'Journal entry is already posted',
            ], 422);
        }

        // Early lock-date check so we can surface requires_override to the UI
        // before attempting the full post. The service re-validates internally.
        $lockValidation = $this->lockDateService->validateTransactionDate(
            'journal_entries',
            $entry->entry_date,
        );

        if ($lockValidation['is_locked'] && ! $request->has('override_reason')) {
            return response()->json([
                'message' => $lockValidation['message'],
                'requires_override' => true,
                'lock_date' => $lockValidation['lock_date'],
            ], 422);
        }

        // Self-approval guard
        $approvedBy = $request->input('approved_by') ? (int) $request->input('approved_by') : null;
        $userId = TenantUserHelper::requireTenantUserId();

        if ($lockValidation['is_locked'] && $approvedBy && $approvedBy === $userId) {
            return response()->json([
                'message' => 'Self-approval of lock-date overrides is not permitted. A different approver is required.',
            ], 422);
        }

        $result = $this->manualJournalService->post(
            entry: $entry,
            userId: $userId,
            overrideReason: $request->input('override_reason'),
            approvedBy: $approvedBy,
            permissionActorId: (int) $request->user()->id,
        );

        if (! $result->success) {
            return response()->json([
                'message' => 'Error posting journal entry',
                'error' => $result->error,
            ], 422);
        }

        return response()->json([
            'message' => 'Journal entry posted successfully',
            'entry' => $result->journalEntry->load('lines.account'),
        ]);
    }

    /**
     * Post multiple draft journal entries using the exact single-entry posting
     * path, including lock-date, approval, balance, and permission checks.
     */
    public function bulkPost(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $posted = [];
        $skipped = [];

        foreach ($validated['ids'] as $id) {
            try {
                $response = $this->post($id, $request);
                $payload = $response->getData(true);

                if ($response->getStatusCode() < 300) {
                    $posted[] = $id;

                    continue;
                }

                $skipped[] = [
                    'id' => $id,
                    'reason' => $payload['message'] ?? $payload['error'] ?? 'Unable to post journal entry',
                ];
            } catch (\Throwable $exception) {
                $skipped[] = ['id' => $id, 'reason' => $exception->getMessage()];
            }
        }

        return response()->json([
            'message' => count($posted).' journal entry(s) posted',
            'posted' => $posted,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Reverse a posted journal entry.
     *
     * Requires an explicit reversal_date and reason. Self-approval is blocked:
     * approved_by must differ from the authenticated user.
     */
    public function reverse($id, Request $request)
    {
        $validated = $request->validate([
            'reversal_date' => 'required|date',
            'reason' => 'required|string|max:500',
            'reason_code' => 'nullable|string|max:50',
            'approved_by' => 'nullable|integer|exists:users,id',
        ]);

        $userId = TenantUserHelper::requireTenantUserId();
        $approvedBy = isset($validated['approved_by']) ? (int) $validated['approved_by'] : null;

        if ($approvedBy !== null && $approvedBy === $userId) {
            return response()->json([
                'message' => 'Self-approval of reversals is not permitted. A different approver is required.',
            ], 422);
        }

        try {
            $result = $this->postingEngine->reverse(new ReversalRequest(
                journalEntryId: (int) $id,
                reversalDate: $validated['reversal_date'],
                reason: $validated['reason'],
                reasonCode: $validated['reason_code'] ?? null,
                actorUserId: $userId,
                approvalUserId: $approvedBy,
            ));

            if (! $result->success) {
                return response()->json([
                    'message' => 'Error reversing journal entry',
                    'error' => $result->error,
                ], 422);
            }

            // Update lifecycle status on the original journal entry
            $originalJe = JournalEntry::find($id);
            if ($originalJe) {
                $originalJe->update([
                    'lifecycle_status' => 'reversed',
                    'correction_type' => 'reverse',
                    'is_active_version' => false,
                ]);
            }

            return response()->json([
                'message' => 'Journal entry reversed successfully',
                'reversal_entry' => $result->journalEntry->load('lines.account'),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error reversing journal entry',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Partial reversal — reverse specific lines of a posted journal entry.
     *
     * Accepts line_amounts array with { line_id, amount } pairs.
     * Creates a new journal entry with only the specified line amounts.
     */
    public function partialReverse($id, Request $request)
    {
        $validated = $request->validate([
            'reversal_date' => 'required|date',
            'reason' => 'required|string|max:500',
            'reason_code' => 'nullable|string|max:50',
            'line_amounts' => 'required|array|min:1',
            'line_amounts.*.line_id' => 'required|integer',
            'line_amounts.*.amount' => 'required|numeric|min:0.01',
        ]);

        TenantUserHelper::requireTenantUserId();

        try {
            // Route through the canonical ReversalCorrectionEngine so that all
            // four lineage tables (ReversalRelationship, ReversalComponent,
            // JournalEntryLink, PostingAuditLog) and the state-transition
            // record are written atomically — not via direct model writes
            // which bypass audit gates.
            $reversalRequest = new \App\Accounting\DTOs\ReversalRequest(
                journalEntryId: (int) $id,
                reversalDate: $validated['reversal_date'],
                reason: $validated['reason'],
                reasonCode: $validated['reason_code'] ?? 'PARTIAL_REVERSAL',
                actorUserId: TenantUserHelper::requireTenantUserId(),
                tenantId: tenant('id'),
            );

            $engine = app(\App\Accounting\Services\ReversalCorrectionEngine::class);
            $result = $engine->partialReversal($reversalRequest, $validated['line_amounts']);

            if (! $result->success) {
                return response()->json([
                    'message' => 'Partial reversal failed',
                    'error' => $result->reason,
                    'reason_code' => $result->reasonCode?->value,
                ], 422);
            }

            return response()->json([
                'message' => 'Partial reversal posted successfully',
                'reversal_entry' => $result->journalEntry->load('lines.account'),
                'reversal_relationship_id' => $result->relationship->id,
                'reversed_amount' => $result->relationship->reversed_amount_summary,
                'remaining_reversible' => $result->relationship->remaining_reversible_amount_summary,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error creating partial reversal',
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

    /**
     * Traceability: attach a front-end link for each entry's reference, so the
     * list (and detail) can navigate to the payment/invoice/bill the entry was
     * posted from — or, for reversal rows, to the entry they reverse.
     */
    private function attachReferenceLinks(\Illuminate\Support\Collection $entries): void
    {
        if ($entries->isEmpty()) {
            return;
        }

        $links = JournalEntryLink::query()
            ->whereIn('journal_entry_id', $entries->pluck('id')->all())
            ->where('relation_type', 'post')
            ->get()
            ->keyBy('journal_entry_id');

        foreach ($entries as $entry) {
            $entry->setAttribute('reference_link', $this->referenceLinkFor($entry, $links->get($entry->id)));
        }
    }

    /**
     * @return array{type: string, id: int|null, label: string|null, url: string}|null
     */
    private function referenceLinkFor(JournalEntry $entry, ?JournalEntryLink $link): ?array
    {
        // Reversal rows: link to the entry they cancel (their reference carries
        // "Reversal: JE-…").
        if ($entry->is_reversal) {
            $originalId = $entry->reversed_entry_id;

            if ($originalId) {
                return [
                    'type' => 'journal_entry',
                    'id' => (int) $originalId,
                    'label' => $entry->reversedEntry?->entry_number,
                    'url' => '/accounting/core/journal-entries?entry_id='.$originalId,
                ];
            }

            return null;
        }

        $ref = trim((string) $entry->reference);

        // Preferred: the posting link records the source document id.
        if ($link && $link->source_document_type && $link->source_document_id) {
            $type = strtolower(class_basename($link->source_document_type));
            $id = (int) $link->source_document_id;

            $url = match ($type) {
                'invoice' => "/accounting/customers/bills/{$id}",
                'bill' => "/accounting/vendors/bills/{$id}",
                'quotation' => "/quotations/{$id}",
                'deliveryorder' => "/delivery-orders/{$id}",
                'payment' => $ref !== ''
                    ? '/accounting/customers/payments?search='.urlencode($ref)
                    : null,
                default => null,
            };

            if ($url !== null) {
                return ['type' => $type, 'id' => $id, 'label' => $ref !== '' ? $ref : null, 'url' => $url];
            }
        }

        // Fallback: resolve from the reference convention alone.
        if ($ref !== '' && preg_match('/^PAY\//i', $ref)) {
            return [
                'type' => 'payment',
                'id' => null,
                'label' => $ref,
                'url' => '/accounting/customers/payments?search='.urlencode($ref),
            ];
        }

        return null;
    }

    /**
     * Single entry (detail dialog): same traceability payload as the list.
     */
}
