<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use DOMElement;
use Dompdf\Canvas;
use Dompdf\FontMetrics;
use Dompdf\Frame;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\GeneratedDocument;
use Modules\TemplateBuilder\Services\DataSource\DataSourceRegistry;

final class PdfGenerationService implements PdfGenerationServiceContract
{
    /**
     * Issue #617: display text for each non-canonical document variant. The
     * canonical document (no variant, or a variant key not listed here) has
     * no banner.
     *
     * @var array<string,string>
     */
    private const DOCUMENT_VARIANTS = [
        'acceptance_copy' => 'ACCEPTANCE COPY — NOT A TAX INVOICE',
    ];

    public function __construct(
        private readonly DataSourceRegistry $dataSources,
        private readonly TemplateRendererService $renderer,
        private readonly DocumentTemplateServiceContract $templates,
        private readonly MergeFieldResolver $mergeFields,
    ) {}

    public function render(string $documentType, string $documentId, array $options = [], ?array $context = null): array
    {
        $format = $options['format'] ?? 'pdf';
        $variant = $this->documentVariantText($options['document_variant'] ?? null);

        // Issue #613 (TP3): a posted/issued document is reprinted from its
        // stored immutable copy — never re-rendered against the current
        // template or current company data. An explicit template selection or
        // a signed render is an intentional one-off and bypasses the snapshot.
        //
        // Issue #617: a requested variant (acceptance copy) is a different
        // document from the issued tax invoice, so it must never be served
        // from that immutable copy either.
        if ($variant === null && ! isset($options['template_id']) && ! isset($options['signature'])) {
            $stored = $this->storedCopy($documentType, $documentId, $format);

            if ($stored !== null) {
                $bytes = $this->readStoredBytes($stored);

                if ($bytes !== null) {
                    return [
                        'bytes' => $bytes,
                        'html' => $format === 'html' ? $bytes : '',
                        'mime' => $format === 'html' ? 'text/html' : 'application/pdf',
                        'hash' => (string) $stored->render_hash,
                        'template' => $stored->template,
                        'document' => $stored,
                        'immutable' => true,
                    ];
                }

                // The row survives but its bytes are gone. Serve a live render
                // rather than failing the reprint, but report the missing file
                // — never hide it.
                Log::warning('Immutable generated document file missing; falling back to live render', [
                    'generated_document_id' => $stored->id,
                    'document_type' => $documentType,
                    'document_id' => $documentId,
                    'disk' => $stored->disk,
                    'disk_path' => $stored->disk_path,
                ]);
            }
        }

        // Issue #612: resolve the template against the DOCUMENT's context
        // (selling entity, branch, currency, customer language/group,
        // channel) — not as an anonymous tenant render. Callers may override
        // explicitly; null means "derive from the document's data source".
        $context ??= $this->dataSources->resolutionContextFor($documentType, $documentId);

        $template = isset($options['template_id'])
            ? $this->templates->find($options['template_id'])
            : $this->templates->resolveFor($documentType, $context);

        // Lazily provision the tenant's system-default starter templates when
        // none is assigned for this document type yet. Without this, a tenant
        // that never manually created a template has NO published default for
        // any type (seedDefaults() is only wired to a job that is never
        // dispatched), so every render threw and callers silently degraded to
        // their legacy fallbacks.
        if (! $template && ! isset($options['template_id'])) {
            $this->templates->seedDefaults();
            $template = $this->templates->resolveFor($documentType, $context);
        }

        if (! $template) {
            throw new \RuntimeException("No template available to render document type '{$documentType}'.");
        }

        if ($template->document_type !== $documentType) {
            throw new \RuntimeException("The selected template is not a {$documentType} template.");
        }

        if (isset($options['template_id']) && $template->status !== DocumentTemplate::STATUS_PUBLISHED) {
            throw new \RuntimeException('The selected template is not published.');
        }

        $data = $this->dataSources->loadPayload($documentType, $documentId);
        // Issue #617: expose the requested variant to the template. Empty on
        // the canonical (final) document, so the shipped default invoice
        // template's conditional banner stays hidden.
        $data['document_variant'] = $variant ?? '';
        $built = $this->build($template, $data, $format, $options['signature'] ?? null);

        $ttl = (int) config('templatebuilder.pdf.cache_ttl_min', 1440);

        $document = GeneratedDocument::create([
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

        if (($options['immutable'] ?? false) === true) {
            $this->persistImmutable($document, $built);
        }

        return $built + ['template' => $template, 'document' => $document];
    }

    public function snapshot(string $documentType, string $documentId, array $options = []): GeneratedDocument
    {
        // Idempotent: once a posted document is snapshotted, later triggers
        // (re-sent, re-posted, observer re-entry) must never re-render it.
        $existing = $this->storedCopy($documentType, $documentId, 'pdf');

        if ($existing !== null) {
            return $existing;
        }

        $result = $this->render(
            $documentType,
            $documentId,
            array_merge($options, ['format' => 'pdf', 'immutable' => true]),
        );

        return $result['document'];
    }

    public function regenerate(string $documentType, string $documentId, array $options = []): GeneratedDocument
    {
        // "Regenerate with current template" creates a NEW version and keeps
        // the original: previous immutable copies are marked superseded, never
        // overwritten or deleted. All inside one transaction so a failed
        // re-render leaves the previously issued copy current.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($documentType, $documentId, $options) {
            GeneratedDocument::query()
                ->where('document_type', $documentType)
                ->where('document_id', (string) $documentId)
                ->where('output_format', 'pdf')
                ->where('is_immutable', true)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);

            $result = $this->render(
                $documentType,
                $documentId,
                array_merge($options, ['format' => 'pdf', 'immutable' => true]),
            );

            return $result['document'];
        });
    }

    public function storedCopy(string $documentType, string $documentId, string $format = 'pdf'): ?GeneratedDocument
    {
        return GeneratedDocument::query()
            ->where('document_type', $documentType)
            ->where('document_id', (string) $documentId)
            ->where('output_format', $format)
            ->where('is_immutable', true)
            ->whereNull('superseded_at')
            ->orderByDesc('generated_at')
            ->first();
    }

    public function renderData(string $documentType, array $data, ?string $templateId = null, string $format = 'pdf'): array
    {
        $template = $templateId
            ? $this->templates->find($templateId)
            : $this->templates->resolveFor($documentType);

        if (! $template && ! $templateId) {
            $this->templates->seedDefaults();
            $template = $this->templates->resolveFor($documentType);
        }

        if (! $template) {
            throw new \RuntimeException("No template available to render document type '{$documentType}'.");
        }

        return $this->build($template, $data, $format);
    }

    /**
     * Issue #617: display text for a requested document variant, or null when
     * the request is for the canonical document (no/unknown variant key).
     */
    private function documentVariantText(?string $variant): ?string
    {
        if ($variant === null || $variant === '') {
            return null;
        }

        return self::DOCUMENT_VARIANTS[$variant] ?? null;
    }

    /**
     * Persist the rendered bytes as an immutable copy: object storage +
     * checksum, linked to the source document and template version.
     *
     * @param  array{bytes: string, html: string, mime: string, hash: string}  $built
     */
    private function persistImmutable(GeneratedDocument $document, array $built): void
    {
        $disk = (string) config('templatebuilder.pdf.snapshot_disk', 'local');
        $extension = ($built['mime'] ?? '') === 'text/html' ? 'html' : 'pdf';
        $path = 'generated-documents/'.$document->document_type.'/'.$document->document_id.'/'.$document->id.'.'.$extension;

        Storage::disk($disk)->put($path, $built['bytes']);

        $document->forceFill([
            'disk' => $disk,
            'disk_path' => $path,
            'checksum' => hash('sha256', $built['bytes']),
            'is_immutable' => true,
            'size_bytes' => strlen($built['bytes']),
            // An issued copy never expires with the render cache.
            'expires_at' => null,
        ])->save();
    }

    /**
     * Read a stored immutable copy. Returns null when the bytes are gone;
     * throws when the bytes no longer match their recorded checksum (serving
     * tampered output as an issued document would be worse than failing).
     */
    private function readStoredBytes(GeneratedDocument $document): ?string
    {
        $path = (string) $document->disk_path;

        if ($path === '') {
            return null;
        }

        $disk = Storage::disk($document->disk ?: (string) config('templatebuilder.pdf.snapshot_disk', 'local'));

        if (! $disk->exists($path)) {
            return null;
        }

        $bytes = $disk->get($path);

        if ($bytes === null) {
            return null;
        }

        if ($document->checksum !== null
            && ! hash_equals((string) $document->checksum, hash('sha256', $bytes))) {
            throw new \RuntimeException('The stored copy of this document failed its checksum and was not served.');
        }

        return $bytes;
    }

    /**
     * Build the final output bytes + cache hash.
     *
     * @param  array<string,mixed>  $data
     * @return array{bytes: string, html: string, mime: string, hash: string}
     */
    private function build(DocumentTemplate $template, array $data, string $format, ?array $signature = null): array
    {
        // Production PDFs render the PUBLISHED version, never an unsaved draft.
        $renderTemplate = $this->forRendering($template);

        $rendered = $this->renderer->render($renderTemplate, $data);

        // A signed document carries its signature block on the same template, so
        // every tenant template gets one without being edited.
        $body = $rendered['html']
            .($signature !== null ? $this->signatureBlockHtml($signature) : '')
            .$this->fxFooterHtml($renderTemplate, $data);

        $fullHtml = $this->wrapCanvas($renderTemplate, $body);

        $hash = hash('sha256', $template->id.'|'.$template->published_version.'|'.json_encode($data).($signature !== null ? '|signed' : ''));

        if ($format === 'html') {
            return ['bytes' => $fullHtml, 'html' => $fullHtml, 'mime' => 'text/html', 'hash' => $hash];
        }

        // Pass 1 — natural flow, NO reserved decoration band. The band costs
        // 40mm of page-1 height, which pushed tall footers and totals onto a
        // second page even for 4-line invoices (report 2026-10-01).
        $pdf = Pdf::loadHTML($fullHtml);
        $pdf->setPaper($this->paperSize($renderTemplate), $renderTemplate->orientation ?: 'portrait');
        $pdf->render();
        $pageCount = (int) $pdf->getDomPDF()->getCanvas()->get_page_count();

        // Pass 2 — pin the final-page footer/totals on every document, but
        // reserve the 40mm decoration band only for genuinely multi-page
        // output. On a single page the band is what pushed short invoices
        // over the page limit (report 2026-10-01); with band 0 the pinned
        // footer uses the blank tail of the page instead.
        $decorationBandMm = $this->decorationBandMm($renderTemplate);
        if ($decorationBandMm > 0) {
            $bandMm = $pageCount > 1 ? $decorationBandMm : 0;
            $fullHtml = $this->wrapCanvas($renderTemplate, $body, $bandMm);
            $pdf = Pdf::loadHTML($fullHtml);
            $pdf->setPaper($this->paperSize($renderTemplate), $renderTemplate->orientation ?: 'portrait');
            $this->configurePhysicalPageDecorations($pdf, $renderTemplate, $data);
        }

        $bytes = $pdf->output();

        return ['bytes' => $bytes, 'html' => $fullHtml, 'mime' => 'application/pdf', 'hash' => $hash];
    }

    /**
     * Appended to the rendered HTML when a document has been signed. Carries the
     * signature image plus the audit facts a signed document needs (signer,
     * time, method, IP, document hash).
     *
     * @param  array{data_uri:string,signer_name?:?string,signer_email?:?string,signed_at?:?string,date?:?string,method?:?string,ip?:?string,document_hash?:?string}  $signature
     */
    private function signatureBlockHtml(array $signature): string
    {
        $e = fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $src = (string) ($signature['data_uri'] ?? '');

        if ($src === '') {
            return '';
        }

        $rows = array_filter([
            'Signed at' => $signature['signed_at'] ?? null,
            'Method' => $signature['method'] ?? null,
            'Signed by' => $signature['signer_email'] ?? null,
            'IP address' => $signature['ip'] ?? null,
            'Document hash' => isset($signature['document_hash']) ? substr((string) $signature['document_hash'], 0, 32).'…' : null,
        ], fn ($v) => $v !== null && $v !== '');

        $audit = '';
        foreach ($rows as $label => $value) {
            $audit .= '<div style="margin-bottom:1px;"><strong>'.$e((string) $label).':</strong> '.$e((string) $value).'</div>';
        }

        $date = $signature['date'] ?? null;
        $dateLine = $date !== null && $date !== ''
            ? '<div style="margin-top:4px; font-size:11px; color:#475569;"><strong>Received:</strong> '.$e((string) $date).'</div>'
            : '';

        return '<div style="page-break-inside:avoid; margin-top:26px; padding-top:10px; border-top:1px solid #cbd5e1;">
            <div style="font-size:9px; letter-spacing:.08em; text-transform:uppercase; color:#64748b; margin-bottom:8px;">Signed electronically</div>
            <table style="width:100%; border-collapse:collapse;">
                <tr>
                    <td style="width:46%; vertical-align:bottom; padding-right:12px;">
                        <img src="'.$e($src).'" style="max-height:64px; max-width:230px;" />
                        <div style="border-top:1px solid #94a3b8; margin-top:5px; padding-top:4px; font-size:11px;">
                            <strong>'.($signature['signer_name'] ?? null ? 'Received by: '.$e((string) $signature['signer_name']) : 'Received by: Customer').'</strong>
                            '.($signature['signer_email'] ?? null ? '<br /><span style="color:#475569;">'.$e((string) $signature['signer_email']).'</span>' : '').'
                            '.$dateLine.'
                        </div>
                    </td>
                    <td style="width:54%; vertical-align:top; font-size:9px; color:#475569;">'.$audit.'</td>
                </tr>
            </table>
        </div>';
    }

    /**
     * Let Dompdf perform normal physical pagination, record the page on which
     * each complete product row was rendered, then decorate every physical
     * page using those actual placements.
     *
     * @param  array<string,mixed>  $data
     */
    public function configurePhysicalPageDecorations(mixed $pdf, DocumentTemplate $template, array $data): void
    {
        $config = $this->paginationConfig($template);
        if ($config === null || ($config['paginate'] ?? true) === false) {
            return;
        }

        $pageSubtotals = [];
        $seenRows = [];
        $footerTop = null;
        $totalsFrame = null;
        // Numeric copies captured during layout: frame decorators are only
        // valid while layout runs, so end_document must not touch them.
        $totalsHeight = null;
        $totalsPosition = null;
        $showSubtotal = ($config['show_page_subtotal'] ?? true) !== false;
        $subtotalLabel = (string) ($config['subtotal_label'] ?? 'Page Subtotal');
        $subtotalFormat = (string) ($config['subtotal_format'] ?? 'money');
        $subtotalFontSize = max(6, min(18, (int) ($config['subtotal_font_size'] ?? 9)));
        $subtotalGapMm = max(0, min(30, (float) ($config['subtotal_gap_mm'] ?? 3)));
        $margin = is_array($template->margin) ? $template->margin : config('templatebuilder.defaults.margin_mm');
        $pointsPerMm = 72 / 25.4;

        $pdf->setCallbacks([
            [
                'event' => 'begin_frame',
                'f' => function (Frame $frame, Canvas $canvas) use (&$footerTop, &$totalsFrame, &$totalsHeight, &$totalsPosition, $margin, $pointsPerMm): void {
                    $node = $frame->get_node();
                    if (! $node instanceof DOMElement) {
                        return;
                    }

                    $classes = ' '.$node->getAttribute('class').' ';
                    if (str_contains($classes, ' tb-document-totals ')) {
                        $totalsFrame = $frame;

                        // The footer is FIRST in the DOM, so on the page where
                        // both land its begin_frame (and paint) already
                        // happened: pin the totals just above the footer now,
                        // before this frame is painted (moving it afterwards
                        // from another callback would be too late — frames are
                        // painted as they are traversed, in DOM order).
                        if ($footerTop !== null) {
                            $totalsHeight = $frame->get_margin_height();
                            $totalsTargetY = $footerTop
                                - (3 * $pointsPerMm)
                                - $totalsHeight;
                            $frame->move(0, $totalsTargetY - (float) $frame->get_position('y'));
                            $totalsPosition = [
                                'page' => $canvas->get_page_number(),
                                'y' => $totalsTargetY,
                            ];
                        }

                        return;
                    }

                    if (! str_contains($classes, ' tb-document-footer ')) {
                        return;
                    }

                    $targetY = $canvas->get_height()
                        - (((float) ($margin['bottom'] ?? 15)) * $pointsPerMm)
                        - $frame->get_margin_height();
                    $frame->move(0, $targetY - (float) $frame->get_position('y'));
                    $footerTop = $targetY;
                },
            ],
            [
                'event' => 'end_frame',
                'f' => function (Frame $frame, Canvas $canvas) use (&$pageSubtotals, &$seenRows, &$totalsPosition): void {
                    $node = $frame->get_node();
                    if (! $node instanceof DOMElement) {
                        return;
                    }

                    if (str_contains(' '.$node->getAttribute('class').' ', ' tb-document-totals ')) {
                        $totalsPosition = [
                            'page' => $canvas->get_page_number(),
                            'y' => (float) $frame->get_position('y'),
                        ];
                    }

                    if (! $node->hasAttribute('data-tb-line-index')) {
                        return;
                    }

                    $index = $node->getAttribute('data-tb-line-index');
                    if (isset($seenRows[$index])) {
                        return;
                    }

                    $page = $canvas->get_page_number();
                    $amount = (float) $node->getAttribute('data-tb-line-amount');
                    $pageSubtotals[$page] = ($pageSubtotals[$page] ?? 0.0) + $amount;
                    $seenRows[$index] = $page;
                },
            ],
            [
                'event' => 'end_document',
                'f' => function (int $pageNumber, int $pageCount, Canvas $canvas, FontMetrics $fontMetrics) use (
                    &$pageSubtotals,
                    &$footerTop,
                    &$totalsHeight,
                    &$totalsPosition,
                    $data,
                    $showSubtotal,
                    $subtotalLabel,
                    $subtotalFormat,
                    $subtotalFontSize,
                    $subtotalGapMm,
                    $margin,
                    $pointsPerMm
                ): void {
                    $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
                    $bold = $fontMetrics->getFont('DejaVu Sans', 'bold');
                    $pageWidth = $canvas->get_width();
                    $pageHeight = $canvas->get_height();
                    $left = (float) ($margin['left'] ?? 12) * $pointsPerMm;
                    $right = (float) ($margin['right'] ?? 12) * $pointsPerMm;
                    $top = max(8.0, ((float) ($margin['top'] ?? 15) * $pointsPerMm / 2) - 4);
                    $number = $pageNumber.'/'.$pageCount;
                    $numberWidth = $fontMetrics->getTextWidth($number, $font, 8);
                    $canvas->text($pageWidth - $right - $numberWidth, $top, $number, $font, 8, [0.42, 0.45, 0.50]);

                    if (! $showSubtotal || ! array_key_exists($pageNumber, $pageSubtotals)) {
                        return;
                    }

                    $this->mergeFields->with($data);
                    $value = (string) $this->mergeFields->formatValue($pageSubtotals[$pageNumber], $subtotalFormat);
                    $size = $subtotalFontSize;
                    // Earlier pages use the reserved bottom decoration band. On the
                    // final page, use the blank slot immediately above overall totals.
                    $defaultY = $pageHeight - (((float) ($margin['bottom'] ?? 15) + 4) * $pointsPerMm);
                    if ($pageNumber === $pageCount && $footerTop !== null && $totalsHeight !== null) {
                        // The footer's begin_frame callback pins the footer to the
                        // page bottom and moves the totals above it (3mm gap).
                        // Recompute the totals' final top from those two facts:
                        // the callback-order-dependent $totalsPosition is unreliable
                        // since the totals/footer DOM order changed (2026-10-01).
                        $y = $footerTop
                            - (3 * $pointsPerMm)
                            - $totalsHeight
                            - ($subtotalGapMm * $pointsPerMm);
                    } elseif ($pageNumber === $pageCount && ($totalsPosition['page'] ?? null) === $pageNumber) {
                        $y = (float) $totalsPosition['y'] - ($subtotalGapMm * $pointsPerMm);
                    } elseif ($footerTop !== null && $pageNumber === $pageCount) {
                        $y = min($defaultY, $footerTop - (8 * $pointsPerMm));
                    } else {
                        $y = $defaultY;
                    }
                    $valueWidth = $fontMetrics->getTextWidth($value, $bold, $size);
                    $gridRight = $pageWidth - $right;
                    $gridLeft = $gridRight - (($gridRight - $left) * 0.55);
                    $cellPadding = 7.5; // Matches the Totals table's 10px cell padding.
                    $labelX = $gridLeft + $cellPadding;
                    $valueX = $gridRight - $cellPadding - $valueWidth;
                    $canvas->line($gridLeft, $y - 4, $gridRight, $y - 4, [0.82, 0.84, 0.87], 0.6);
                    $canvas->text($labelX, $y, $subtotalLabel, $bold, $size, [0.12, 0.16, 0.23]);
                    $canvas->text($valueX, $y, $value, $bold, $size, [0.12, 0.16, 0.23]);
                },
            ],
        ]);
    }

    /** @return array<string,mixed>|null */
    private function paginationConfig(DocumentTemplate $template): ?array
    {
        $blocks = is_array($template->layout_config) ? ($template->layout_config['blocks'] ?? []) : [];

        return $this->findPaginatedTable(is_array($blocks) ? $blocks : []);
    }

    /**
     * @param  array<int,array<string,mixed>>  $blocks
     * @return array<string,mixed>|null
     */
    private function findPaginatedTable(array $blocks): ?array
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'table' && ($block['paginate'] ?? true) !== false) {
                return $block;
            }
            if (is_array($block['children'] ?? null)) {
                $found = $this->findPaginatedTable($block['children']);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Return a template instance carrying the PUBLISHED definition (layout/html/css)
     * when one exists, otherwise the live draft. Production rendering must never
     * use un-published edits.
     */
    private function forRendering(DocumentTemplate $template): DocumentTemplate
    {
        if ($template->published_version > 0) {
            $definition = $template->getPublishedDefinition();

            $clone = $template->replicate(['id']);
            $clone->id = $template->id;
            $clone->layout_config = $definition['layout_config'];
            $clone->html = $definition['html'];
            $clone->css = $definition['css'];

            return $clone;
        }

        return $template;
    }

    /**
     * MRD pricelist-multicurrency FR-8.5 — AUTOMATIC dual-currency footer for
     * foreign-currency documents: document currency primary, snapshot rate +
     * base total underneath. Skipped when the template already draws its own
     * footer block (no duplication), and when the document is base-currency.
     *
     * @param  array<string,mixed>  $data
     */
    private function fxFooterHtml(DocumentTemplate $template, array $data): string
    {
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $totals = is_array($data['totals'] ?? null) ? $data['totals'] : [];

        $currency = strtoupper((string) ($meta['currency_code'] ?? ''));
        $baseCurrency = strtoupper((string) ($meta['base_currency_code'] ?? ''));
        $rate = (float) ($meta['exchange_rate'] ?? 1.0);

        if ($currency === '' || $baseCurrency === '' || $currency === $baseCurrency || $rate <= 0) {
            return '';
        }

        // FR-8.5: the footnote is AUTOMATIC for every foreign-currency
        // document — provenance of the rate must not depend on template
        // authoring. (Templates that print their own FX line will simply
        // repeat the same frozen numbers, never contradictory ones.)
        $e = fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $symbol = fn (string $code): string => \Modules\TemplateBuilder\Services\DataSource\InvoiceDataSource::currencySymbol($code);

        $documentTotal = isset($totals['total']) ? number_format((float) $totals['total'], 2) : null;
        $baseTotal = isset($totals['base_total']) ? number_format((float) $totals['base_total'], 2) : null;
        $rateText = rtrim(rtrim(number_format($rate, 6, '.', ''), '0'), '.');
        $rateDate = (string) ($meta['rate_date'] ?? '');

        $parts = [];
        if ($documentTotal !== null) {
            $parts[] = 'Document total: '.$e($symbol($currency).' '.$documentTotal);
        }
        $parts[] = 'Exchange rate: 1 '.$e($currency).' = '.$e($rateText).' '.$e($baseCurrency)
            .($rateDate !== '' ? ' ('.$e($rateDate).')' : '');
        if ($baseTotal !== null) {
            $parts[] = 'Base total: '.$e($symbol($baseCurrency).' '.$baseTotal);
        }

        return '<div class="tb-fx-footer" style="margin-top:6mm; padding-top:2mm; border-top:1px solid #e5e7eb; '
            .'text-align:right; font-size:9px; color:#6b7280; line-height:1.5;">'
            .implode(' &middot; ', $parts)
            .'</div>';
    }

    private function wrapCanvas(DocumentTemplate $template, string $body, ?int $bandMm = null): string
    {
        $font = $template->default_font ?: config('templatebuilder.defaults.font', 'DejaVu Sans');
        $margin = is_array($template->margin) ? $template->margin : config('templatebuilder.defaults.margin_mm');

        return view('templatebuilder::canvas', [
            'body' => $body,
            'font' => $font,
            'margin' => $margin,
            'css' => (string) ($template->css ?? ''),
            'contentHeightMm' => $this->contentHeightMm($template, $margin),
            'bottomBandMm' => $bandMm ?? $this->decorationBandMm($template),
        ])->render();
    }

    /**
     * The physical bottom band (page subtotals / pinned footer) is only
     * reserved when this template actually draws into it.
     */
    private function decorationBandMm(DocumentTemplate $template): int
    {
        $pagination = $this->paginationConfig($template);
        $reserveBand = $pagination !== null
            && (($pagination['show_page_subtotal'] ?? true) !== false || $this->containsBlockType(
                is_array($template->layout_config['blocks'] ?? null) ? $template->layout_config['blocks'] : [],
                'footer',
            ));

        return $reserveBand ? 40 : 0;
    }

    /**
     * @param  array<int,array<string,mixed>>  $blocks
     */
    private function containsBlockType(array $blocks, string $type): bool
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === $type) {
                return true;
            }
            if (is_array($block['children'] ?? null) && $this->containsBlockType($block['children'], $type)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $margin */
    private function contentHeightMm(DocumentTemplate $template, array $margin): float
    {
        $paperHeight = match ($template->format) {
            'Letter' => 279.4,
            'A5' => 210.0,
            '80mm', '58mm' => 229.3,
            default => 297.0,
        };

        if (($template->orientation ?: 'portrait') === 'landscape' && in_array($template->format, ['A4', 'Letter', 'A5'], true)) {
            $paperHeight = match ($template->format) {
                'Letter' => 215.9,
                'A5' => 148.0,
                default => 210.0,
            };
        }

        return max(40, $paperHeight - (float) ($margin['top'] ?? 15) - (float) ($margin['bottom'] ?? 15));
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
