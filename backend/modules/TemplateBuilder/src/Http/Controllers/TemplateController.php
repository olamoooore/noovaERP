<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Modules\TemplateBuilder\Http\Requests\StoreTemplateRequest;
use Modules\TemplateBuilder\Http\Requests\UpdateTemplateRequest;
use Modules\TemplateBuilder\Http\Resources\TemplateResource;
use Modules\TemplateBuilder\Services\DataSource\DataSourceRegistry;
use Modules\TemplateBuilder\Services\PdfGenerationService;

class TemplateController extends Controller
{
    public function __construct(
        private readonly DocumentTemplateServiceContract $service,
        private readonly PdfGenerationServiceContract $pdf,
        private readonly DataSourceRegistry $dataSources,
    ) {}

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

    /** Catalog of predefined starter layouts. */
    public function starters(): JsonResponse
    {
        return response()->json(['data' => $this->service->listStarters()]);
    }

    /** Create a user-owned template pre-filled from a starter layout. */
    public function createFromStarter(string $documentType): JsonResponse
    {
        try {
            $template = $this->service->createFromStarter($documentType);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new TemplateResource($template))->response()->setStatusCode(201);
    }

    /** Map of document_type => currently-resolved template id. */
    public function activeDefaults(): JsonResponse
    {
        return response()->json(['data' => $this->service->activeDefaults()]);
    }

    /** Render the template against sample data and stream a real PDF. */
    public function samplePdf(string $id): Response
    {
        $template = $this->service->find($id);
        if (! $template) {
            return response(['message' => 'Template not found'], 404);
        }

        if (! $this->dataSources->has($template->document_type)) {
            return response(['message' => "No sample data for '{$template->document_type}'."], 422);
        }

        $sample = $this->dataSources->sampleFor($template->document_type);

        try {
            // Render the LIVE (draft) layout so authors preview their latest edits.
            $result = app(\Modules\TemplateBuilder\Services\TemplateRendererService::class)
                ->render($template, $sample);
            $fullHtml = view('templatebuilder::canvas', [
                'body' => $result['html'],
                'font' => $template->default_font ?: config('templatebuilder.defaults.font', 'DejaVu Sans'),
                'margin' => is_array($template->margin) ? $template->margin : config('templatebuilder.defaults.margin_mm'),
                'css' => (string) ($template->css ?? ''),
            ])->render();

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($fullHtml);
            $paper = match ($template->format) {
                'A4' => 'a4', 'Letter' => 'letter', 'A5' => 'a5',
                '80mm' => [0, 0, 226.77, 650], '58mm' => [0, 0, 164.41, 650], default => 'a4',
            };
            $pdf->setPaper($paper, $template->orientation ?: 'portrait');
            app(PdfGenerationService::class)->configurePhysicalPageDecorations($pdf, $template, $sample);
            $bytes = $pdf->output();
        } catch (\Throwable $e) {
            return response(['message' => 'Render failed: '.$e->getMessage()], 500);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="template-'.$id.'-sample.pdf"',
            'Content-Length' => strlen($bytes),
        ]);
    }
}
