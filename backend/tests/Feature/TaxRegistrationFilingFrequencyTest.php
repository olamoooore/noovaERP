<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TaxRegistration;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #748 TU2: a tax registration carries the filing frequency the entity files
 * at (SG monthly/quarterly, MY two-monthly/monthly), validated against the
 * jurisdiction's pack — not a free-text field. #750 derives the filing
 * periods from it and the cycle start month.
 */
class TaxRegistrationFilingFrequencyTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@freq.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_registration_stores_the_filing_frequency_it_was_given(): void
    {
        $response = $this->postJson('/api/v1/accounting/tax-registrations', [
            'jurisdiction' => 'SG',
            'regime' => 'GST',
            'registration_number' => 'M88888888X',
            'effective_from' => '2025-01-01',
            'filing_frequency' => 'quarterly',
        ])->assertCreated();

        $this->assertSame('quarterly', $response->json('data.filing_frequency'));
        $this->assertSame('quarterly', TaxRegistration::findOrFail($response->json('data.id'))->filing_frequency);
    }

    public function test_a_frequency_the_jurisdiction_does_not_support_is_rejected(): void
    {
        // SG files monthly or quarterly — never two-monthly (that is MY's shape).
        $this->postJson('/api/v1/accounting/tax-registrations', [
            'jurisdiction' => 'SG',
            'registration_number' => 'M88888888Y',
            'effective_from' => '2025-01-01',
            'filing_frequency' => 'bimonthly',
        ])->assertStatus(422);
    }

    public function test_my_bimonthly_registration_keeps_its_cycle_start_month(): void
    {
        $response = $this->postJson('/api/v1/accounting/tax-registrations', [
            'jurisdiction' => 'MY',
            'regime' => 'SST',
            'registration_number' => 'W10-2026-000001',
            'effective_from' => '2025-01-01',
            'filing_frequency' => 'bimonthly',
            'filing_start_month' => 4,
        ])->assertCreated();

        $registration = TaxRegistration::findOrFail($response->json('data.id'));
        $this->assertSame('bimonthly', $registration->filing_frequency);
        $this->assertSame(4, (int) $registration->filing_start_month);
    }

    public function test_frequency_defaults_to_the_jurisdictions_primary_pack_shape(): void
    {
        // MY's primary shape is two-monthly; SG's is monthly.
        $my = $this->postJson('/api/v1/accounting/tax-registrations', [
            'jurisdiction' => 'MY', 'registration_number' => 'W10-2026-000002', 'effective_from' => '2025-01-01',
        ])->assertCreated();
        $this->assertSame('bimonthly', $my->json('data.filing_frequency'));

        $sg = $this->postJson('/api/v1/accounting/tax-registrations', [
            'jurisdiction' => 'SG', 'registration_number' => 'M88888888Z', 'effective_from' => '2025-01-01',
        ])->assertCreated();
        $this->assertSame('monthly', $sg->json('data.filing_frequency'));
    }
}
