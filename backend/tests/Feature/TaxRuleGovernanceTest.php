<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #274 G-10/G-11/G-13 (Section 2): tax rule governance — optimistic version
 * token (no last-write-wins), activation separation-of-duties, immutable audit
 * lineage, and the versioned replacement trail.
 */
class TaxRuleGovernanceTest extends TenantTestCase
{
    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = (string) Str::uuid();
        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => $tenantId]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        foreach (['Admin', 'Manager', 'Viewer'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        $this->admin->assignRole('Admin');
        $this->manager = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        $this->manager->assignRole('Manager');

        foreach ([[$this->admin, 'admin'], [$this->manager, 'manager']] as [$u, $role]) {
            DB::table('user_company_access')->insert([
                'user_id' => $u->id, 'tenant_id' => $tenant->id, 'role' => $role,
                'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Auth::login($this->admin);
    }

    private function rule(array $extra = []): Tax
    {
        return Tax::create(array_merge([
            'name' => 'Gov rule '.Str::random(4), 'code' => 'GOV-'.Str::upper(Str::random(4)),
            'type' => 'gst', 'regime' => 'GST', 'jurisdiction' => 'SG',
            'scope' => 'both', 'treatment' => 'standard_rated', 'rate' => 9,
            'is_active' => true,
        ], $extra))->refresh();
    }

    public function test_stale_expected_version_is_rejected_with_409(): void
    {
        $tax = $this->rule();

        $stale = $this->putJson("/api/v1/taxes/{$tax->id}", ['name' => 'Renamed', 'expected_version' => 99]);
        $this->assertSame(409, $stale->status(), 'A stale version token must not overwrite.');
        $this->assertSame(1, (int) $stale->json('current_version'));

        $ok = $this->putJson("/api/v1/taxes/{$tax->id}", ['name' => 'Renamed', 'expected_version' => 1]);
        $this->assertSame(200, $ok->status());
        $this->assertSame(2, (int) $ok->json('version'), 'A successful update must bump the version.');

        $tax->refresh();
        $this->assertSame('Renamed', $tax->name);
        $this->assertSame(2, (int) $tax->version);
    }

    public function test_activation_requires_policy_manage_separation_of_duties(): void
    {
        $tax = $this->rule(['is_active' => false]);
        $this->assertSame(1, (int) $tax->version);

        $this->actingAs($this->manager);
        $denied = $this->putJson("/api/v1/taxes/{$tax->id}", ['is_active' => true]);
        $this->assertSame(403, $denied->status(), 'A routine tax.manage editor must not self-activate a rule.');
        $this->assertSame('tax.policy.manage', $denied->json('required_permission'));

        $stillEditable = $this->putJson("/api/v1/taxes/{$tax->id}", ['name' => 'Manager rename']);
        $this->assertSame(200, $stillEditable->status(), 'Non-activation edits remain available to tax.manage.');

        $this->actingAs($this->admin);
        $activated = $this->putJson("/api/v1/taxes/{$tax->id}", ['is_active' => true]);
        $this->assertSame(200, $activated->status());
        $this->assertTrue((bool) $activated->json('is_active'));
    }

    public function test_rule_changes_write_immutable_audit_trail(): void
    {
        $created = $this->postJson('/api/v1/taxes', [
            'name' => 'Audited rule', 'code' => 'AUD-'.Str::upper(Str::random(4)),
            'type' => 'gst', 'rate' => 9, 'is_active' => false,
        ]);
        $this->assertSame(201, $created->status());
        $taxId = (int) $created->json('id');

        $this->putJson("/api/v1/taxes/{$taxId}", ['name' => 'Audited rule v2']);

        $trail = $this->getJson("/api/v1/taxes/{$taxId}/audit-trail");
        $this->assertSame(200, $trail->status());

        $operations = collect($trail->json())->pluck('operation');
        $this->assertTrue($operations->contains('tax_rule_created'));
        $this->assertTrue($operations->contains('tax_rule_updated'));

        $updated = collect($trail->json())->firstWhere('operation', 'tax_rule_updated');
        $this->assertSame('Audited rule', $updated['before_state']['name']);
        $this->assertSame('Audited rule v2', $updated['after_state']['name']);
        $this->assertSame($this->admin->id, (int) $updated['actor_id']);

        $this->assertSame(
            AuditLog::where('reference_type', 'tax_rule')->where('reference_id', $taxId)->count(),
            collect($trail->json())->count(),
            'Every rule change must be represented in the immutable trail.'
        );
    }

    public function test_versioned_replacement_records_lineage(): void
    {
        $live = $this->rule(['effective_from' => now()->subYear()->toDateString()]);

        $resp = $this->putJson("/api/v1/taxes/{$live->id}", ['rate' => 10]);
        $this->assertSame(200, $resp->status());
        $this->assertTrue((bool) $resp->json('versioned'));

        $trail = collect($this->getJson("/api/v1/taxes/{$live->id}/audit-trail")->json());
        $versioned = $trail->firstWhere('operation', 'tax_rule_versioned');
        $this->assertNotNull($versioned, 'The closed rule must carry a versioned lineage row.');
        $this->assertSame((int) $resp->json('id'), (int) $versioned['after_state']['versioned_to']);
        $this->assertSame(2, (int) $versioned['after_state']['version'], 'Closing a live rule bumps its version.');
    }
}
