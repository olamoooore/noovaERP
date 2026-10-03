<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting\Compliance;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\AccountingCountry\Singapore\Services\SFRSInvoiceMapper;
use Tests\TestCase;

/**
 * #305 (X5), SG Peppol side: the document discount rides as a document-level
 * allowance, so the header tax-exclusive amount and the tax subtotals must be
 * the POST-discount figures and the tax total must equal the booked invoice
 * tax — otherwise the SG invoice contradicts the invoice/ledger.
 */
class SfrsInvoiceDiscountReconciliationTest extends TestCase
{
    public function test_document_discount_reconciles_header_and_tax_subtotals(): void
    {
        $invoice = new Invoice;
        $invoice->setRawAttributes([
            'id' => 77,
            'invoice_number' => 'SG-X5-0001',
            'currency_code' => 'SGD',
            'subtotal' => 100.00,
            'tax_amount' => 9.00,
            'discount_amount' => 10.00,
            'total' => 99.00,
            'total_amount' => 99.00,
            'prepaid_amount' => 0,
        ], true);
        $invoice->issued_date = Carbon::parse('2026-04-06');
        $invoice->due_date = Carbon::parse('2026-05-06');

        $party = fn (string $name, string $uen) => (object) [
            'name' => $name,
            'registration_number' => $uen,
            'address_line_1' => '1 Example Road',
            'city' => 'Singapore',
            'postal_code' => '018989',
            'contact_person' => null,
            'phone' => null,
            'email' => null,
        ];
        $invoice->supplier = $party('Supplier Pte Ltd', '202600001A');
        $invoice->customer = $party('Customer Pte Ltd', '202600002B');

        $invoice->setRelation('lineItems', new Collection([
            tap(new InvoiceLine, fn ($l) => $l->setRawAttributes([
                'id' => 78, 'description' => 'Consulting', 'quantity' => 1,
                'unit_price' => 100.00, 'tax_rate' => 10, 'tax_amount' => 10.00,
                'discount_amount' => 0, 'total_amount' => 100.00, 'line_total' => 100.00,
            ], true)),
        ]));

        $payload = (new SFRSInvoiceMapper)->mapInvoice($invoice);
        $totals = $payload['totals'];

        // Peppol: LineExtensionTotal(100) - allowance(10) = TaxExclusive(90).
        $this->assertSame(100.00, (float) $totals['line_extension_total_amount']['amount']);
        $this->assertSame(10.00, (float) $payload['allowances_charges'][0]['amount']['amount']);
        $this->assertSame(90.00, (float) $totals['legal_monetary_total']['tax_exclusive_amount']['amount']);
        $this->assertSame((float) $invoice->tax_amount, (float) $totals['tax_total']['amount']);

        $subtotals = $totals['tax_total']['tax_subtotal'];
        $this->assertSame(90.00, round(array_sum(array_map(fn ($row) => (float) $row['taxable_amount']['amount'], $subtotals)), 2));
        $this->assertSame((float) $invoice->tax_amount, round(array_sum(array_map(fn ($row) => (float) $row['tax_amount']['amount'], $subtotals)), 2));
    }
}
