<?php

namespace Modules\FinancialClose\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinancialClose\Services\FinancialCloseChecklistService;

class ChecklistTemplateController extends Controller
{
    public function __construct(
        private readonly FinancialCloseChecklistService $checklistService,
    ) {}

    /** GET /checklist-templates */
    public function index(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $templates = $this->checklistService->listTemplates($tenantId, $request->only('status'));

        return response()->json(['data' => $templates]);
    }

    /** POST /checklist-templates */
    public function store(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId = $request->user()->id;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'task_definitions' => ['required', 'array'],
            'task_definitions.*.title' => ['required', 'string'],
            'task_definitions.*.priority' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'task_definitions.*.is_critical' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $template = $this->checklistService->createTemplate($tenantId, $userId, $validated);

        return response()->json(['data' => $template], 201);
    }

    /** GET /checklist-templates/{templateId} */
    public function show(string $templateId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $template = $this->checklistService->getTemplate($tenantId, $templateId);

        if (! $template) {
            return response()->json(['message' => 'Template not found.'], 404);
        }

        return response()->json(['data' => $template]);
    }

    /** PUT /checklist-templates/{templateId} */
    public function update(Request $request, string $templateId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $template = $this->checklistService->getTemplate($tenantId, $templateId);

        if (! $template) {
            return response()->json(['message' => 'Template not found.'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:draft,active,archived'],
            'task_definitions' => ['sometimes', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        $template->update($validated);

        return response()->json(['data' => $template]);
    }

    /** DELETE /checklist-templates/{templateId} */
    public function destroy(string $templateId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $template = $this->checklistService->getTemplate($tenantId, $templateId);

        if (! $template) {
            return response()->json(['message' => 'Template not found.'], 404);
        }

        $template->update(['status' => 'archived']);

        return response()->json(['message' => 'Template archived.']);
    }

    /** POST /checklist-templates/{templateId}/activate */
    public function activate(string $templateId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $template = $this->checklistService->getTemplate($tenantId, $templateId);

        if (! $template) {
            return response()->json(['message' => 'Template not found.'], 404);
        }

        $template->update(['status' => 'active']);

        return response()->json(['data' => $template]);
    }
}
