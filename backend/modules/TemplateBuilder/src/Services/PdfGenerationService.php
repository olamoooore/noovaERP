<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\GeneratedDocument;
use Modules\TemplateBuilder\Services\DataSource\DataSourceRegistry;

final class PdfGenerationService implements PdfGenerationServiceContract
{
    public function __construct(
        private readonly DataSourceRegistry $dataSources,
        private readonly TemplateRendererService $renderer,
        private readonly DocumentTemplateServiceContract $templates,
    ) {}

    public function render(string $documentType, string $documentId, array $options = []): array
    {
        $template = isset($options['template_id'])
            ? ($this->templates->find($options['template_id']) ?? $this->templates->resolveFor($documentType))
            : $this->templates->resolveFor($documentType);

        if (! $template) {
            throw new \RuntimeException("No template available to render document type '{$documentType}'.");
        }

        $data = $this->dataSources->loadPayload($documentType, $documentId);
        $format = $options['format'] ?? 'pdf';
        $built = $this->build($template, $data, $format);

        $ttl = (int) config('templatebuilder.pdf.cache_ttl_min', 1440);

        GeneratedDocument::create([
            'document_type' => $documentType,
            'document_id' => $documentId,
            'template_id' => $template->id,
            'template_version' => $template->published_version,
            'render_hash' => $built['hash'],
            'output_format' => $format,
            'size_bytes' => strlen($built['bytes']),
            'generated_at' => now(),
            'expires_at' => Carbon::now()->addMinutes($ttl),
        ]);

        return $built + ['template' => $template];
    }

    public function renderData(string $documentType, array $data, ?string $templateId = null, string $format = 'pdf'): array
    {
        $template = $templateId
            ? $this->templates->find($templateId)
            : $this->templates->resolveFor($documentType);

        if (! $template) {
            throw new \RuntimeException("No template available to render document type '{$documentType}'.");
        }

        return $this->build($template, $data, $format);
    }

    /**
     * Build the final output bytes + cache hash.
     *
     * @param  array<string,mixed>  $data
     * @return array{bytes: string, html: string, mime: string, hash: string}
     */
    private function build(DocumentTemplate $template, array $data, string $format): array
    {
        $rendered = $this->renderer->render($template, $data);

        $fullHtml = $this->wrapCanvas($template, $rendered['html']);

        $hash = hash('sha256', $template->id.'|'.$template->published_version.'|'.json_encode($data));

        if ($format === 'html') {
            return ['bytes' => $fullHtml, 'html' => $fullHtml, 'mime' => 'text/html', 'hash' => $hash];
        }

        $pdf = Pdf::loadHTML($fullHtml);
        $pdf->setPaper($this->paperSize($template), $template->orientation ?: 'portrait');
        $bytes = $pdf->output();

        return ['bytes' => $bytes, 'html' => $fullHtml, 'mime' => 'application/pdf', 'hash' => $hash];
    }

    private function wrapCanvas(DocumentTemplate $template, string $body): string
    {
        $font = $template->default_font ?: config('templatebuilder.defaults.font', 'DejaVu Sans');
        $margin = is_array($template->margin) ? $template->margin : config('templatebuilder.defaults.margin_mm');

        return view('templatebuilder::canvas', [
            'body' => $body,
            'font' => $font,
            'margin' => $margin,
            'css' => (string) ($template->css ?? ''),
        ])->render();
    }

    /** @return string|array{0:int,1:int,2:int,3:int} */
    private function paperSize(DocumentTemplate $template): string|array
    {
        return match ($template->format) {
            'A4' => 'a4',
            'Letter' => 'letter',
            'A5' => 'a5',
            '80mm' => [0, 0, 226.77, 650],
            '58mm' => [0, 0, 164.41, 650],
            default => 'a4',
        };
    }
}
