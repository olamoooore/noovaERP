<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Invoice discount → tax ordering (Oct-2026 invoice review, F3).
 *
 * The create-invoice screen now mirrors Invoice::calculateTotals() for its
 * live totals: the document discount reduces the taxable base BEFORE tax.
 * These tests pin the server contract using the review's worked example
 * (100 + 10% tax with a 10 discount must save 99.00, not 100.00).
 */
class InvoiceDiscountTaxOrderingTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContext((string) Str::uuid());
    }

    private function makeInvoice(float $documentDiscount): Invoice
    {
        $customer = Customer::factory()->create(['legal_entity_id' => null]);

        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'discount_amount' => $documentDiscount,
            'created_by' => User::factory()->create()->id,
        ]);

        $line = InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'line_type' => 'product',
            'description' => 'Widget',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 10,
            'tax_inclusive' => false,
            'discount_amount' => 0,
            'line_total' => 0,
            'tax_amount' => 0,
        ]);
        $line->calculateTotals();

        $invoice->setRelation('lines', InvoiceLine::where('invoice_id', $invoice->id)->get());
        $invoice->calculateTotals();

        return $invoice->fresh();
    }

    /** @test */
    public function document_discount_reduces_tax_before_the_total(): void
    {
        $invoice = $this->makeInvoice(10);

        $this->assertSame(100.0, (float) $invoice->subtotal);
        $this->assertSame(9.0, (float) $invoice->tax_amount, 'Tax must be scaled by (subtotal - discount) / subtotal');
        $this->assertSame(99.0, (float) $invoice->total, 'Total must be net - discount + scaled tax');
    }

    /** @test */
    public function without_a_document_discount_tax_is_unchanged(): void
    {
        $invoice = $this->makeInvoice(0);

        $this->assertSame(100.0, (float) $invoice->subtotal);
        $this->assertSame(10.0, (float) $invoice->tax_amount);
        $this->assertSame(110.0, (float) $invoice->total);
    }
}
