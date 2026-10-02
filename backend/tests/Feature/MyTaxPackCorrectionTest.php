<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #734 T1: the MY pack must not offer the fictional "SST (GST-model)"
 * 8%/10% rules, and MY SST rules must not claim creditable input tax.
 *
 * Reproduced first on main: activation created MY-SSTG-8/MY-SSTG-10 and
 * every rule defaulted to creditable=true.
 */
class MyTaxPackCorrectionTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_activating_my_pack_creates_no_fictional_gst_model_rules(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/tax-engine/packs/MY/activate')
            ->assertOk();

        foreach (['MY-SSTG-8', 'MY-SSTG-10', 'MY-SST-ZERO', 'MY-SST-EXEMPT'] as $code) {
            $this->assertFalse(
                Tax::withTrashed()->where('code', $code)->exists(),
                "Fictional rule {$code} must not be seeded (#734).",
            );
        }

        $this->assertTrue(Tax::where('code', 'MY-SALES-10')->exists(), 'Enacted sales-tax rows survive.');
        $this->assertTrue(Tax::where('code', 'MY-SVC-8')->exists(), 'Enacted service-tax rows survive.');
    }

    public function test_my_sst_rules_are_not_creditable_and_output_only(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/tax-engine/packs/MY/activate')
            ->assertOk();

        $rules = Tax::query()->where('jurisdiction', 'MY')->where('regime', 'SST')->get();
        $this->assertNotEmpty($rules);

        foreach ($rules as $rule) {
            $this->assertFalse((bool) $rule->creditable, "MY SST rule {$rule->code} must not be creditable (single-stage tax).");
            $this->assertSame('output', $rule->scope, "MY SST rule {$rule->code} must be output-scope only.");
        }
    }
}
