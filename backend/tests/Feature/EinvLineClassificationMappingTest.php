<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceMapper;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #665 EI3: a line's LHDN classification and UN/ECE unit come from the product
 * (or the pack's declared default), not a silent hardcoded 001 / C62.
 */
class EinvLineClassificationMappingTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@einv665.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function invoiceWithProduct(array $productAttributes): Invoice
    {
        $product = Product::create(array_merge([
            'name' => 'Boxed item', 'sku' => 'BX-'.Str::random(6), 'is_active' => true,
            'track_inventory' => false, 'unit_price' => 50,
        ], $productAttributes));

        $customer = Customer::factory()->create();
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'CLS-'.Str::random(8),
            'invoice_date' => '2025-08-15',
            'due_date' => '2025-09-15',
            'status' => 'draft',
            'subtotal' => 100.0, 'tax_amount' => 0.0, 'total' => 100.0,
            'currency_code' => 'MYR', 'base_currency_code' => 'MYR',
            'created_by' => $this->user->id,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id, 'product_id' => $product->id,
            'description' => 'Boxed item', 'quantity' => 2, 'unit_price' => 50,
            'tax_rate' => 0, 'discount_amount' => 0,
        ]);

        return $invoice->load('lines.product');
    }

    public function test_a_product_classification_and_unit_are_used_per_line(): void
    {
        $invoice = $this->invoiceWithProduct([
            'einvoice_classification_code' => '022',
            'einvoice_unit_code' => 'BX',
        ]);

        $payload = app(MyInvoiceMapper::class)->mapInvoice($invoice);

        $line = $payload['line_items'][0];
        $this->assertSame('022', $line['classification_code']);
        $this->assertSame('BX', $line['unit_code']);
    }

    public function test_an_unmapped_product_uses_the_declared_pack_default(): void
    {
        $invoice = $this->invoiceWithProduct([]);

        $payload = app(MyInvoiceMapper::class)->mapInvoice($invoice);

        $this->assertSame('001', $payload['line_items'][0]['classification_code']);
        $this->assertSame('C62', $payload['line_items'][0]['unit_code']);
    }

    public function test_the_backfill_command_sets_codes_without_overwriting_existing_ones(): void
    {
        $unmapped = Product::create(['name' => 'Unmapped', 'sku' => 'U-'.Str::random(6), 'is_active' => true, 'track_inventory' => false]);
        $partial = Product::create([
            'name' => 'Partial', 'sku' => 'P-'.Str::random(6), 'is_active' => true, 'track_inventory' => false,
            'einvoice_classification_code' => '999',
        ]);

        $this->artisan('products:backfill-einvoice-codes --classification=022 --unit=BX')->assertExitCode(0);

        $this->assertSame('022', $unmapped->fresh()->einvoice_classification_code);
        $this->assertSame('BX', $unmapped->fresh()->einvoice_unit_code);
        $this->assertSame('999', $partial->fresh()->einvoice_classification_code, 'A hand-set code must not be overwritten by the sweep.');
        $this->assertSame('BX', $partial->fresh()->einvoice_unit_code);
    }

    public function test_a_missing_classification_is_a_validation_error(): void
    {
        config(['tax_packs.MY.einvoice.default_classification' => null]);
        $invoice = $this->invoiceWithProduct([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/EINVOICE_CLASSIFICATION_REQUIRED/');

        app(MyInvoiceMapper::class)->mapInvoice($invoice);
    }
}
