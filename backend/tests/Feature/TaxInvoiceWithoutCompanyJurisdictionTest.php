<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\TaxDeterminationResult;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Hostile review of the tax decision paper (2026-10-06): an invoice posted
 * with NO company context — no sales order entity and no X-Entity-Id, e.g. a
 * tenant without legal entities — skips the #740 registration path, and
 * TaxDeterminationService::resolveJurisdiction() takes the BUYER's billing
 * country as the jurisdiction (and so the regime) of the whole invoice.
 *
 * Rule asserted (#307 acceptance criteria: "Jurisdiction and regime come from
 * the selling legal entity"; here there is none, so the seller is the tenant,
 * whose home jurisdiction is MY): the invoice's tax is determined and
 * recorded under MY whatever the buyer's country. Whether a foreign buyer is
 * an export is NOT asserted (#307 treatment rule).
 *
 * Every step goes through the real HTTP routes (setup from
 * TaxBuyerCountryTextTest, without the legal entity).
 */
class TaxInvoiceWithoutCompanyJurisdictionTest extends TenantTestCase
{
    private User $user;

    private Product $product;

    private float $ruleRate;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@no-company.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->user);

        $this->postJson('/api/v1/accounting/tax-engine/packs/MY/activate')->assertOk();
        // The seller (the tenant itself — no legal entities) is in MY.
        Setting::setValue('tax', array_merge((array) Setting::getValue('tax', []), ['jurisdiction' => 'MY']));

        // Tax from the activated MY pack's own rule, valid on the invoice
        // date, booked at its own rate — no rate is defined by this test.
        $rule = Tax::where('code', 'MY-SVC-8')->orderBy('id')->firstOrFail();
        $this->ruleRate = (float) $rule->rate;
        $this->product = Product::factory()->create([
            'name' => 'No Company Probe Service',
            'type' => 'service',
            'track_inventory' => false,
            'price' => 1000,
            'tax_id' => $rule->id,
        ]);
    }

    public function test_control_invoice_is_determined_under_the_seller(): void
    {
        $this->assertSame(0, DB::table('legal_entities')->count(), 'Precondition: no company context exists.');

        $control = $this->determination($this->invoiceFor($this->customerWithBillingCountry('MY')));

        $this->assertSame('MY', strtoupper((string) $control->jurisdiction));
    }

    public function test_buyer_country_typed_as_a_name_does_not_become_the_invoice_jurisdiction(): void
    {
        $named = $this->determination($this->invoiceFor($this->customerWithBillingCountry('Malaysia')));

        $this->assertSame(
            'MY',
            strtoupper((string) $named->jurisdiction),
            'An invoice to a buyer whose country is typed "Malaysia" must be determined under the seller (MY), not "MALAYSIA".'
        );
    }

    public function test_foreign_buyer_does_not_become_the_invoice_jurisdiction(): void
    {
        $foreign = $this->determination($this->invoiceFor($this->customerWithBillingCountry('SG')));

        $this->assertSame(
            'MY',
            strtoupper((string) $foreign->jurisdiction),
            'An invoice from the MY seller to an SG buyer must be determined under MY (the buyer decides treatment only, #307).'
        );
    }

    private function customerWithBillingCountry(string $country): Customer
    {
        $customer = Customer::factory()->create(['billing_address' => null, 'shipping_address' => null]);

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
        $create = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => '2025-08-15',
            'due_date' => '2025-09-15',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'No company probe',
                'quantity' => 1,
                'unit_price' => 1000,
                'discount_amount' => 0,
                'tax_rate' => $this->ruleRate,
            ]],
        ]);
        $create->assertCreated();
        $invoiceId = (int) $create->json('id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/send")->assertOk();

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
