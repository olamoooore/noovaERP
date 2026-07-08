<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;

/**
 * DocumentRenderController — the consumer-facing render endpoints.
 *
 * These live at the resource path so the existing detail-page buttons work:
 *   GET /invoices/{id}/pdf          (fixes the previously-broken button)
 *   GET /purchase-orders/{id}/pdf
 *   GET /payments/{id}/pdf
 */
class DocumentRenderController extends Controller
{
    public function __construct(private readonly PdfGenerationServiceContract $pdf) {}

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

    private function renderDocument(string $type, string $id, Request $request, ?string $filename, string $format = 'pdf'): Response
    {
        $options = ['format' => $format];
        if ($request->has('template')) {
            $options['template_id'] = $request->input('template');
        }
        if ($request->has('as') && $type === 'invoice' && $request->input('as') === 'quotation') {
            $type = 'quotation';
        }

        try {
            $result = $this->pdf->render($type, $id, $options);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
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
}
