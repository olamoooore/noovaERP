<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Platform\ResolutionResult;
use Modules\TemplateBuilder\Services\DataSource\DataSourceRegistry;
use Modules\TemplateBuilder\Services\DataSource\DocumentResolutionContext;
use Modules\TemplateBuilder\Services\DocumentRenderAuthorizer;
use Modules\TemplateBuilder\Services\DocumentTemplateService;
use Symfony\Component\HttpFoundation\Response;

/**
 * DocumentRenderController — the consumer-facing render endpoints.
 *
 * These live at the resource path so the existing detail-page buttons work:
 *   GET /invoices/{id}/pdf          (fixes the previously-broken button)
 *   GET /purchase-orders/{id}/pdf
 *   GET /payments/{id}/pdf          (+ /html, issue #500)
 *
 * Issue #615 (TP5) adds the sales order family and credit note:
 *   GET /sales/orders/{id}/pdf (+ /html)
 *   GET /sales/orders/{id}/confirmation/pdf (+ /html)
 *   GET /sales/orders/{id}/proforma/pdf (+ /html)
 *   GET /credit-notes/{id}/pdf (+ /html)
 *
 * Issue #611: every render authorizes against the SOURCE document's own
 * permission and legal entity before generating output. document.view alone
 * (the coarse route gate) is not enough — a cashier/viewer holding it must
 * not be able to fetch another entity's invoice/statement/receipt by id.
 */
class DocumentRenderController extends Controller
{
    public function __construct(
        private readonly PdfGenerationServiceContract $pdf,
        private readonly DataSourceRegistry $dataSources,
        private readonly DocumentTemplateService $templates,
        private readonly DocumentRenderAuthorizer $authorizer,
    ) {}

    public function invoicePdf(Request $request, string $id): Response
    {
        return $this->renderDocument('invoice', $id, $request, 'invoice-'.$id.'.pdf');
    }

    public function invoiceHtml(Request $request, string $id): Response
    {
        return $this->renderDocument('invoice', $id, $request, null, 'html');
    }

    public function purchaseOrderPdf(Request $request, string $id): Response
    {
        return $this->renderDocument('purchase_order', $id, $request, 'po-'.$id.'.pdf');
    }

    public function purchaseOrderHtml(Request $request, string $id): Response
    {
        return $this->renderDocument('purchase_order', $id, $request, null, 'html');
    }

    public function paymentReceiptPdf(Request $request, string $id): Response
    {
        return $this->renderDocument('receipt', $id, $request, 'receipt-'.$id.'.pdf');
    }

    /** Issue #500: on-screen preview of the payment receipt. */
    public function paymentReceiptHtml(Request $request, string $id): Response
    {
        return $this->renderDocument('receipt', $id, $request, null, 'html');
    }

    public function deliveryOrderPdf(Request $request, string $id): Response
    {
        return $this->renderDocument('delivery_order', $id, $request, 'do-'.$id.'.pdf');
    }

    public function deliveryOrderTemplates(): JsonResponse
    {
        $templates = DocumentTemplate::query()
            ->forDocumentType('delivery_order')
            ->where('status', DocumentTemplate::STATUS_PUBLISHED)
            ->orderByDesc('is_system_default')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_system_default', 'published_version']);

        return response()->json(['data' => $templates]);
    }

    public function invoiceTemplates(): JsonResponse
    {
        $templates = DocumentTemplate::query()->forDocumentType('invoice')
            ->where('status', DocumentTemplate::STATUS_PUBLISHED)
            ->orderByDesc('is_system_default')->orderBy('name')
            ->get(['id', 'name', 'code', 'is_system_default', 'published_version']);

        return response()->json(['data' => $templates]);
    }

    public function quotationPdf(Request $request, string $id): Response
    {
        return $this->renderDocument('quotation', $id, $request, 'quotation-'.$id.'.pdf');
    }

    public function customerStatementPdf(Request $request, string $id): Response
    {
        return $this->renderDocument('customer_statement', $id, $request, 'statement-'.$id.'.pdf');
    }

    // ─── Issue #615 (TP5): sales order family + credit note ─────────────────

    public function salesOrderPdf(Request $request, string $id): Response
    {
        return $this->renderDocument('sales_order', $id, $request, 'so-'.$id.'.pdf');
    }

    public function salesOrderHtml(Request $request, string $id): Response
    {
        return $this->renderDocument('sales_order', $id, $request, null, 'html');
    }

    public function orderConfirmationPdf(Request $request, string $id): Response
    {
        return $this->renderDocument('sales_order_confirmation', $id, $request, 'so-confirmation-'.$id.'.pdf');
    }

    public function orderConfirmationHtml(Request $request, string $id): Response
    {
        return $this->renderDocument('sales_order_confirmation', $id, $request, null, 'html');
    }

    public function proformaInvoicePdf(Request $request, string $id): Response
    {
        return $this->proformaDisabled('sales_proforma_invoice')
            ?? $this->renderDocument('sales_proforma_invoice', $id, $request, 'proforma-'.$id.'.pdf');
    }

    public function proformaInvoiceHtml(Request $request, string $id): Response
    {
        return $this->proformaDisabled('sales_proforma_invoice')
            ?? $this->renderDocument('sales_proforma_invoice', $id, $request, null, 'html');
    }

    /**
     * Issue #551: Sales Settings → Sales Orders "Pro-forma invoice". When the
     * tenant switched it off, no pro-forma invoice is rendered.
     */
    private function proformaDisabled(string $documentType): ?JsonResponse
    {
        if ($documentType !== 'sales_proforma_invoice' || app(\App\Services\SalesSettings::class)->proformaInvoiceEnabled()) {
            return null;
        }

        return response()->json([
            'message' => 'Pro-forma invoices are turned off in Sales Settings.',
            'code' => 'PROFORMA_DISABLED',
        ], 403);
    }

    public function creditNotePdf(Request $request, string $id): Response
    {
        return $this->renderDocument('credit_note', $id, $request, 'credit-note-'.$id.'.pdf');
    }

    public function creditNoteHtml(Request $request, string $id): Response
    {
        return $this->renderDocument('credit_note', $id, $request, null, 'html');
    }

    /**
     * Issue #618 (TP8): read-only resolution preview for a document.
     *
     * The shared print/send menu shows which template the deterministic
     * resolver picks for THIS document ("Using: <template> (<rule>)") before
     * anything is rendered. It reuses the #612 document resolution context so
     * the label matches what Preview/Download/Print will actually use.
     *
     * Documents that do not exist yet (a customer statement is generated from
     * a customer + period) pass explicit resolution dimensions instead of a
     * document_id; the context normaliser drops unknown channel values.
     */
    public function documentResolution(Request $request, string $documentType): JsonResponse
    {
        if (! $this->dataSources->has($documentType)) {
            return response()->json(['message' => "No render path for document type '{$documentType}'."], 404);
        }

        $documentId = $request->query('document_id');

        if (is_string($documentId) && $documentId !== '') {
            // Same authorization as a render: the SOURCE document's own
            // permission and legal-entity scope.
            $denied = $this->authorizeRender($documentType, $documentId, $request);
            if ($denied !== null) {
                return $denied;
            }

            $context = $this->dataSources->resolutionContextFor($documentType, $documentId);
        } else {
            $context = DocumentResolutionContext::build([
                'entity_id' => $request->query('entity_id'),
                'branch_id' => $request->query('branch_id'),
                'language' => $request->query('language'),
                'currency' => $request->query('currency'),
                'country' => $request->query('country'),
                'jurisdiction' => $request->query('jurisdiction'),
                'customer_group' => $request->query('customer_group'),
                'channel' => $request->query('channel'),
            ]);
        }

        $result = $this->templates->resolveWithExplanation($documentType, $context);

        return response()->json([
            'data' => [
                'outcome' => $result->outcome->value,
                'template_id' => $result->template?->id,
                'template_name' => $result->template?->name,
                'rule' => $result->assignment ? [
                    'scope_type' => $result->assignment->scope_type,
                    'scope_value' => $result->assignment->scope_value,
                ] : null,
                'rule_label' => $this->resolutionRuleLabel($result),
                'explanation' => $result->explanation,
            ],
        ]);
    }

    /**
     * Issue #613 (TP3): explicit re-issue with the CURRENT published template.
     *
     * This is the only path that may change an issued document's bytes. It
     * creates a new immutable version (checksummed) and keeps the original
     * (superseded), so the copy the customer received is never destroyed.
     */
    public function regenerate(Request $request, string $documentType, string $id): JsonResponse
    {
        if (! $this->dataSources->has($documentType)) {
            return response()->json(['message' => "No render path for document type '{$documentType}'."], 404);
        }

        if ($disabled = $this->proformaDisabled($documentType)) {
            return $disabled;
        }

        $denied = $this->authorizeRender($documentType, $id, $request);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $document = $this->pdf->regenerate($documentType, $id);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Document not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Document regenerated with the current template; the previous issued copy is retained.',
            'id' => $document->id,
            'document_type' => $document->document_type,
            'document_id' => $document->document_id,
            'template_id' => $document->template_id,
            'template_version' => $document->template_version,
            'checksum' => $document->checksum,
            'size_bytes' => $document->size_bytes,
            'generated_at' => $document->generated_at?->toIso8601String(),
        ], 201);
    }

    private function renderDocument(string $type, string $id, Request $request, ?string $filename, string $format = 'pdf'): Response
    {
        // An invoice endpoint can render the quotation projection; the
        // effective type drives both authorization and rendering.
        if ($request->has('as') && $type === 'invoice' && $request->input('as') === 'quotation') {
            $type = 'quotation';
        }

        $denied = $this->authorizeRender($type, $id, $request);
        if ($denied !== null) {
            return $denied;
        }

        $options = ['format' => $format];
        if ($request->has('template')) {
            $options['template_id'] = $request->input('template');
        }

        try {
            $result = $this->pdf->render($type, $id, $options);
        } catch (ModelNotFoundException $e) {
            return response(['message' => 'Document not found.'], 404);
        } catch (\RuntimeException $e) {
            return response(['message' => $e->getMessage()], 422);
        }

        if ($format === 'html') {
            return response($result['html'], 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $disposition = $filename ? 'inline; filename="'.$filename.'"' : 'inline';

        return response($result['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
            'Content-Length' => strlen($result['bytes']),
        ]);
    }

    /**
     * Enforce the source document's own permission and legal-entity scope
     * before any output is generated (issue #611). The shared authorizer is
     * also used by the template editor's real-document preview (#620).
     */
    private function authorizeRender(string $type, string $id, Request $request): ?Response
    {
        return $this->authorizer->deny($type, $id, $request);
    }

    /**
     * Human label for the assignment that won resolution — "entity rule",
     * "customer rule", … — or "system default" when no assignment matched and
     * the fallback applied. Null when nothing resolved.
     */
    private function resolutionRuleLabel(ResolutionResult $result): ?string
    {
        if ($result->template === null) {
            return null;
        }

        if ($result->assignment === null) {
            return 'system default';
        }

        $scope = (string) ($result->assignment->scope_type ?: 'tenant');

        return match ($scope) {
            // The resolver's customer_group dimension is the customer rule.
            'customer_group' => 'customer rule',
            default => $scope.' rule',
        };
    }
}
