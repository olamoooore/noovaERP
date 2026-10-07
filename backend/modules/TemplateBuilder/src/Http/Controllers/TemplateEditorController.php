<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Modules\TemplateBuilder\Http\Requests\PreviewTemplateRequest;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Services\DataSource\DataSourceRegistry;
use Modules\TemplateBuilder\Services\DocumentRenderAuthorizer;
use Modules\TemplateBuilder\Services\PreviewDocumentFinder;

class TemplateEditorController extends Controller
{
    public function __construct(
        private readonly DocumentTemplateServiceContract $service,
        private readonly PdfGenerationServiceContract $pdf,
        private readonly DataSourceRegistry $dataSources,
        private readonly DocumentRenderAuthorizer $authorizer,
        private readonly PreviewDocumentFinder $previewDocuments,
    ) {}

    public function preview(PreviewTemplateRequest $request, string $id): JsonResponse
    {
        $template = $this->service->find($id);
        if (! $template) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        // Overlay any unsaved edits from the editor onto a transient template.
        $transient = $this->overlayEdits($template, $request);

        // Issue #620: "Preview with INV-2026-0412" — render the draft against
        // a REAL document. Same authorization as a real render (#611: source
        // permission + legal entity, no IDOR) and the same render pipeline and
        // payload choke point (PHI masking) as real PDFs.
        $documentId = (string) ($request->input('document_id') ?? '');
        if ($documentId !== '') {
            return $this->previewWithDocument($transient, $documentId, $request);
        }

        $result = $this->service->preview($transient, $request->input('sample_data'));

        return response()->json([
            'html' => $result['html'],
            'warnings' => $result['warnings'],
        ]);
    }

    /**
     * Issue #620: recent documents of the template's type that the caller may
     * render, for the editor's preview document picker.
     */
    public function previewDocuments(Request $request, string $id): JsonResponse
    {
        $template = $this->service->find($id);
        if (! $template) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        $documentType = (string) $template->document_type;
        if (! $this->dataSources->has($documentType) || ! $this->previewDocuments->supports($documentType)) {
            return response()->json(['data' => [], 'document_type' => $documentType]);
        }

        $denied = $this->authorizer->denyType($documentType, $request);
        if ($denied !== null) {
            return $denied;
        }

        return response()->json([
            'data' => $this->previewDocuments->recent($documentType, $request),
            'document_type' => $documentType,
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

    private function previewWithDocument(DocumentTemplate $draft, string $documentId, Request $request): JsonResponse
    {
        $documentType = (string) $draft->document_type;

        if (! $this->dataSources->has($documentType)) {
            return response()->json(['message' => "No render path for document type '{$documentType}'."], 422);
        }

        $denied = $this->authorizer->deny($documentType, $documentId, $request);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $result = $this->pdf->previewDocument($draft, $documentType, $documentId);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Document not found.'], 403);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'html' => $result['html'],
            'warnings' => $result['warnings'],
            'document_id' => $documentId,
        ]);
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
