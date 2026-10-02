<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TaxEngineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #276 F2: DRAFT -> PENDING_APPROVAL -> APPROVED -> ACTIVE with SoD,
 * expected-version activation, engine only resolving ACTIVE rules, and
 * retire-instead-of-delete for referenced rules.
 */
class TaxRuleLifecycleTest extends TenantTestCase
{
    private User $author;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $makeAdmin = function (string $suffix) use ($tenant) {
            $user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().$suffix.'@taxfix.test']);
            $user->assignRole('Admin');
            DB::table('user_company_access')->insert([
                'user_id' => $user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
                'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $user;
        };

        $this->author = $makeAdmin('-author');
        $this->approver = $makeAdmin('-approver');
    }

    private function draftRule(): Tax
    {
        return Tax::create([
            'name' => 'Lifecycle rule', 'code' => 'LC-'.Str::upper(Str::random(4)),
            'type' => 'gst', 'regime' => 'GST', 'jurisdiction' => 'SG',
            'scope' => 'both', 'treatment' => 'standard_rated', 'rate' => 9,
            'is_active' => false, 'lifecycle_state' => 'DRAFT',
        ])->refresh();
    }

    public function test_full_lifecycle_with_sod_version_protection_and_engine_gating(): void
    {
        $rule = $this->draftRule();
        $product = Product::create(['name' => 'LC product', 'sku' => 'LC-'.Str::random(6), 'is_active' => true, 'track_inventory' => false]);
        $product->forceFill(['tax_id' => $rule->id])->save();

        // DRAFT rules never resolve.
        $resolved = app(TaxEngineService::class)->resolve($product->refresh(), 'SG', 'output');
        $this->assertNotSame((int) $rule->id, (int) ($resolved['tax_id'] ?? 0), 'DRAFT rules must not resolve.');

        // Author submits; author cannot approve own rule.
        $this->actingAs($this->author)->postJson("/api/v1/taxes/{$rule->id}/submit")->assertStatus(200);
        $this->assertSame('PENDING_APPROVAL', $rule->refresh()->lifecycle_state);
        $this->actingAs($this->author)->postJson("/api/v1/taxes/{$rule->id}/approve")->assertStatus(403);

        // Independent approver approves.
        $this->actingAs($this->approver)->postJson("/api/v1/taxes/{$rule->id}/approve")->assertStatus(200);
        $this->assertSame('APPROVED', $rule->refresh()->lifecycle_state);
        $this->assertSame($this->approver->id, (int) $rule->approved_by);

        // Stale activation token conflicts; correct one activates.
        $this->actingAs($this->approver)->postJson("/api/v1/taxes/{$rule->id}/activate", ['expected_version' => 99])->assertStatus(409);
        $version = (int) $rule->refresh()->version;
        $this->actingAs($this->approver)->postJson("/api/v1/taxes/{$rule->id}/activate", ['expected_version' => $version])->assertStatus(200);
        $this->assertSame('ACTIVE', $rule->refresh()->lifecycle_state);
        $this->assertTrue((bool) $rule->is_active);
        $this->assertSame($version + 1, (int) $rule->version);

        // ACTIVE now resolves.
        $resolved = app(TaxEngineService::class)->resolve($product->refresh(), 'SG', 'output');
        $this->assertSame((int) $rule->id, (int) ($resolved['tax_id'] ?? 0));

        // Audit lineage for the transitions.
        foreach (['tax_rule_PENDING_APPROVAL', 'tax_rule_APPROVED', 'tax_rule_ACTIVE'] as $operation) {
            $this->assertTrue(DB::table('audit_logs')->where('operation', $operation)->exists(), "Missing audit row {$operation}.");
        }
    }

    public function test_referenced_rules_are_retired_not_deleted_and_unreferenced_rules_retire(): void
    {
        $rule = Tax::create([
            'name' => 'Retire rule', 'code' => 'RT-'.Str::upper(Str::random(4)),
            'type' => 'gst', 'regime' => 'GST', 'jurisdiction' => 'SG',
            'scope' => 'both', 'treatment' => 'standard_rated', 'rate' => 9, 'is_active' => true,
        ])->refresh();

        $product = Product::create(['name' => 'RT product', 'sku' => 'RT-'.Str::random(6), 'is_active' => true, 'track_inventory' => false]);
        $product->forceFill(['tax_id' => $rule->id])->save();

        $this->actingAs($this->approver)->deleteJson("/api/v1/taxes/{$rule->id}")->assertStatus(422);
        $this->assertNotSame('RETIRED', $rule->refresh()->lifecycle_state);

        $product->forceFill(['tax_id' => null])->save();
        $this->actingAs($this->approver)->deleteJson("/api/v1/taxes/{$rule->id}")->assertStatus(200);
        $this->assertSame('RETIRED', $rule->refresh()->lifecycle_state);
        $this->assertFalse((bool) $rule->is_active);
    }
}
