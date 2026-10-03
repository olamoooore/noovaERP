<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Contracts;

use Modules\TemplateBuilder\Models\DocumentTemplate;

interface PdfGenerationServiceContract
{
    /**
     * Render a business record to PDF (or HTML) using its resolved template.
     *
     * The template is resolved against the document's own context — entity,
     * branch, currency, customer language/group, channel (issue #612). Pass
     * an explicit $context to override; null derives it from the document's
     * data source.
     *
     * @param  array{template_id?: ?string, format?: string, locale?: ?string}  $options
     * @param  array<string,mixed>|null  $context
     * @return array{bytes: string, html: string, mime: string, hash: string, template: DocumentTemplate}
     */
    public function render(string $documentType, string $documentId, array $options = [], ?array $context = null): array;

    /**
     * Render an already-loaded data payload to raw bytes (used by preview/tests).
     *
     * @return array{bytes: string, html: string, mime: string, hash: string}
     */
    public function renderData(string $documentType, array $data, ?string $templateId = null, string $format = 'pdf'): array;
}
