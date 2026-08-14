<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingFinding;
use App\Services\AccountingFindingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingFindingsController extends Controller
{
    public function __construct(
        private AccountingFindingService $findingService
    ) {}

    /**
     * List findings for an entity.
     */
    public function index(Request $request, string $entityId): JsonResponse
    {
        $findings = $this->findingService->getFindings(
            $entityId,
            $request->input('status'),
            $request->input('severity'),
            $request->input('type'),
            $request->input('limit', 50)
        );

        return $this->success($findings, 'Findings retrieved.');
    }

    /**
     * Get finding summary statistics.
     */
    public function summary(string $entityId): JsonResponse
    {
        $summary = $this->findingService->getSummary($entityId);

        return $this->success($summary, 'Summary retrieved.');
    }

    /**
     * Store a new finding.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_id' => 'required|string|max:255',
            'finding_type' => 'required|string|max:100',
            'severity' => 'sometimes|string|in:low,medium,high,critical',
            'source_type' => 'nullable|string|max:100',
            'source_id' => 'nullable|uuid',
            'account_code' => 'nullable|string|max:50',
            'reference_number' => 'nullable|string|max:100',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'recommendation' => 'nullable|string',
            'amount' => 'nullable|numeric',
            'currency' => 'nullable|string|max:3',
            'confidence' => 'nullable|numeric|min:0|max:1',
            'metadata' => 'nullable|array',
        ]);

        $finding = $this->findingService->createFinding($validated);

        return $this->success($finding, 'Finding created.', 201);
    }

    /**
     * Show a specific finding.
     */
    public function show(string $id): JsonResponse
    {
        $finding = AccountingFinding::where('tenant_id', tenant()->id)
            ->findOrFail($id);

        return $this->success($finding, 'Finding retrieved.');
    }

    /**
     * Acknowledge a finding.
     */
    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $finding = $this->findingService->acknowledge($id, $request->user()->id);

        return $this->success($finding, 'Finding acknowledged.');
    }

    /**
     * Mark a finding as under investigation.
     */
    public function investigate(Request $request, string $id): JsonResponse
    {
        $finding = $this->findingService->investigate($id, $request->user()->id);

        return $this->success($finding, 'Finding marked as investigating.');
    }

    /**
     * Resolve a finding.
     */
    public function resolve(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $finding = $this->findingService->resolve($id, $validated['notes'] ?? null);

        return $this->success($finding, 'Finding resolved.');
    }

    /**
     * Dismiss a finding.
     */
    public function dismiss(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string',
        ]);

        $finding = $this->findingService->dismiss($id, $validated['reason'] ?? null);

        return $this->success($finding, 'Finding dismissed.');
    }

    /**
     * Escalate a finding.
     */
    public function escalate(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string',
        ]);

        $finding = $this->findingService->escalate($id, $validated['reason'] ?? null);

        return $this->success($finding, 'Finding escalated.');
    }
}
