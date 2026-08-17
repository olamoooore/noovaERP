<?php

namespace App\Http\Controllers\Api;

use App\Accounting\Enums\DocumentIssueState;
use App\Accounting\Enums\RevisionLineageState;
use App\Accounting\Services\RevisionPolicyResolver;
use App\Http\Controllers\Controller;
use App\Models\DocumentRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentRevisionController extends Controller
{
    public function __construct(
        private readonly RevisionPolicyResolver $resolver,
    ) {}

    /**
     * GET /accounting/revisions/{documentUuid}
     * List all revisions for a document.
     */
    public function index(string $documentUuid): JsonResponse
    {
        $revisions = DocumentRevision::forDocument($documentUuid)
            ->orderByDesc('revision_sequence')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $revisions,
        ]);
    }

    /**
     * GET /accounting/revisions/{documentUuid}/effective
     * Get the effective (current) revision for a document.
     */
    public function effective(string $documentUuid): JsonResponse
    {
        $revision = $this->resolver->resolveEffectiveRevision($documentUuid);

        if (!$revision) {
            return response()->json([
                'success' => false,
                'error' => 'No effective revision found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $revision,
        ]);
    }

    /**
     * POST /accounting/revisions
     * Create a new revision for a document.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_uuid' => 'required|uuid',
            'document_type' => 'required|string|max:50',
            'base_document_number' => 'nullable|string|max:100',
            'source_capability' => 'nullable|string|max:50',
        ]);

        $sequence = $this->resolver->reserveNextSequence($validated['document_uuid']);

        $revision = DocumentRevision::create([
            'tenant_id' => auth()->user()?->tenant_id,
            'entity_id' => $request->input('entity_id'),
            'document_uuid' => $validated['document_uuid'],
            'base_document_number' => $validated['base_document_number'],
            'document_type' => $validated['document_type'],
            'revision_sequence' => $sequence,
            'revision_code' => 'R' . str_pad($sequence, 3, '0', STR_PAD_LEFT),
            'revision_display_value' => $validated['base_document_number'] . '-R' . str_pad($sequence, 3, '0', STR_PAD_LEFT),
            'source_capability' => $validated['source_capability'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $revision,
        ], 201);
    }

    /**
     * POST /accounting/revisions/{id}/issue
     * Issue a revision.
     */
    public function issue(int $id): JsonResponse
    {
        $revision = DocumentRevision::findOrFail($id);

        if ($revision->document_issue_state === DocumentIssueState::ISSUED->value) {
            return response()->json([
                'success' => false,
                'error' => 'Revision is already issued.',
            ], 422);
        }

        $revision->update([
            'document_issue_state' => DocumentIssueState::ISSUED->value,
            'effective_revision' => true,
            'issued_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $revision,
        ]);
    }

    /**
     * POST /accounting/revisions/{id}/supersede
     * Supersede a revision (mark as superseded).
     */
    public function supersede(int $id): JsonResponse
    {
        $revision = DocumentRevision::findOrFail($id);

        $revision->update([
            'revision_lineage_state' => RevisionLineageState::SUPERSEDED->value,
            'effective_revision' => false,
            'superseded_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $revision,
        ]);
    }

    /**
     * GET /accounting/revisions/resolve-policy
     * Resolve the permitted correction instrument for a document.
     */
    public function resolvePolicy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => 'required|string',
            'jurisdiction' => 'nullable|string',
            'tax_status' => 'nullable|string',
            'posting_status' => 'nullable|string',
            'settlement_status' => 'nullable|string',
            'regulatory_status' => 'nullable|string',
        ]);

        $result = $this->resolver->resolve($validated);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
