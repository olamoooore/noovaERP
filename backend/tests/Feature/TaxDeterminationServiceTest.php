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
        $user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
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

    /**
     * MRD gap review G-05 (repro R04) is the authoritative contract now:
     * IDENTICAL inputs are idempotent (same version); a changed input bumps
     * the version — and both versions share one correlation chain.
     */
    public function test_identical_inputs_are_idempotent_and_changed_inputs_bump_version(): void
    {
        $invoice = $this->makeInvoiceWithLine();
        $service = app(TaxDeterminationService::class);

        $first = $service->determineForInvoice($invoice);
        $this->assertSame(1, $first['result_version']);

        $same = $service->determineForInvoice($invoice);
        $this->assertSame(1, $same['result_version'], 'G-05: identical recomputation must not burn a new version');
        $this->assertSame($first['id'], $same['id']);
        $this->assertSame(1, TaxDeterminationResult::where('source_record_id', $invoice->id)
            ->where('source_record_type', 'invoice')->where('tenant_id', tenant('id'))->count(),
            'The idempotent call wrote no extra row');

        $invoice->lines()->update(['tax_amount' => 12.00, 'line_total' => 112.00]);
        $invoice->update(['tax_amount' => 12.00, 'total' => 112.00]);

        $changed = $service->determineForInvoice($invoice->fresh(['lines.product', 'customer']));
        $this->assertSame(2, $changed['result_version'], 'Changed inputs must produce version 2');
        $this->assertSame($first['id'], $changed['id'], 'Versions share the correlation id');
        $this->assertSame(2, TaxDeterminationResult::where('source_record_id', $invoice->id)
            ->where('source_record_type', 'invoice')->where('tenant_id', tenant('id'))->count(),
            'Exactly two versions persisted');
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
