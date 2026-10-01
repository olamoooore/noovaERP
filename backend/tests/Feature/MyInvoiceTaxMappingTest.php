<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TaxDeterminationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceMapper;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #274 G-37: MyInvois per-line tax typing is sourced from the persisted
 * determination — standard 01, zero-rated 06, exempt E (with exemption
 * amount), distinguishing zero-rated from exempt.
 */
class MyInvoiceTaxMappingTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_mapped_lines_carry_determination_tax_types_and_exemption_fields(): void
    {
        $customer = Customer::create(['name' => 'G37 Customer Sdn Bhd', 'is_active' => true]);
        $product = Product::create([
            'name' => 'G37 Product', 'sku' => 'G37-'.Str::upper(Str::random(6)),
            'is_active' => true, 'track_inventory' => false,
        ]);

        $invoice = Invoice::create([
            'customer_id' => $customer->id, 'invoice_number' => 'G37-'.Str::upper(Str::random(6)),
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft', 'lifecycle_status' => 'draft',
            'subtotal' => 200, 'tax_amount' => 9, 'discount_amount' => 0, 'total' => 209,
            'paid_amount' => 0, 'balance' => 209, 'currency_code' => 'MYR', 'created_by' => $this->user->id,
        ]);

        $lineData = [
            ['base' => 100.0, 'rate' => 9.0, 'tax' => 9.0, 'code' => 'MY-SST-9', 'treatment' => 'standard_rated'],
            ['base' => 50.0, 'rate' => 0.0, 'tax' => 0.0, 'code' => 'MY-SST-0', 'treatment' => 'zero_rated'],
            ['base' => 50.0, 'rate' => 0.0, 'tax' => 0.0, 'code' => 'MY-EXEMPT', 'treatment' => 'exempt'],
        ];

        $determinationInput = [];
        foreach ($lineData as $i => $data) {
            $line = InvoiceLine::create([
                'invoice_id' => $invoice->id, 'product_id' => $product->id,
                'description' => 'G37 line '.($i + 1), 'quantity' => 1, 'unit_price' => $data['base'],
                'tax_rate' => $data['rate'], 'tax_inclusive' => false, 'discount_amount' => 0,
                'line_total' => $data['base'] + $data['tax'], 'tax_amount' => $data['tax'],
            ]);

            $determinationInput[] = [
                'source_line_id' => $line->id,
                'base' => $data['base'],
                'rate' => $data['rate'],
                'tax' => $data['tax'],
                'tax_code_override' => $data['code'],
                'treatment_override' => $data['treatment'],
            ];
        }

        // ONE canonical determination covering all lines (each call persists a
        // new version — per-line calls would supersede each other).
        app(TaxDeterminationService::class)->determineForLineSet(
            'invoice', (int) $invoice->id, now()->toDateString(),
            $determinationInput,
            jurisdiction: 'MY',
        );

        $mapped = app(MyInvoiceMapper::class)->mapInvoice($invoice->load('lines', 'customer'));
        $items = $mapped['line_items'];

        $this->assertSame('01', $items[0]['tax_type_code'], 'Standard-rated sales tax line must be 01.');
        $this->assertSame('06', $items[1]['tax_type_code'], 'Zero-rated line must be 06 (Not Applicable), distinct from exempt.');
        $this->assertSame('E', $items[2]['tax_type_code'], 'Exempt line must be E.');

        $this->assertNull($items[0]['tax_exemption_amount']);
        $this->assertEqualsWithDelta(50.0, (float) $items[1]['tax_exemption_amount'], 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $items[2]['tax_exemption_amount'], 0.001);

        $this->assertEqualsWithDelta(9.0, (float) $items[0]['sst_amount'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $items[1]['sst_amount'], 0.001);
    }
}
