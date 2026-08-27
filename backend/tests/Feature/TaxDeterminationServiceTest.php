<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\TaxDeterminationResult;
use App\Models\User;
use App\Services\TaxDeterminationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class TaxDeterminationServiceTest extends TenantTestCase
{
    private string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        // Create and authenticate a user so Product audit logging works
        $user = User::factory()->create();
        Auth::login($user);
    }

    private function makeInvoiceWithLine(array $lineOverrides = []): Invoice
    {
        $customer = Customer::create([
            'name' => 'Test Customer',
            'email' => 'test@example.com',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Test Product',
            'sku' => 'TP-'.uniqid(),
            'is_active' => true,
        ]);

        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'created_by' => Auth::id(),
        ]);

        InvoiceLine::create(array_merge([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => 'Test line',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 9.0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
            'line_total' => 100,
            'tax_amount' => 9.00,
        ], $lineOverrides));

        return $invoice->fresh(['lines.product', 'customer']);
    }

    public function test_creates_tax_determination_result_record(): void
    {
        $invoice = $this->makeInvoiceWithLine();
        $service = app(TaxDeterminationService::class);

        $result = $service->determineForInvoice($invoice);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('jurisdiction', $result);
        $this->assertArrayHasKey('tax_regime', $result);
        $this->assertArrayHasKey('tax_lines', $result);

        $persisted = TaxDeterminationResult::where('source_record_type', 'invoice')
            ->where('source_record_id', $invoice->id)
            ->first();

        $this->assertNotNull($persisted);
        $this->assertSame('DETERMINED', $persisted->result_status);
        $this->assertSame(1, $persisted->result_version);
    }

    public function test_resolves_jurisdiction_from_customer_billing_address(): void
    {
        $customer = Customer::create([
            'name' => 'MY Customer',
            'email' => 'my@example.com',
            'is_active' => true,
        ]);

        CustomerAddress::create([
            'customer_id' => $customer->id,
            'type' => 'billing',
            'is_default' => true,
            'country' => 'MY',
            'street' => '123 Jalan',
            'city' => 'KL',
        ]);

        $product = Product::create([
            'name' => 'Product',
            'sku' => 'P-'.uniqid(),
            'is_active' => true,
        ]);

        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'created_by' => Auth::id(),
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => 'Line',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 6.0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
            'line_total' => 100,
            'tax_amount' => 6.00,
        ]);

        $result = app(TaxDeterminationService::class)->determineForInvoice(
            $invoice->fresh(['lines.product', 'customer']),
        );

        $this->assertSame('MY', $result['jurisdiction']);
        $this->assertSame('SST', $result['tax_regime']);
    }

    public function test_zero_rated_treatment_when_tax_rate_is_zero(): void
    {
        $invoice = $this->makeInvoiceWithLine([
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 100,
        ]);

        $result = app(TaxDeterminationService::class)->determineForInvoice($invoice);

        $this->assertSame('zero_rated', $result['treatment']);
        $this->assertSame(0.0, $result['tax_rate']);
    }

    public function test_exempt_treatment_with_override(): void
    {
        $invoice = $this->makeInvoiceWithLine([
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 100,
        ]);

        $result = app(TaxDeterminationService::class)->determineForInvoice($invoice, [
            'treatment' => 'exempt',
        ]);

        $this->assertSame('exempt', $result['treatment']);
    }

    public function test_version_bumps_on_subsequent_determination_for_same_invoice(): void
    {
        $invoice = $this->makeInvoiceWithLine();
        $service = app(TaxDeterminationService::class);

        $first = $service->determineForInvoice($invoice);
        $this->assertSame(1, $first['result_version']);

        $second = $service->determineForInvoice($invoice);
        $this->assertSame(2, $second['result_version']);

        // Same correlation_id across versions
        $this->assertSame($first['id'], $second['id']);
    }

    public function test_persisted_determination_has_correct_tax_lines(): void
    {
        $invoice = $this->makeInvoiceWithLine([
            'quantity' => 2,
            'unit_price' => 50,
            'tax_rate' => 9.0,
            'tax_inclusive' => false,
            'line_total' => 100,
            'tax_amount' => 9.00,
        ]);

        $result = app(TaxDeterminationService::class)->determineForInvoice($invoice);

        $this->assertCount(1, $result['tax_lines']);
        $this->assertSame('standard_rated', $result['tax_lines'][0]['treatment']);
        $this->assertSame(9.0, $result['tax_lines'][0]['tax_rate']);
        $this->assertSame(100.0, $result['tax_lines'][0]['taxable_base']);
        $this->assertSame(9.0, $result['tax_lines'][0]['tax_amount']);
    }

    public function test_array_format_matches_posting_engine_expectation(): void
    {
        $invoice = $this->makeInvoiceWithLine();
        $result = app(TaxDeterminationService::class)->determineForInvoice($invoice);

        // Verify all fields PostingEngine::writeTaxTransactionDetails expects
        $this->assertIsString($result['id']);
        $this->assertIsInt($result['result_version']);
        $this->assertIsString($result['jurisdiction']);
        $this->assertIsString($result['tax_regime']);
        $this->assertSame('DETERMINED', $result['result_status']);
        $this->assertIsArray($result['tax_lines']);

        foreach ($result['tax_lines'] as $line) {
            $this->assertArrayHasKey('tax_code', $line);
            $this->assertArrayHasKey('taxable_base', $line);
            $this->assertArrayHasKey('tax_rate', $line);
            $this->assertArrayHasKey('treatment', $line);
        }
    }

    public function test_synthetic_tax_code_when_product_has_no_tax_code(): void
    {
        $invoice = $this->makeInvoiceWithLine();
        $result = app(TaxDeterminationService::class)->determineForInvoice($invoice);

        // Default jurisdiction is SG, regime is GST, rate is 9% → SG-GST-9
        $this->assertSame('SG-GST-9', $result['tax_lines'][0]['tax_code']);
    }
}
