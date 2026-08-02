<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Modules\TemplateBuilder\Http\Requests\PreviewTemplateRequest;
use Modules\TemplateBuilder\Models\DocumentTemplate;

class TemplateEditorController extends Controller
{
    public function __construct(private readonly DocumentTemplateServiceContract $service) {}

    public function preview(PreviewTemplateRequest $request, string $id): JsonResponse
    {
        $template = $this->service->find($id);
        if (! $template) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        // Overlay any unsaved edits from the editor onto a transient template.
        $transient = $this->overlayEdits($template, $request);

        $result = $this->service->preview($transient, $request->input('sample_data'));

        return response()->json([
            'html' => $result['html'],
            'warnings' => $result['warnings'],
        ]);
    }

    public function validateDefinition(Request $request): JsonResponse
    {
        $definition = $request->validate([
            'mode' => ['required', 'in:block,code'],
            'layout_config' => ['nullable', 'array'],
            'html' => ['nullable', 'string'],
        ]);

        $result = $this->service->validateDefinition($definition);

        return response()->json($result);
    }

    private function overlayEdits(DocumentTemplate $template, PreviewTemplateRequest $request): DocumentTemplate
    {
        $clone = $template->replicate(['id']);
        $clone->id = $template->id;

        if ($request->has('layout_config')) {
            $clone->layout_config = $request->input('layout_config');
        }
        if ($request->has('html')) {
            $clone->html = $request->input('html');
        }
        if ($request->has('css')) {
            $clone->css = $request->input('css');
        }
        if ($request->has('mode')) {
            $clone->mode = $request->input('mode');
        }
        if ($request->has('metadata')) {
            $clone->metadata = $request->input('metadata');
        }

        return $clone;
    }
}
