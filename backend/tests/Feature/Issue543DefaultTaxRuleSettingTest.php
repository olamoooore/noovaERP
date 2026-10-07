<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #543 (ST6): Sales Settings "Default tax rate" mapped a free-typed %
 * to `default_tax_id` by searching for an active rule with that rate. When no
 * rule matched, the old `default_tax_id` was kept and the PUT still returned
 * success, so the user's change was silently ignored.
 *
 * Acceptance: the default is chosen from the active tax rules (writing
 * `default_tax_id`), and an unknown rate / rule returns 422 without changing
 * the stored default. All assertions go through the real routes
 * (PUT/GET /api/v1/settings/sales).
 */
class Issue543DefaultTaxRuleSettingTest extends TenantTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenantUuid,
            'name' => 'Issue 543 Tenant',
            'email' => $tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Test fixture rules only — not statutory data. */
    private function rule(string $code, float $rate, array $overrides = []): Tax
    {
        return Tax::create(array_merge([
            'name' => "Test rule {$code}", 'code' => $code, 'type' => 'gst', 'regime' => 'GST',
            'jurisdiction' => null, 'scope' => 'both', 'treatment' => 'standard_rated',
            'rate' => $rate, 'is_active' => true,
        ], $overrides));
    }

    private function storedDefaultTaxId(): ?int
    {
        $id = Setting::getValue('tax', [])['default_tax_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function test_a_rate_that_matches_no_active_rule_is_rejected_and_the_default_is_unchanged(): void
    {
        $rule = $this->rule('T543-A', 7);

        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/sales', ['tax' => ['default_tax_rate' => 7]])
            ->assertOk();
        $this->assertSame($rule->id, $this->storedDefaultTaxId());

        // Business rule: an unmatched rate must not silently succeed.
        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/sales', ['tax' => ['default_tax_rate' => 42]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tax.default_tax_rate']);

        $this->assertSame($rule->id, $this->storedDefaultTaxId());
        $this->actingAs($this->admin)->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->assertJsonPath('data.tax.default_tax_rate', 7)
            ->assertJsonPath('data.tax.default_tax_id', $rule->id);
    }

    public function test_a_rate_matching_only_an_inactive_or_purchase_only_rule_is_rejected(): void
    {
        $this->rule('T543-OFF', 11, ['is_active' => false]);
        $this->rule('T543-IN', 12, ['scope' => 'input']);
        $this->rule('T543-DRAFT', 13, ['lifecycle_state' => 'DRAFT']);

        foreach ([11, 12, 13] as $rate) {
            $this->actingAs($this->admin)
                ->putJson('/api/v1/settings/sales', ['tax' => ['default_tax_rate' => $rate]])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['tax.default_tax_rate']);
        }

        $this->assertNull($this->storedDefaultTaxId());
    }

    public function test_the_default_is_picked_from_the_active_sales_tax_rules_by_id(): void
    {
        $five = $this->rule('T543-5', 5);
        $output = $this->rule('T543-OUT', 6, ['scope' => 'output']);
        $inactive = $this->rule('T543-X', 8, ['is_active' => false]);
        $purchase = $this->rule('T543-P', 9, ['scope' => 'input']);

        $options = $this->actingAs($this->admin)->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->json('data.tax.available_taxes');

        $ids = array_column($options, 'id');
        $this->assertContains($five->id, $ids);
        $this->assertContains($output->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($purchase->id, $ids);

        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/sales', ['tax' => ['default_tax_id' => $output->id]])
            ->assertOk()
            ->assertJsonPath('data.tax.default_tax_id', $output->id)
            ->assertJsonPath('data.tax.default_tax_rate', 6);
        $this->assertSame($output->id, $this->storedDefaultTaxId());

        // Clearing the picker removes the tenant default.
        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/sales', ['tax' => ['default_tax_id' => null]])
            ->assertOk()
            ->assertJsonPath('data.tax.default_tax_id', null);
        $this->assertNull($this->storedDefaultTaxId());
    }

    public function test_an_unknown_inactive_or_purchase_only_rule_id_is_rejected(): void
    {
        $current = $this->rule('T543-CUR', 5);
        $inactive = $this->rule('T543-X', 8, ['is_active' => false]);
        $purchase = $this->rule('T543-P', 9, ['scope' => 'input']);

        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/sales', ['tax' => ['default_tax_id' => $current->id]])
            ->assertOk();

        foreach ([999999, $inactive->id, $purchase->id] as $id) {
            $this->actingAs($this->admin)
                ->putJson('/api/v1/settings/sales', ['tax' => ['default_tax_id' => $id]])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['tax.default_tax_id']);
        }

        $this->assertSame($current->id, $this->storedDefaultTaxId());
    }
}
