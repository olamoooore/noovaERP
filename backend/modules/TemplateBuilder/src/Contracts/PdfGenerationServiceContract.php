<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Contracts;

use Modules\TemplateBuilder\Models\DocumentTemplate;

interface PdfGenerationServiceContract
{
    /**
     * Render a business record to PDF (or HTML) using its resolved template.
     *
     * @param  array{template_id?: ?string, format?: string, locale?: ?string}  $options
     * @return array{bytes: string, html: string, mime: string, hash: string, template: DocumentTemplate}
     */
    public function render(string $documentType, string $documentId, array $options = []): array;

    /**
     * Render an already-loaded data payload to raw bytes (used by preview/tests).
     *
     * @return array{bytes: string, html: string, mime: string, hash: string}
     */
    public function renderData(string $documentType, array $data, ?string $templateId = null, string $format = 'pdf'): array;
}
