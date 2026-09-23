<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Services\GlobalSearchService;
use App\Services\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function __construct(
        private TagService $tagService,
        private GlobalSearchService $searchService,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->tagService->allWithCounts(),
            'subjects' => array_keys(TagService::SUBJECTS),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $tag = $this->tagService->findOrCreate($validated['name']);

        return response()->json([
            'message' => 'Tag ready',
            'data' => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'color' => $tag->color,
            ],
        ], 201);
    }

    /**
     * Tags attached to one subject.
     */
    public function forSubject(Request $request, string $subjectType, int $subjectId): JsonResponse
    {
        $model = $this->resolveSubject($subjectType, $subjectId, $request);

        if (! $model) {
            return response()->json(['message' => 'Subject not found'], 404);
        }

        return response()->json(['data' => $this->tagService->forSubject($model)]);
    }

    /**
     * Attach tags (by name) to a subject. Creates missing tags on the fly.
     */
    public function attach(Request $request, string $subjectType, int $subjectId): JsonResponse
    {
        $model = $this->resolveSubject($subjectType, $subjectId, $request);

        if (! $model) {
            return response()->json(['message' => 'Subject not found'], 404);
        }

        $validated = $request->validate([
            'tags' => 'required|array|min:1',
            'tags.*' => 'string|max:100',
            'replace' => 'nullable|boolean',
        ]);

        $tags = ($validated['replace'] ?? false)
            ? $this->tagService->sync($model, $validated['tags'], $request->user()?->id)
            : $this->tagService->attach($model, $validated['tags'], $request->user()?->id);

        return response()->json([
            'message' => 'Tags updated',
            'data' => $this->tagService->forSubject($model),
            'tags' => array_map(fn (Tag $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'color' => $t->color,
            ], $tags),
        ]);
    }

    /**
     * Detach a single tag from a subject.
     */
    public function detach(Request $request, string $subjectType, int $subjectId, int $tagId): JsonResponse
    {
        $model = $this->resolveSubject($subjectType, $subjectId, $request);

        if (! $model) {
            return response()->json(['message' => 'Subject not found'], 404);
        }

        $this->tagService->detach($model, $tagId);

        return response()->json([
            'message' => 'Tag removed',
            'data' => $this->tagService->forSubject($model),
        ]);
    }

    /**
     * Global search across documents, master data and tags.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'nullable|string|max:120',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:120',
            'limit' => 'nullable|integer|min:1|max:25',
        ]);

        $result = $this->searchService->search(
            (string) ($validated['q'] ?? ''),
            $validated['tags'] ?? [],
            (int) ($validated['limit'] ?? 6),
        );

        return response()->json($result);
    }

    /**
     * Resolve a whitelisted subject alias + id, honouring soft deletes.
     */
    private function resolveSubject(string $alias, int $id, Request $request): ?\Illuminate\Database\Eloquent\Model
    {
        $class = $this->tagService->modelFor($alias);

        if (! $class) {
            return null;
        }

        return $class::query()->find($id);
    }
}
