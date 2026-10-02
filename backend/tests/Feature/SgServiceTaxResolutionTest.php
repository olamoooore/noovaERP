<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TaxCountryPackService;
use App\Services\TaxDeterminationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #738 T5: SG has one GST type — determination must not filter SG rules by
 * the MY-specific taxType 'service', which made SG service products resolve
 * to no tax (goods on the same invoice were charged 9%).
 */
class SgServiceTaxResolutionTest extends TenantTestCase
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
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_sg_pack_charges_gst_on_a_service_product(): void
    {
        app(TaxCountryPackService::class)->activate('SG');

        $customer = Customer::create(['name' => 'SG Cust', 'email' => 'sg-svc@x.test', 'is_active' => true]);
        $product = Product::create([
            'name' => 'Consulting', 'sku' => 'SG-SVC-'.Str::random(6), 'is_active' => true,
            'type' => 'service', 'track_inventory' => false,
        ]);

        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'SG-SVC-'.Str::random(8),
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'status' => 'draft',
            'subtotal' => 100.0,
            'tax_amount' => 0.0,
            'total' => 100.0,
            'created_by' => $this->user->id,
        ]);

        // A fresh line with no booked rate: the engine is authoritative (a
        // non-null booked rate would be treated as a document override).
        $line = new InvoiceLine([
            'product_id' => $product->id,
            'description' => 'Consulting',
            'quantity' => 1,
            'unit_price' => 100.0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);
        $line->setRelation('product', $product);
        $invoice->setRelation('lines', collect([$line]));

        $determination = app(TaxDeterminationService::class)->determineForInvoice($invoice);

        $taxTotal = round(array_sum(array_map(
            fn (array $line) => (float) ($line['tax_amount'] ?? 0),
            $determination['tax_lines'] ?? [],
        )), 2);

        $this->assertSame(9.0, $taxTotal, 'An SG service product must resolve to GST 9%.');
    }
}
