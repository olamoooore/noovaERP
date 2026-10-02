<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TaxDeterminationResult;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #740 T7: the tax regime comes from the DOCUMENT's legal entity's active
 * tax registration — jurisdiction, regime and cycle — never from a
 * fallback country or the buyer's location. An entity without a
 * registration charges no tax (with a warning). Pack activation never
 * changes the tenant's home jurisdiction; registrations do.
 */
class EntityRegistrationRegimeTest extends TenantTestCase
{
    private User $user;

    private int $group;

    private int $entityMy;

    private int $entitySg;

    private int $entityNoReg;

    private Customer $customerMy;

    private Customer $customerSg;

    private Customer $customerUs;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@regime.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->user);

        // Country packs live (rates effective for the 2025-08 document dates).
        foreach (['SG', 'MY'] as $code) {
            $this->postJson("/api/v1/accounting/tax-engine/packs/{$code}/activate")->assertOk();
        }

        $this->group = DB::table('entity_groups')->insertGetId([
            'name' => 'Regime Group', 'code' => 'RGM', 'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01', 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityMy = $this->insertEntity('ENT-MY', 'MY', 'MYR');
        $this->entitySg = $this->insertEntity('ENT-SG', 'SG', 'SGD');
        $this->entityNoReg = $this->insertEntity('ENT-NR', 'MY', 'MYR');

        foreach ([$this->entityMy, $this->entitySg, $this->entityNoReg] as $i => $entityId) {
            DB::table('user_entity_access')->insert([
                'user_id' => $this->user->id,
                'legal_entity_id' => $entityId,
                'role' => 'editor',
                'is_default' => $i === 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->customerMy = $this->customerWithBilling('MY');
        $this->customerSg = $this->customerWithBilling('SG');
        $this->customerUs = $this->customerWithBilling('US');

        $this->product = Product::factory()->create([
            'name' => 'Regime Probe Service',
            'type' => 'service',
            'track_inventory' => false,
            'price' => 1000,
        ]);
    }

    private function insertEntity(string $code, string $country, string $currency): int
    {
        return DB::table('legal_entities')->insertGetId([
            'group_id' => $this->group, 'name' => "Entity {$code}", 'code' => $code,
            'country_code' => $country, 'base_currency' => $currency,
            'fiscal_year_start' => '01-01', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function customerWithBilling(string $country): Customer
    {
        $customer = Customer::factory()->create();
        DB::table('customer_addresses')->insert([
            'customer_id' => $customer->id,
            'type' => 'billing',
            'country' => $country,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $customer;
    }

    private function registerEntity(int $entityId, string $jurisdiction, string $regime, string $number): void
    {
        $this->postJson('/api/v1/accounting/tax-registrations', [
            'legal_entity_id' => $entityId,
            'jurisdiction' => $jurisdiction,
            'regime' => $regime,
            'registration_number' => $number,
            'effective_from' => '2025-01-01',
        ])->assertCreated();
    }

    private function invoiceFor(int $entityId, int $customerId, ?float $bookedRate = null): int
    {
        $line = [
            'product_id' => $this->product->id,
            'description' => 'Regime probe',
            'quantity' => 1,
            'unit_price' => 1000,
            'discount_amount' => 0,
        ];
        if ($bookedRate !== null) {
            $line['tax_rate'] = $bookedRate;
        }

        $create = $this->withHeaders(['X-Entity-Id' => (string) $entityId])
            ->postJson('/api/v1/invoices', [
                'customer_id' => $customerId,
                'invoice_date' => '2025-08-15',
                'due_date' => '2025-09-15',
                'lines' => [$line],
            ]);
        $create->assertCreated();

        $invoiceId = (int) $create->json('id');

        $this->withHeaders(['X-Entity-Id' => (string) $entityId])
            ->postJson("/api/v1/invoices/{$invoiceId}/send")
            ->assertOk();

        return $invoiceId;
    }

    private function determination(int $invoiceId): TaxDeterminationResult
    {
        return TaxDeterminationResult::query()
            ->where('source_record_type', 'invoice')
            ->where('source_record_id', $invoiceId)
            ->orderByDesc('result_version')
            ->firstOrFail();
    }

    public function test_each_entities_registration_defines_its_own_regime(): void
    {
        $this->registerEntity($this->entityMy, 'my', 'sst', 'W-MY-000001');
        $this->registerEntity($this->entitySg, 'sg', 'gst', '2001M-000002');

        // Deliberately CROSSED buyers: the buyer's country must not decide
        // the seller's regime — the seller's registration does.
        $myInvoice = $this->invoiceFor($this->entityMy, $this->customerSg->id, 8.0);
        $sgInvoice = $this->invoiceFor($this->entitySg, $this->customerMy->id, 9.0);

        $myDet = $this->determination($myInvoice);
        $sgDet = $this->determination($sgInvoice);

        $this->assertSame('MY', $myDet->jurisdiction, 'MY entity must resolve the MY registration regime despite an SG buyer.');
        $this->assertSame('SG', $sgDet->jurisdiction, 'SG entity must resolve the SG registration regime despite an MY buyer.');
        $this->assertNotSame($myDet->jurisdiction, $sgDet->jurisdiction);

        $this->assertSame(80.0, (float) Invoice::findOrFail($myInvoice)->tax_amount);
        $this->assertSame(90.0, (float) Invoice::findOrFail($sgInvoice)->tax_amount);

        // The posted trail carries the registration jurisdiction, so the
        // returns attribute the rows to the right country.
        foreach ([$myInvoice => 'MY', $sgInvoice => 'SG'] as $invoiceId => $jurisdiction) {
            $jeId = (int) Invoice::findOrFail($invoiceId)->journal_entry_id;
            $trail = DB::table('tax_transaction_details')->where('journal_entry_id', $jeId)->pluck('jurisdiction');
            $this->assertNotEmpty($trail->all());
            $this->assertSame([$jurisdiction], $trail->unique()->values()->all());
        }
    }

    public function test_entity_without_registration_charges_no_tax_with_warning(): void
    {
        // Packs are active in this tenant, but this entity has no registration.
        $invoiceId = $this->invoiceFor($this->entityNoReg, $this->customerMy->id);

        $this->assertSame(0.0, (float) Invoice::findOrFail($invoiceId)->tax_amount, 'An unregistered entity must never charge tax.');

        $det = $this->determination($invoiceId);
        $this->assertSame('NO_REGISTRATION', $det->result_status, 'The determination must carry the clear no-registration warning status.');
        $this->assertSame('', (string) $det->jurisdiction);

        $jeId = (int) Invoice::findOrFail($invoiceId)->journal_entry_id;
        $this->assertSame(0, DB::table('tax_transaction_details')->where('journal_entry_id', $jeId)->count());
    }

    public function test_export_buyer_keeps_the_registration_regime_but_charges_no_local_tax(): void
    {
        $this->registerEntity($this->entityMy, 'my', 'sst', 'W-MY-000009');

        $invoiceId = $this->invoiceFor($this->entityMy, $this->customerUs->id);

        $det = $this->determination($invoiceId);
        $this->assertSame('MY', $det->jurisdiction, 'The regime identity stays the seller registration even for an export buyer.');
        $this->assertSame('DETERMINED', $det->result_status);

        $lines = (array) $det->tax_lines;
        $this->assertSame('zero_rated', $lines[0]['treatment'] ?? null, 'Buyer location decides the treatment: export is zero-rated.');
        $this->assertSame(0.0, (float) Invoice::findOrFail($invoiceId)->tax_amount);
    }

    public function test_pack_activation_never_sets_home_jurisdiction_but_registrations_do(): void
    {
        // Packs were activated in setUp — that must not have touched home.
        $this->assertNull(Setting::getValue('tax', [])['jurisdiction'] ?? null, 'Pack activation must not set the home jurisdiction.');

        $this->registerEntity($this->entityMy, 'my', 'sst', 'W-MY-000011');
        $this->assertSame('MY', Setting::getValue('tax', [])['jurisdiction'] ?? null, 'Registrations define the home jurisdiction.');

        // Re-activating another pack must not flip it either.
        $this->postJson('/api/v1/accounting/tax-engine/packs/SG/activate')->assertOk();
        $this->assertSame('MY', Setting::getValue('tax', [])['jurisdiction'] ?? null);
    }
}
