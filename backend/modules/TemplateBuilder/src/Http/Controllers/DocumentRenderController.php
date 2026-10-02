<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Services\DataSource\DataSourceRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * DocumentRenderController — the consumer-facing render endpoints.
 *
 * These live at the resource path so the existing detail-page buttons work:
 *   GET /invoices/{id}/pdf          (fixes the previously-broken button)
 *   GET /purchase-orders/{id}/pdf
 *   GET /payments/{id}/pdf
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
     * before any output is generated.
     *
     * A non-existent id answers exactly like an id outside the caller's
     * entity (same 403 + same body) so the endpoint is not an existence
     * oracle.
     */
    private function authorizeRender(string $type, string $id, Request $request): ?Response
    {
        $source = $this->dataSources->make($type);
        $permission = $source->permission();

        if (! \App\Http\Middleware\RequirePermission::allows($request, $permission)) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
                'required_permission' => $permission,
            ], 403);
        }

        try {
            $source->load($id);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Document not found.'], 403);
        }

        $documentEntityId = $source->legalEntityId();
        $requestEntityId = $request->attributes->get('legal_entity_id');

        // Entity scoping mirrors the platform's EnsureEntityAccess convention:
        // when the request has an entity context and the document's entity is
        // resolvable, they must match. Documents with no reachable entity link
        // (or requests without an entity context — the single-entity tenant
        // shortcut) are not blocked here.
        if ($documentEntityId !== null
            && $requestEntityId !== null
            && (int) $documentEntityId !== (int) $requestEntityId) {
            return response()->json(['message' => 'Document not found.'], 403);
        }

        return null;
    }
}
