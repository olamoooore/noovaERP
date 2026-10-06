<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Contracts;

use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\GeneratedDocument;

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
     * Issue #613: when an immutable copy exists for (document, format), it is
     * served instead of a fresh render — unless the caller explicitly selects
     * a template (`template_id`) or asks for a signed render (`signature`),
     * both of which are intentional one-off renders.
     *
     * Issue #617: `document_variant` asks for a non-canonical presentation of
     * the SAME record (e.g. `acceptance_copy` for an invoice sent for
     * approval). A variant is always rendered live — it never reuses the
     * immutable issued copy — and its display text is exposed to the template
     * as the `document_variant` token.
     *
     * @param  array{template_id?: ?string, format?: string, locale?: ?string, signature?: ?array, immutable?: bool, document_variant?: ?string}  $options
     * @param  array<string,mixed>|null  $context
     * @return array{bytes: string, html: string, mime: string, hash: string, template: DocumentTemplate|null, document?: GeneratedDocument, immutable?: bool}
     */
    public function render(string $documentType, string $documentId, array $options = [], ?array $context = null): array;

    /**
     * Render an already-loaded data payload to raw bytes (used by preview/tests).
     *
     * @return array{bytes: string, html: string, mime: string, hash: string}
     */
    public function renderData(string $documentType, array $data, ?string $templateId = null, string $format = 'pdf'): array;

    /**
     * Issue #620: render an editor DRAFT template against a real document's
     * payload through the same body/canvas pipeline as a real render. The
     * payload passes the registry choke point (PHI masking, pricelist display
     * mode). Nothing is persisted. Callers must authorize the document first
     * (DocumentRenderAuthorizer).
     *
     * @return array{html: string, warnings: array<int,string>}
     */
    public function previewDocument(DocumentTemplate $draft, string $documentType, string $documentId): array;

    /**
     * Store the immutable, checksummed copy of a posted/issued document and
     * return its record. Idempotent: an existing current immutable copy is
     * returned untouched (never re-rendered).
     *
     * @param  array<string,mixed>  $options
     */
    public function snapshot(string $documentType, string $documentId, array $options = []): GeneratedDocument;

    /**
     * Explicitly re-issue a document with the currently published template:
     * the new copy becomes the current one and every previous copy is retained
     * (marked superseded), so the original issue is never destroyed.
     *
     * @param  array<string,mixed>  $options
     */
    public function regenerate(string $documentType, string $documentId, array $options = []): GeneratedDocument;

    /**
     * The currently served immutable copy for a document/format, or null when
     * the document has no stored copy (drafts, legacy rows).
     */
    public function storedCopy(string $documentType, string $documentId, string $format = 'pdf'): ?GeneratedDocument;
}
