<?php

namespace Modules\AccountingFoundation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccountingFoundation\Services\OpeningBalanceService;
use Modules\AccountingFoundation\Models\OpeningBalanceBatch;

/**
 * ACC-001 Opening Balance — API controller.
 *
 * Issue #70: exposes the opening balance lifecycle through REST
 * endpoints. All mutations go through OpeningBalanceService which
 * enforces fail-closed rules, SoD, and lifecycle transitions.
 */
class OpeningBalanceController extends Controller
{
    public function __construct(
        private readonly OpeningBalanceService $service,
    ) {}

    /**
     * List opening balance batches.
     */
    public function index(Request $request): JsonResponse
    {
        $query = OpeningBalanceBatch::query()
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->population_scope, fn ($q, $s) => $q->where('population_scope', $s))
            ->orderByDesc('created_at');

        $batches = $query->paginate($request->per_page ?? 25);

        return response()->json($batches);
    }

    /**
     * Show a single batch with lines and audit trail.
     */
    public function show(string $id): JsonResponse
    {
        $batch = OpeningBalanceBatch::with(['lines', 'auditTrail'])->findOrFail($id);

        return response()->json($batch);
    }

    /**
     * Create a new opening balance batch (DRAFT).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|uuid',
            'legal_entity_id' => 'nullable|uuid',
            'accounting_book_id' => 'nullable|uuid',
            'source_type' => 'required|string|max:50',
            'source_reference' => 'nullable|string|max:255',
            'cutover_date' => 'required|date',
            'fiscal_period_id' => 'required|integer|exists:fiscal_periods,id',
            'population_scope' => ['required', 'string', \Illuminate\Validation\Rule::in(\Modules\AccountingFoundation\Enums\PopulationScope::values())],
            'mapping_version' => 'nullable|string|max:50',
            'mapping_hash' => 'nullable|string|max:64',
        ]);

        $batch = $this->service->createBatch($validated, (string) $request->user()->id);

        return response()->json($batch, 201);
    }

    /**
     * Capture lines into a DRAFT batch.
     */
    public function captureLines(Request $request, string $id): JsonResponse
    {
        $batch = OpeningBalanceBatch::findOrFail($id);

        $validated = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.account_id' => 'required|integer|exists:chart_of_accounts,id',
            'lines.*.account_code' => 'required|string|max:50',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
            'lines.*.currency_code' => 'nullable|string|max:3',
            'lines.*.source_line_ref' => 'nullable|string|max:255',
        ]);

        $this->service->captureLines($batch, $validated['lines'], (string) $request->user()->id);

        return response()->json(['status' => 'imported']);
    }

    /**
     * Validate the batch.
     */
    public function validateBatch(string $id): JsonResponse
    {
        $batch = OpeningBalanceBatch::findOrFail($id);

        $this->service->validate($batch, (string) request()->user()->id);

        return response()->json(['status' => $batch->fresh()->status]);
    }

    /**
     * Reconcile against authoritative sources.
     */
    public function reconcile(string $id): JsonResponse
    {
        $batch = OpeningBalanceBatch::findOrFail($id);

        $this->service->reconcile($batch, (string) request()->user()->id);

        return response()->json(['status' => $batch->fresh()->status]);
    }

    /**
     * Submit for approval (reconciled → pending_approval).
     */
    public function submitForApproval(string $id): JsonResponse
    {
        $batch = OpeningBalanceBatch::findOrFail($id);

        // HR70-11: route the governance-significant RECONCILED → PENDING_APPROVAL
        // transition through the service so it is recorded in the audit trail.
        $this->service->submitForApproval($batch, (string) request()->user()->id);

        return response()->json(['status' => $batch->fresh()->status]);
    }

    /**
     * Approve the batch (SoD: approver ≠ creator).
     */
    public function approve(string $id): JsonResponse
    {
        $batch = OpeningBalanceBatch::findOrFail($id);

        $this->service->approve($batch, (string) request()->user()->id);

        return response()->json(['status' => $batch->fresh()->status]);
    }

    /**
     * Authorise for posting.
     */
    public function authorise(string $id): JsonResponse
    {
        $batch = OpeningBalanceBatch::findOrFail($id);

        $this->service->authoriseForPosting($batch, (string) request()->user()->id);

        return response()->json(['status' => $batch->fresh()->status]);
    }

    /**
     * Post via ACC-002 PostingEngine.
     */
    public function post(string $id): JsonResponse
    {
        $batch = OpeningBalanceBatch::findOrFail($id);

        $this->service->post($batch, (string) request()->user()->id);

        $batch->refresh();

        return response()->json([
            'status' => $batch->status,
            'journal_entry_id' => $batch->journal_entry_id,
            'posted_at' => $batch->posted_at,
        ]);
    }

    /**
     * Supersede an active batch.
     */
    public function supersede(Request $request, string $id): JsonResponse
    {
        $batch = OpeningBalanceBatch::findOrFail($id);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $this->service->supersede($batch, (string) $request->user()->id, $validated['reason']);

        return response()->json(['status' => $batch->fresh()->status]);
    }

    /**
     * Get audit trail for a batch.
     */
    public function auditTrail(string $id): JsonResponse
    {
        $batch = OpeningBalanceBatch::findOrFail($id);

        $trail = $batch->auditTrail()->orderByDesc('created_at')->get();

        return response()->json($trail);
    }
}
