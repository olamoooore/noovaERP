<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tax;
use App\Models\TaxDeterminationResult;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * ADR-003B W6 step 2 (tax) — found while reviewing how tax reads addresses.
 *
 * TaxDeterminationService decides EXPORT treatment when the buyer's
 * jurisdiction differs from the seller's registration. The buyer's
 * jurisdiction comes from the default billing address's `country`, passed
 * through TaxPackRegistry::jurisdictionForCountry(), which returns the raw
 * text for anything that is not a configured code. The customer address
 * endpoints accept any country text (max 100 characters), so a Malaysian
 * buyer saved with country "Malaysia" gets jurisdiction "MALAYSIA" ≠ "MY"
 * and is zero-rated as an export by a MY-registered seller.
 *
 * Rule asserted (the minimum, independent of the fix the tax agent chooses):
 * country text that is not a recognised country code must not, by itself,
 * make a buyer an export buyer. A control buyer saved with "MY" shows the
 * local outcome. No tax rate is asserted; rates come from the country packs.
 *
 * Every step goes through the real HTTP routes (setup copied from
 * EntityRegistrationRegimeTest).
 */
class TaxBuyerCountryTextTest extends TenantTestCase
{
    private User $user;

    private int $entityMy;

    private Product $product;

    private float $ruleRate;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@country-text.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->user);

        $this->postJson('/api/v1/accounting/tax-engine/packs/MY/activate')->assertOk();

        $group = DB::table('entity_groups')->insertGetId([
            'name' => 'Country Text Group', 'code' => 'CTX', 'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01', 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityMy = DB::table('legal_entities')->insertGetId([
            'group_id' => $group, 'name' => 'Entity CTX-MY', 'code' => 'CTX-MY',
            'country_code' => 'MY', 'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_entity_access')->insert([
            'user_id' => $this->user->id, 'legal_entity_id' => $this->entityMy, 'role' => 'editor',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/accounting/tax-registrations', [
            'legal_entity_id' => $this->entityMy,
            'jurisdiction' => 'my',
            'regime' => 'sst',
            'registration_number' => 'W-CTX-000001',
            'effective_from' => '2025-01-01',
        ])->assertCreated();

        // The product's tax comes from the activated MY pack's own service
        // tax rule (config/tax_packs.php) — no rate is defined by this test.
        $rule = Tax::where('code', 'MY-SVC-8')->orderBy('id')->firstOrFail();
        $this->ruleRate = (float) $rule->rate;
        $this->product = Product::factory()->create([
            'name' => 'Country Text Probe Service',
            'type' => 'service',
            'track_inventory' => false,
            'price' => 1000,
            'tax_id' => $rule->id,
        ]);
    }

    public function test_buyer_country_written_as_a_name_is_not_treated_as_an_export(): void
    {
        $control = $this->customerWithBillingCountry('MY');
        $named = $this->customerWithBillingCountry('Malaysia');

        $controlDet = $this->determination($this->invoiceFor($control));
        $namedDet = $this->determination($this->invoiceFor($named));

        $controlLine = ((array) $controlDet->tax_lines)[0] ?? [];
        $namedLine = ((array) $namedDet->tax_lines)[0] ?? [];

        // Control: a local buyer's line takes the pack rule's identity.
        $this->assertSame('MY-SVC-8', $controlLine['tax_code'] ?? null);
        $this->assertSame('standard_rated', $controlLine['treatment'] ?? null);

        $this->assertSame(
            [$controlLine['tax_code'] ?? null, $controlLine['treatment'] ?? null],
            [$namedLine['tax_code'] ?? null, $namedLine['treatment'] ?? null],
            'A buyer whose billing country is written "Malaysia" must get the same tax code and treatment as the "MY" buyer, not the export path.'
        );
    }

    private function customerWithBillingCountry(string $country): Customer
    {
        $customer = Customer::factory()->create(['billing_address' => null, 'shipping_address' => null]);

        // The real address endpoint (Addresses card / API) accepts free text.
        $this->postJson("/api/v1/customers/{$customer->id}/addresses", [
            'type' => 'billing',
            'street' => '1 Jalan Probe',
            'city' => 'Kuala Lumpur',
            'country' => $country,
            'is_default' => true,
        ])->assertCreated();

        return $customer;
    }

    private function invoiceFor(Customer $customer): int
    {
        $create = $this->withHeaders(['X-Entity-Id' => (string) $this->entityMy])
            ->postJson('/api/v1/invoices', [
                'customer_id' => $customer->id,
                'invoice_date' => '2025-08-15',
                'due_date' => '2025-09-15',
                'lines' => [[
                    'product_id' => $this->product->id,
                    'description' => 'Country text probe',
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount_amount' => 0,
                    // Booked at the pack rule's own rate (read above).
                    'tax_rate' => $this->ruleRate,
                ]],
            ]);
        $create->assertCreated();
        $invoiceId = (int) $create->json('id');

        $this->withHeaders(['X-Entity-Id' => (string) $this->entityMy])
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
}
