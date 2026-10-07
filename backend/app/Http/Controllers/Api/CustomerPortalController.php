<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPortalLink;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Services\CustomerPortalService;
use App\Services\CustomerStatementService;
use App\Services\InvoiceService;
use App\Services\SalesSettings;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\SalesOrder;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #337 — public customer portal (token-authenticated, no login).
 *
 * The token is the only credential. Tenant resolution happens in
 * InitializeTenantForPublicAccess through the central #466 registry; this
 * controller then re-resolves the token inside the tenant schema, enforces
 * expiry/revocation, and scopes every document to the link's ONE customer
 * (and legal entity). Out-of-scope or unknown documents answer 404.
 *
 * Reuses the existing building blocks only: PdfGenerationService (serves the
 * #613 issued copy for posted invoices), InvoiceService::createPaymentLink
 * (idempotent — returns the live existing link), CustomerStatementService
 * (canonical statement payload) and the existing acceptance page for open
 * quotations (`/quotation-accept/{token}`).
 */
class CustomerPortalController extends Controller
{
    public function __construct(private CustomerPortalService $portal) {}

    public function show(string $token): JsonResponse
    {
        [$link, $customer, $error] = $this->guard($token);
        if ($error !== null) {
            return $error;
        }

        $invoices = $this->portal->invoicesQuery($link)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->format('Y-m-d'),
                'due_date' => $invoice->due_date?->format('Y-m-d'),
                'status' => $invoice->status,
                'currency_code' => $invoice->currency_code,
                'total' => (float) $invoice->total,
                'paid_amount' => (float) $invoice->paid_amount,
                'balance' => (float) $invoice->balance,
                'can_pay' => bccomp((string) $invoice->balance, '0.00', 2) > 0,
            ])->values()->all();

        $quotations = $this->portal->quotationsQuery($link)
            ->orderByDesc('quotation_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Quotation $quotation) => [
                'id' => $quotation->id,
                'quotation_number' => $quotation->quotation_number,
                'quotation_date' => $quotation->quotation_date?->format('Y-m-d'),
                'valid_until' => $quotation->valid_until?->format('Y-m-d'),
                'status' => $quotation->status,
                'currency_code' => $quotation->currency_code ?? null,
                'total' => (float) $quotation->total,
                'accepted_at' => $quotation->accepted_at?->toIso8601String(),
                // An open quotation links to the EXISTING acceptance page,
                // only while its own acceptance token is live.
                'acceptance_token' => $this->liveAcceptanceToken($quotation),
            ])->values()->all();

        $orders = $this->portal->salesOrdersQuery($link)
            ->with('lines')
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (SalesOrder $order) => [
                'id' => $order->id,
                'sales_order_number' => $order->sales_order_number,
                'order_date' => $order->order_date?->format('Y-m-d'),
                'commitment_date' => $order->commitment_date?->format('Y-m-d'),
                'status' => $order->status,
                'currency_code' => $order->currency_code,
                'total' => (float) $order->total,
                'customer_reference' => $order->customer_reference,
                // #371 canonical invoice progress (never re-derived here).
                'invoice_status' => $order->invoiceStatus(),
                'delivery_status' => $this->deliveryStatus($order),
            ])->values()->all();

        return response()->json([
            'customer' => [
                'name' => $customer->name,
                'code' => $customer->code,
            ],
            'company' => \App\Support\TenantBranding::forCurrentTenant(),
            'expires_at' => $link->expires_at?->toIso8601String(),
            'invoices' => $invoices,
            'quotations' => $quotations,
            'orders' => $orders,
        ]);
    }

    public function statement(string $token, CustomerStatementService $statements): JsonResponse
    {
        [$link, $customer, $error] = $this->guard($token);
        if ($error !== null) {
            return $error;
        }

        if ($this->portal->statementWouldMixEntities($link)) {
            return response()->json([
                'error' => 'The statement is not available on this portal link.',
                'code' => 'STATEMENT_UNAVAILABLE',
            ], 409);
        }

        $payload = $statements->buildAsOfPayload($customer, now());

        return response()->json([
            'as_of' => now()->toDateString(),
            'currency' => $payload['currency'] ?? null,
            'closing_balance' => $payload['closing_balance'] ?? 0,
            'aging' => $payload['aging'] ?? [],
            'transactions' => $payload['transactions'] ?? [],
        ]);
    }

    public function invoicePdf(string $token, string $invoice, PdfGenerationServiceContract $pdf): Response
    {
        [$link, , $error] = $this->guard($token);
        if ($error !== null) {
            return $error;
        }

        $model = $this->scopedInvoice($link, $invoice);
        if (! $model) {
            return $this->documentNotFound();
        }

        return $this->renderPdf($pdf, 'invoice', (string) $model->id, 'invoice-'.$model->invoice_number.'.pdf');
    }

    public function quotationPdf(string $token, string $quotation, PdfGenerationServiceContract $pdf): Response
    {
        [$link, , $error] = $this->guard($token);
        if ($error !== null) {
            return $error;
        }

        $model = ctype_digit($quotation)
            ? $this->portal->quotationsQuery($link)->whereKey((int) $quotation)->first()
            : null;
        if (! $model) {
            return $this->documentNotFound();
        }

        return $this->renderPdf($pdf, 'quotation', (string) $model->id, 'quotation-'.$model->quotation_number.'.pdf');
    }

    /**
     * The customer-facing document of an order is its order confirmation
     * (#405 template), rendered through the same PdfGenerationService path
     * the staff `/sales/orders/{id}/confirmation/pdf` route uses.
     */
    public function salesOrderPdf(string $token, string $order, PdfGenerationServiceContract $pdf): Response
    {
        [$link, , $error] = $this->guard($token);
        if ($error !== null) {
            return $error;
        }

        $model = ctype_digit($order)
            ? $this->portal->salesOrdersQuery($link)->whereKey((int) $order)->first()
            : null;
        if (! $model) {
            return $this->documentNotFound();
        }

        return $this->renderPdf($pdf, 'sales_order_confirmation', (string) $model->id, 'order-'.$model->sales_order_number.'.pdf');
    }

    public function pay(string $token, string $invoice, InvoiceService $invoices, SalesSettings $settings): JsonResponse
    {
        [$link, , $error] = $this->guard($token);
        if ($error !== null) {
            return $error;
        }

        $model = $this->scopedInvoice($link, $invoice);
        if (! $model) {
            return $this->documentNotFound();
        }

        if (bccomp((string) $model->balance, '0.00', 2) <= 0) {
            return response()->json([
                'error' => 'This invoice has no balance to pay.',
                'code' => 'NOTHING_TO_PAY',
            ], 422);
        }

        try {
            // Idempotent: a live existing link is returned as-is; otherwise
            // the same path the staff "payment link" action uses mints one
            // for the outstanding balance.
            $result = $invoices->createPaymentLink(
                $model,
                (float) $model->balance,
                now()->addDays($settings->paymentLinkExpiryDays()),
            );
        } catch (\Throwable $e) {
            Log::error('CustomerPortal: payment link creation failed', [
                'invoice_id' => $model->id,
                'portal_link_id' => $link->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Online payment is not available for this invoice right now.',
                'code' => 'PAYMENT_LINK_UNAVAILABLE',
            ], 502);
        }

        if (empty($result['url'])) {
            return response()->json([
                'error' => 'Online payment is not available for this invoice right now.',
                'code' => 'PAYMENT_LINK_UNAVAILABLE',
            ], 502);
        }

        return response()->json([
            'url' => $result['url'],
            'expires_at' => $result['expires_at'] ?? null,
            'amount' => (float) ($result['amount'] ?? $model->balance),
            'currency' => $model->currency_code,
        ]);
    }

    /**
     * Resolve + validate the token. Unknown or revoked → 404 (a revoked link
     * is indistinguishable from one that never existed); expired → 410, the
     * same codes the existing public acceptance routes use.
     *
     * @return array{0: ?CustomerPortalLink, 1: ?Customer, 2: ?JsonResponse}
     */
    private function guard(string $token): array
    {
        $link = $this->portal->findByToken($token);

        if (! $link || $link->isRevoked()) {
            return [null, null, response()->json([
                'error' => 'Invalid or expired portal link.',
                'code' => 'INVALID_TOKEN',
            ], 404)];
        }

        if ($link->isExpired()) {
            return [null, null, response()->json([
                'error' => 'This portal link has expired. Please ask for a new one.',
                'code' => 'EXPIRED',
            ], 410)];
        }

        $customer = Customer::query()->find($link->customer_id);

        if (! $customer) {
            return [null, null, response()->json([
                'error' => 'Invalid or expired portal link.',
                'code' => 'INVALID_TOKEN',
            ], 404)];
        }

        $link->forceFill(['last_accessed_at' => now()])->saveQuietly();

        return [$link, $customer, null];
    }

    private function scopedInvoice(CustomerPortalLink $link, string $invoice): ?Invoice
    {
        if (! ctype_digit($invoice)) {
            return null;
        }

        return $this->portal->invoicesQuery($link)->whereKey((int) $invoice)->first();
    }

    /**
     * Delivery progress from the order lines' delivered quantities, using the
     * order's own #408 isFullyDelivered rule. Null when nothing is deliverable
     * (service/note-only orders).
     */
    private function deliveryStatus(SalesOrder $order): ?string
    {
        $deliverable = $order->lines->filter(
            fn ($line) => ! in_array($line->line_type ?? 'product', ['note', 'section'], true)
                && (float) $line->quantity - (float) $line->quantity_cancelled > 0.0001
        );

        if ($deliverable->isEmpty()) {
            return null;
        }

        if ($order->isFullyDelivered()) {
            return 'delivered';
        }

        return $deliverable->contains(fn ($line) => (float) $line->quantity_delivered > 0.0001)
            ? 'partially_delivered'
            : 'not_delivered';
    }

    private function liveAcceptanceToken(Quotation $quotation): ?string
    {
        if ((string) $quotation->status !== 'sent'
            || ! $quotation->acceptance_token
            || $quotation->accepted_at !== null
            || ($quotation->acceptance_expires_at && $quotation->acceptance_expires_at->isPast())) {
            return null;
        }

        return (string) $quotation->acceptance_token;
    }

    private function renderPdf(PdfGenerationServiceContract $pdf, string $type, string $id, string $filename): Response
    {
        try {
            // No template/signature/variant options: a posted invoice is
            // served from its immutable issued copy (#613) when one exists.
            $result = $pdf->render($type, $id, ['format' => 'pdf']);
        } catch (ModelNotFoundException) {
            return $this->documentNotFound();
        } catch (\RuntimeException $e) {
            Log::error('CustomerPortal: PDF render failed', [
                'document_type' => $type,
                'document_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'The document could not be generated.',
                'code' => 'PDF_UNAVAILABLE',
            ], 422);
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'document.pdf';

        return response($result['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$safeName.'"',
            'Content-Length' => (string) strlen($result['bytes']),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function documentNotFound(): JsonResponse
    {
        return response()->json([
            'error' => 'Document not found.',
            'code' => 'DOCUMENT_NOT_FOUND',
        ], 404);
    }
}
