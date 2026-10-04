<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tax;
use App\Models\TaxDeterminationResult;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleService;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceMapper;
use Modules\Contact\src\Models\ContactTaxProfile;
use Modules\Contact\src\Models\CoreContact;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #309 (X9): a customer tax exemption recorded on the contact tax profile must
 * reach the invoice — 0 tax, exempt treatment in the persisted determination,
 * and the exemption reason + amount on the mapped MyInvois "E" line.
 */
class Issue309CustomerTaxExemptionTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@x9.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $gateMock = \Mockery::mock(FeatureGateService::class);
        $gateMock->shouldReceive('check')->andReturn([
            'key' => 'accounting', 'is_entitled' => true, 'source' => 'tier_included', 'name' => 'Accounting',
        ]);
        $gateMock->shouldReceive('all')->andReturn([['key' => 'accounting', 'source' => 'tier_included', 'name' => 'Accounting']]);
        $gateMock->shouldReceive('invalidate')->andReturnNull();
        app()->instance(FeatureGateService::class, $gateMock);

        $roleMock = \Mockery::mock(TenantRoleService::class);
        $roleMock->shouldReceive('hasPermission')->andReturn(true);
        $roleMock->shouldReceive('hasAnyPermission')->andReturn(true);
        app()->instance(TenantRoleService::class, $roleMock);
    }

    /**
     * A customer whose contact tax profile is exempt, valid for the given
     * window (null = no expiry).
     */
    private function exemptCustomer(string $reason, ?string $expiry): Customer
    {
        $contact = CoreContact::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => tenant('id'),
            'contact_type' => 'organisation',
            'display_name' => 'Exempt Buyer Sdn Bhd',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'name' => 'Exempt Buyer Sdn Bhd',
            'core_contact_uuid' => $contact->id,
            'is_active' => true,
        ]);

        ContactTaxProfile::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => tenant('id'),
            'contact_id' => $contact->id,
            'tax_profile_type' => 'customer_tax',
            'is_tax_exempt' => true,
            'exemption_reason' => $reason,
            'exemption_expiry' => $expiry,
        ]);

        return $customer->refresh();
    }

    private function taxableProduct(): Product
    {
        $rule = Tax::create([
            'name' => 'MY SST 9', 'code' => 'MY-SST-9-'.Str::upper(Str::random(4)), 'type' => 'sales',
            'regime' => 'SST', 'jurisdiction' => 'MY', 'scope' => 'both',
            'treatment' => 'standard_rated', 'rate' => 9, 'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Taxable service', 'sku' => 'X9-'.Str::upper(Str::random(6)),
            'is_active' => true, 'track_inventory' => false, 'tax_mode' => 'default',
        ]);
        $product->forceFill(['tax_id' => $rule->id, 'type' => 'service'])->save();

        return $product->refresh();
    }

    public function test_exempt_customer_invoice_is_zero_tax_and_maps_with_exemption_reason(): void
    {
        $reason = 'Approved sales-tax exemption certificate X9-EX-001';
        $customer = $this->exemptCustomer($reason, now()->addYear()->toDateString());
        $product = $this->taxableProduct();

        $response = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Exempt consultancy',
                'quantity' => 1,
                'unit_price' => 100.00,
                'tax_rate' => 9.00,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated();

        $invoice = Invoice::with('lines')->find($response->json('id'));
        $line = $invoice->lines->first();

        $this->assertSame(0.00, round((float) $invoice->tax_amount, 2),
            'An invoice for an exempt customer must book 0 tax.');
        $this->assertSame(100.00, round((float) $invoice->total, 2),
            'The exempt invoice total must equal the untaxed subtotal.');
        $this->assertSame(0.0, (float) $line->tax_rate,
            'The exempt customer line must be re-rated to 0.');
        $this->assertSame(0.0, (float) $line->tax_amount,
            'The exempt customer line must book 0 tax.');
        $this->assertSame($reason, $line->tax_exemption_reason,
            'The exemption reason from the customer tax profile must be recorded on the line.');

        // Posting must persist an EXEMPT treatment (not zero-rated).
        $this->actingAs($this->user)->postJson("/api/v1/invoices/{$invoice->id}/send")->assertOk();

        $determination = TaxDeterminationResult::where('tenant_id', tenant('id'))
            ->where('source_record_type', 'invoice')
            ->where('source_record_id', $invoice->id)
            ->current()
            ->orderByDesc('result_version')
            ->first();

        $this->assertNotNull($determination, 'Posting must persist a tax determination.');
        $this->assertSame('exempt', $determination->tax_lines[0]['treatment'],
            'The exempt customer line must determine as exempt, not zero-rated.');

        $payload = app(MyInvoiceMapper::class)->mapInvoice($invoice->fresh(['lines', 'customer']));
        $mappedLine = $payload['line_items'][0];

        $this->assertSame('E', $mappedLine['tax_type_code'],
            'An exempt line must be mapped as LHDN tax type E.');
        $this->assertSame($reason, $mappedLine['tax_exemption_reason'],
            'The mapped MyInvois E line must carry the exemption reason.');
        $this->assertEqualsWithDelta(100.0, (float) $mappedLine['tax_exemption_amount'], 0.001,
            'The mapped MyInvois E line must carry the exempted amount.');
        $this->assertEqualsWithDelta(0.0, (float) $mappedLine['sst_amount'], 0.001,
            'The mapped MyInvois E line must carry 0 tax.');
    }

    public function test_expired_exemption_is_not_applied(): void
    {
        $customer = $this->exemptCustomer('Expired exemption certificate', now()->subDay()->toDateString());
        $product = $this->taxableProduct();

        $response = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Taxable consultancy',
                'quantity' => 1,
                'unit_price' => 100.00,
                'tax_rate' => 9.00,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated();

        $invoice = Invoice::with('lines')->find($response->json('id'));

        $this->assertSame(9.00, round((float) $invoice->tax_amount, 2),
            'An exemption whose validity window has passed must not remove tax.');
        $this->assertSame(109.00, round((float) $invoice->total, 2));
        $this->assertNull($invoice->lines->first()->tax_exemption_reason,
            'An expired exemption must not record an exemption reason.');
    }
}
