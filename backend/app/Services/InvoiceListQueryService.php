<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Sales\Services\SalesActionQueueService;

/** Builds invoice-list filters and wide search clauses independently of HTTP response handling. */
class InvoiceListQueryService
{
    /** @return Builder<Invoice> */
    public function build(Request $request): Builder
    {
        $query = Invoice::query()->orderBy('invoice_date', 'desc')->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->whereIn('status', explode(',', $request->status));
        }
        if ($request->filled('lifecycle_status')) {
            $query->whereIn('lifecycle_status', explode(',', $request->lifecycle_status));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // The Sales Order smart button includes directly linked and bridge-linked invoices.
        if ($request->filled('sales_order_id')) {
            $salesOrderId = (int) $request->sales_order_id;
            $query->where(function ($query) use ($salesOrderId) {
                $query->where('invoices.sales_order_id', $salesOrderId)
                    ->orWhereIn('invoices.id', function ($links) use ($salesOrderId) {
                        $links->select('invoice_id')
                            ->from('sales_invoice_links')
                            ->where('sales_order_id', $salesOrderId);
                    });
            });
        }

        if ($request->filled('currency_code')) {
            $query->where('currency_code', strtoupper((string) $request->currency_code));
        }
        if ($request->input('sort_by') === 'currency') {
            $query->reorder()->orderBy('currency_code')->orderBy('invoice_date', 'desc')->orderBy('id', 'desc');
        }
        if ($request->filled('document_ids')) {
            $query->whereIn('id', $request->input('document_ids', []));
        }
        if ($request->input('progress_filter') === 'invoiced_not_delivered') {
            app(SalesDocumentProgress::class)->scopeInvoicedNotDelivered($query);
        }
        app(SalesActionQueueService::class)->applyListFilter($request, $query, 'invoice');

        if ($request->filled('start_date')) {
            $query->whereDate('invoice_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('invoice_date', '<=', $request->end_date);
        }
        if ($request->filled('search')) {
            $this->applyWideSearch($query, trim((string) $request->search));
        }
        if ($request->filled('tag')) {
            $taggedIds = app(TagService::class)->subjectIdsForTagSlugs([$request->tag], 'invoice');
            $query->whereIn('id', $taggedIds !== [] ? $taggedIds : [-1]);
        }
        if ($request->filled('einvoice_status')) {
            \App\Support\EInvoiceListStatus::applyFilter($query, (string) $request->einvoice_status, 'invoice', 'invoices.id');
        }

        return $query;
    }

    /**
     * Search invoice identity, linked contacts/customer, line contents, upstream documents, and tags.
     *
     * @param  Builder<Invoice>  $query
     */
    private function applyWideSearch(Builder $query, string $search): void
    {
        $like = "%{$search}%";
        $contactMatches = function ($contact) use ($like) {
            $contact->where('display_name', 'ilike', $like)
                ->orWhere('first_name', 'ilike', $like)
                ->orWhere('last_name', 'ilike', $like)
                ->orWhere('company_name', 'ilike', $like)
                ->orWhere('email', 'ilike', $like)
                ->orWhere('phone', 'ilike', $like)
                ->orWhere('mobile', 'ilike', $like);
        };

        $query->where(function ($query) use ($search, $like, $contactMatches) {
            $query->where('invoice_number', 'ilike', $like)
                ->orWhere('customer_reference', 'ilike', $like)
                ->orWhere('internal_reference', 'ilike', $like)
                ->orWhere('po_number', 'ilike', $like)
                ->orWhere('case_number', 'ilike', $like)
                ->orWhere('pr_no', 'ilike', $like)
                ->orWhere('contract_no', 'ilike', $like)
                ->orWhere('plant_code', 'ilike', $like)
                ->orWhere('company_code', 'ilike', $like)
                ->orWhere('attention_to', 'ilike', $like)
                ->orWhere('contact_email', 'ilike', $like)
                ->orWhere('contact_phone', 'ilike', $like)
                ->orWhere('billing_address_snapshot', 'ilike', $like)
                ->orWhere('shipping_address_snapshot', 'ilike', $like)
                ->orWhere('delivery_address_snapshot', 'ilike', $like)
                ->orWhereHas('doctor', $contactMatches)
                ->orWhereHas('requestor', $contactMatches)
                ->orWhereHas('buyer', $contactMatches)
                ->orWhereHas('billingContact', $contactMatches)
                ->orWhereHas('shippingContact', $contactMatches)
                ->orWhereHas('customer', fn ($customer) => app(CustomerWideSearchService::class)->apply($customer, $search))
                ->orWhereHas('lines', function ($line) use ($like) {
                    $line->where('description', 'ilike', $like)
                        ->orWhere('model_number', 'ilike', $like)
                        ->orWhere('product_notes', 'ilike', $like)
                        ->orWhere('lot_number', 'ilike', $like)
                        ->orWhere('serial_number', 'ilike', $like);
                })
                ->orWhereHas('salesOrder', fn ($order) => $order->where('sales_order_number', 'ilike', $like))
                ->orWhereHas('quotation', fn ($quotation) => $quotation->where('quotation_number', 'ilike', $like));

            $taggedIds = app(TagService::class)->subjectIdsForTagNameSearch($search, 'invoice');
            if ($taggedIds !== []) {
                $query->orWhereIn('id', $taggedIds);
            }
        });
    }
}
