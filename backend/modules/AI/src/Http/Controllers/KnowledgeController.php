<?php

namespace Modules\AI\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\AI\Models\AIKnowledgeBase;
use Modules\AI\Services\KnowledgeIngestionService;

/**
 * Tenant knowledge base management (Settings → General → AI).
 *
 * Entries feed the retrieval-augmented context of every chat
 * (KnowledgeRetriever → AssistantContextService → ProcessAIMessage).
 */
class KnowledgeController extends Controller
{
    public function __construct(private readonly KnowledgeIngestionService $ingestion) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:120',
            'category' => 'nullable|string|max:50',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = AIKnowledgeBase::query()
            ->orderByDesc('priority')
            ->orderByDesc('updated_at');

        if (! empty($validated['search'])) {
            $term = $validated['search'];
            $query->where(function ($where) use ($term) {
                $where->where('question', 'ilike', "%{$term}%")
                    ->orWhere('answer', 'ilike', "%{$term}%");
            });
        }

        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        $documents = $query->paginate($validated['per_page'] ?? 50);

        return response()->json([
            'success' => true,
            'documents' => $documents->items(),
            'meta' => [
                'total' => $documents->total(),
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $document = AIKnowledgeBase::create([
            'category' => $validated['category'],
            'question' => trim($validated['question']),
            'answer' => trim($validated['answer']),
            'keywords' => $validated['keywords'] ?? null,
            'priority' => $validated['priority'] ?? 0,
            'language' => $validated['language'] ?? 'en',
            'is_active' => $validated['is_active'] ?? true,
            'source' => 'manual',
        ]);

        return response()->json(['success' => true, 'document' => $document], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $document = AIKnowledgeBase::findOrFail($id);
        $validated = $request->validate($this->rules());

        $document->update([
            'category' => $validated['category'],
            'question' => trim($validated['question']),
            'answer' => trim($validated['answer']),
            'keywords' => $validated['keywords'] ?? null,
            'priority' => $validated['priority'] ?? 0,
            'language' => $validated['language'] ?? 'en',
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['success' => true, 'document' => $document->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        AIKnowledgeBase::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Load the built-in starter pack (storefront help centre content).
     * Idempotent — re-running refreshes the starter entries.
     */
    public function importDefaults(): JsonResponse
    {
        $imported = $this->ingestion->ingestStarterPack();

        return response()->json([
            'success' => true,
            'imported' => $imported,
            'total' => AIKnowledgeBase::count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'category' => 'required|string|max:50',
            'question' => 'required|string|max:500',
            'answer' => 'required|string|max:5000',
            'keywords' => 'nullable|array|max:20',
            'keywords.*' => 'string|max:60',
            'priority' => 'nullable|integer|min:0|max:100',
            'language' => 'nullable|string|max:10',
            'is_active' => 'nullable|boolean',
        ];
    }
}
