<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendInvoiceBundleJob;
use App\Models\Accounting\ComplianceDocument;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Modules\TemplateBuilder\Services\DocumentRenderAuthorizer;
use ZipArchive;

/**
 * Issue #329 (Odoo 20 parity): Bulk Send and the zip download of the selected
 * invoices' documents.
 *
 * Every selected id is authorized on its own through the #611
 * DocumentRenderAuthorizer — the invoice must exist and sit inside the
 * request's legal entity. A cross-entity id gets exactly the same result as
 * a non-existent one and its number is never echoed back.
 */
class InvoiceBulkDocumentService
{
    public const BULK_SEND_LIMIT = 100;

    /** PDFs are rendered in the request, so the zip is capped lower. */
    public const BULK_DOWNLOAD_LIMIT = 50;

    public const NOT_ACCESSIBLE_REASON = 'Invoice not found or not accessible.';

    public function __construct(
        private DocumentRenderAuthorizer $authorizer,
        private InvoiceSendBundleService $bundle,
        private PdfGenerationServiceContract $pdf,
    ) {}

    /**
     * Check every selected invoice and queue one bundle send per eligible
     * one. Returns one result per distinct id: queued, or skipped with why.
     *
     * @param  array<int, int|string>  $ids
     * @return list<array<string, mixed>>
     */
    public function queueSend(array $ids, Request $request, User $user): array
    {
        $tenantId = (string) tenant('id');
        $results = [];

        foreach ($this->distinctIds($ids) as $id) {
            $invoice = $this->accessibleInvoice($id, $request);

            if ($invoice === null) {
                $results[] = ['id' => $id, 'outcome' => 'skipped', 'reason' => self::NOT_ACCESSIBLE_REASON];

                continue;
            }

            $reason = $this->bundle->unsendableReason($invoice);
            if ($reason !== null) {
                $results[] = $this->result($invoice, 'skipped', $reason);

                continue;
            }

            SendInvoiceBundleJob::dispatch($tenantId, (int) $invoice->id, (int) $user->id);

            $results[] = $this->result($invoice, 'queued');
        }

        return $results;
    }

    /**
     * Build a zip with each accessible invoice's PDF and, where one exists,
     * its e-invoice payload (XML or JSON), plus a manifest of what was
     * included, skipped and failed. Null when none of the ids is accessible.
     *
     * @param  array<int, int|string>  $ids
     * @return array{bytes: string, included: int}|null
     */
    public function buildZip(array $ids, Request $request): ?array
    {
        $invoices = [];
        $manifest = [];

        foreach ($this->distinctIds($ids) as $id) {
            $invoice = $this->accessibleInvoice($id, $request);

            if ($invoice === null) {
                $manifest[] = "Skipped  id {$id}: ".self::NOT_ACCESSIBLE_REASON;

                continue;
            }

            $invoices[] = $invoice;
        }

        if ($invoices === []) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'invoice-bulk-zip-');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new \RuntimeException('Could not create the zip archive.');
        }

        $usedNames = [];
        $included = 0;

        try {
            foreach ($invoices as $invoice) {
                $base = $this->uniqueBaseName($invoice, $usedNames);
                $files = [];

                try {
                    // Same render as GET /invoices/{id}/pdf: a posted invoice is
                    // reprinted from its stored immutable copy (#613).
                    $rendered = $this->pdf->render('invoice', (string) $invoice->id, ['format' => 'pdf']);
                    $zip->addFromString("{$base}.pdf", $rendered['bytes']);
                    $files[] = 'PDF';
                } catch (\Throwable $e) {
                    Log::warning('Invoice bulk download: PDF render failed', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                    $manifest[] = "Failed   {$invoice->invoice_number}: PDF could not be generated ({$e->getMessage()})";
                }

                $eInvoice = $this->eInvoicePayload($invoice);
                if ($eInvoice !== null) {
                    $zip->addFromString("{$base}.einvoice.{$eInvoice['extension']}", $eInvoice['content']);
                    $files[] = 'e-invoice '.strtoupper($eInvoice['extension']);
                }

                if ($files !== []) {
                    $included++;
                    $manifest[] = "Included {$invoice->invoice_number}: ".implode(', ', $files);
                }
            }

            $zip->addFromString('manifest.txt', implode("\n", $manifest)."\n");
            $zip->close();

            $bytes = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }

        return ['bytes' => $bytes, 'included' => $included];
    }

    /**
     * The invoice when it exists and is inside the request's legal-entity
     * scope (the #611 render authorizer), null otherwise.
     */
    private function accessibleInvoice(int $id, Request $request): ?Invoice
    {
        if (! $this->authorizer->visibleInRequestEntity('invoice', (string) $id, $request)) {
            return null;
        }

        return Invoice::with('customer')->find($id);
    }

    /**
     * The latest e-invoice payload stored for the invoice: the signed
     * document when there is one, otherwise the mapped country payload.
     *
     * @return array{extension: string, content: string}|null
     */
    private function eInvoicePayload(Invoice $invoice): ?array
    {
        $query = ComplianceDocument::query()
            ->where('document_type', 'invoice')
            ->where('document_id', $invoice->id);

        $tenantId = tenancy()->tenant?->id;
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $document = $query->orderByDesc('id')->first();
        if ($document === null) {
            return null;
        }

        $payload = ! empty($document->signed_payload) ? $document->signed_payload : $document->mapped_payload;
        if (empty($payload)) {
            return null;
        }

        if (is_string($payload)) {
            $isXml = str_starts_with(ltrim($payload), '<');

            return ['extension' => $isXml ? 'xml' : 'json', 'content' => $payload];
        }

        return [
            'extension' => 'json',
            'content' => (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * @param  array<string, true>  $usedNames
     */
    private function uniqueBaseName(Invoice $invoice, array &$usedNames): string
    {
        $base = $invoice->invoice_number
            ? preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice->invoice_number)
            : null;
        $base = trim((string) $base, '.-') ?: 'invoice-'.$invoice->id;

        if (isset($usedNames[Str::lower($base)])) {
            $base .= '-'.$invoice->id;
        }

        $usedNames[Str::lower($base)] = true;

        return $base;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return list<int>
     */
    private function distinctIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @return array<string, mixed>
     */
    private function result(Invoice $invoice, string $outcome, ?string $reason = null): array
    {
        return array_filter([
            'id' => (int) $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'outcome' => $outcome,
            'reason' => $reason,
        ], fn ($value) => $value !== null);
    }
}
