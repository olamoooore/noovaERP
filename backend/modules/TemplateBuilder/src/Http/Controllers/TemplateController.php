<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Modules\TemplateBuilder\Http\Requests\StoreTemplateRequest;
use Modules\TemplateBuilder\Http\Requests\UpdateTemplateRequest;
use Modules\TemplateBuilder\Http\Resources\TemplateResource;

class TemplateController extends Controller
{
    public function __construct(private readonly DocumentTemplateServiceContract $service) {}

    public function index(Request $request): JsonResponse
    {
        $templates = $this->service->list($request->only(['document_type', 'status', 'search', 'per_page']));

        return response()->json([
            'data' => TemplateResource::collection($templates),
            'meta' => ['current_page' => $templates->currentPage(), 'last_page' => $templates->lastPage(), 'total' => $templates->total()],
        ]);
    }

    public function store(StoreTemplateRequest $request): JsonResponse
    {
        $template = $this->service->create($request->validated());

        return (new TemplateResource($template))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $id): JsonResponse
    {
        $template = $this->service->find($id);
        if (! $template) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        return response()->json(['data' => new TemplateResource($template)]);
    }

    public function update(UpdateTemplateRequest $request, string $id): JsonResponse
    {
        $template = $this->service->update($id, $request->validated());

        return response()->json(['data' => new TemplateResource($template)]);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->archive($id);

        return response()->json(['message' => 'Template archived']);
    }

    public function duplicate(Request $request, string $id): JsonResponse
    {
        $template = $this->service->duplicate($id, $request->input('name'));

        return (new TemplateResource($template))->response()->setStatusCode(201);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $template = $this->service->publish($id, $request->input('change_note'));

        return response()->json(['data' => new TemplateResource($template)]);
    }

    public function archive(string $id): JsonResponse
    {
        $template = $this->service->archive($id);

        return response()->json(['data' => new TemplateResource($template)]);
    }

    public function setDefault(Request $request, string $id): JsonResponse
    {
        $this->service->setDefault($id, $request->only(['scope_type', 'scope_value']));

        return response()->json(['message' => 'Default template updated']);
    }

    public function versions(string $id): JsonResponse
    {
        $template = $this->service->find($id);
        if (! $template) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        return response()->json([
            'data' => $template->versions()->get(['id', 'version', 'change_note', 'is_published', 'created_at']),
        ]);
    }

    public function restoreVersion(string $id, int $version): JsonResponse
    {
        $template = $this->service->restoreVersion($id, $version);

        return response()->json(['data' => new TemplateResource($template)]);
    }
}
