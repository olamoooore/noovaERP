<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #274 G-17: the legacy `/accounting/tax-rates` surface is a compatibility
 * shim over the canonical `taxes` store — the `tax_rates` TABLE is no longer
 * read or written by the application.
 */
class TaxRatesLegacyShimTest extends TenantTestCase
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

    public function test_legacy_tax_rates_endpoints_read_and_write_the_canonical_store(): void
    {
        $create = $this->actingAs($this->user)->postJson('/api/v1/accounting/tax-rates', [
            'name' => 'Shim 9%', 'code' => 'SHIM-'.Str::upper(Str::random(4)),
            'rate' => 9, 'type' => 'gst',
        ]);

        $create->assertStatus(201);
        $id = (int) $create->json('tax_rate.id');
        $this->assertGreaterThan(0, $id);

        $this->assertTrue(Tax::whereKey($id)->exists(), 'The shim must write the canonical taxes store.');
        $this->assertSame(0, DB::table('tax_rates')->count(), 'The legacy table must not be written.');

        $list = $this->actingAs($this->user)->getJson('/api/v1/accounting/tax-rates');
        $list->assertStatus(200);
        $this->assertTrue(collect($list->json())->contains(fn ($row) => (int) $row['id'] === $id));

        $update = $this->actingAs($this->user)->putJson("/api/v1/accounting/tax-rates/{$id}", ['name' => 'Shim renamed']);
        $update->assertStatus(200);
        $this->assertSame('Shim renamed', Tax::find($id)->name);

        $delete = $this->actingAs($this->user)->deleteJson("/api/v1/accounting/tax-rates/{$id}");
        $delete->assertStatus(200);
        $this->assertFalse((bool) Tax::find($id)->is_active, 'Legacy delete deactivates on the canonical store.');
    }
}