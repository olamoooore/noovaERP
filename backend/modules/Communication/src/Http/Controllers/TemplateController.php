<?php

namespace Modules\Communication\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Communication\Models\CommunicationTemplate;
use Modules\Communication\Services\TemplateRenderer;

class TemplateController extends Controller
{
    public function __construct(
        protected TemplateRenderer $renderer,
    ) {}

    /**
     * GET /api/communication/templates
     */
    public function index(Request $request): JsonResponse
    {
        $templates = CommunicationTemplate::query()
            ->when($request->module, fn($q, $module) => $q->forModule($module))
            ->when($request->is_active !== null, fn($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('module')
            ->orderBy('key')
            ->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    /**
     * GET /api/communication/templates/{id}
     */
    public function show(int $id): JsonResponse
    {
        $template = CommunicationTemplate::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $template,
        ]);
    }

    /**
     * POST /api/communication/templates
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'key' => 'required|string|max:100',
            'module' => 'required|string|max:50',
            'event' => 'required|string|max:100',
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body_html' => 'required|string',
            'body_plain' => 'nullable|string',
            'channels' => 'nullable|array',
            'variables' => 'nullable|array',
            'locale' => 'nullable|string|max:10',
        ]);

        $template = CommunicationTemplate::create(array_merge(
            $request->only(['key', 'module', 'event', 'name', 'subject', 'body_html', 'body_plain', 'channels', 'variables', 'locale']),
            ['tenant_id' => tenant('id')],
        ));

        // Invalidate cache for this template key
        $this->renderer->invalidateCache($request->key);

        return response()->json([
            'success' => true,
            'data' => $template,
        ], 201);
    }

    /**
     * PUT /api/communication/templates/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $template = CommunicationTemplate::findOrFail($id);

        $request->validate([
            'name' => 'nullable|string|max:255',
            'subject' => 'nullable|string|max:255',
            'body_html' => 'nullable|string',
            'body_plain' => 'nullable|string',
            'channels' => 'nullable|array',
            'variables' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $template->update($request->only([
            'name', 'subject', 'body_html', 'body_plain', 'channels', 'variables', 'is_active',
        ]));

        $this->renderer->invalidateCache($template->key);

        return response()->json([
            'success' => true,
            'data' => $template->fresh(),
        ]);
    }

    /**
     * POST /api/communication/templates/{id}/preview
     *
     * Preview a rendered template with sample variables.
     */
    public function preview(Request $request, int $id): JsonResponse
    {
        $template = CommunicationTemplate::findOrFail($id);

        $request->validate([
            'variables' => 'nullable|array',
        ]);

        $variables = $request->input('variables', []);

        // Render directly from the template model
        $rendered = $template->render($variables);

        return response()->json([
            'success' => true,
            'data' => [
                'subject' => $rendered['subject'],
                'body_html' => $rendered['body_html'],
                'body_plain' => $rendered['body_plain'],
                'variables_found' => $template->extractVariables(),
            ],
        ]);
    }
}
