<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\TaxRegistration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OnboardingStateMachine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #748 TU2: the onboarding wizard's tax-registration step writes a
 * tax_registrations row (through the shared TaxRegistrationService, so it
 * obeys the pack-driven frequency rule and #740's home-jurisdiction rule).
 * "Not registered" writes nothing.
 */
class OnboardingTaxRegistrationStepTest extends TenantTestCase
{
    private string $tenantId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@onb.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sessionAtTaxStep(): array
    {
        $machine = app(OnboardingStateMachine::class);
        $session = $machine->createSession($this->tenantId, 'guided', (string) $this->user->id);
        $id = (int) $session->id;

        for ($i = 0; $i < 10; $i++) {
            $current = DB::table('onboarding_sessions')->where('id', $id)->value('current_step');
            if ($current === 'tax_registration') {
                break;
            }
            $machine->advance($id, ['completed' => $current]);
        }

        $this->assertSame('tax_registration', DB::table('onboarding_sessions')->where('id', $id)->value('current_step'));

        return [$machine, $id];
    }

    public function test_the_step_writes_a_registration_through_the_shared_service(): void
    {
        [$machine, $id] = $this->sessionAtTaxStep();

        $machine->advance($id, [
            'registered' => true,
            'jurisdiction' => 'MY',
            'regime' => 'SST',
            'registration_number' => 'W10-2026-000123',
            'effective_from' => '2025-01-01',
            'filing_frequency' => 'bimonthly',
            'filing_start_month' => 4,
        ]);

        $registration = TaxRegistration::query()->where('registration_number', 'W10-2026-000123')->firstOrFail();
        $this->assertSame('MY', $registration->jurisdiction);
        $this->assertSame('bimonthly', $registration->filing_frequency);
        $this->assertSame(4, (int) $registration->filing_start_month);
        // #740: the registration defines the home jurisdiction.
        $this->assertSame('MY', Setting::getValue('tax', [])['jurisdiction'] ?? null);
    }

    public function test_not_registered_writes_nothing(): void
    {
        [$machine, $id] = $this->sessionAtTaxStep();

        $machine->advance($id, ['registered' => false, 'jurisdiction' => 'SG']);

        $this->assertSame(0, TaxRegistration::query()->count(), 'A not-registered entity must not create a registration.');
        // The step completes regardless, so the wizard can move on to the gauge.
        $this->assertSame('coa_import', DB::table('onboarding_sessions')->where('id', $id)->value('current_step'));
    }

    public function test_the_step_rejects_a_frequency_the_pack_does_not_support(): void
    {
        [$machine, $id] = $this->sessionAtTaxStep();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $machine->advance($id, [
            'registered' => true,
            'jurisdiction' => 'SG',
            'registration_number' => 'M99999999X',
            'effective_from' => '2025-01-01',
            'filing_frequency' => 'bimonthly',
        ]);
    }
}
