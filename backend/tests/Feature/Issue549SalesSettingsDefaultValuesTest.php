<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #549 (SET-UX5): the Sales Settings screen marks settings that differ
 * from the default and offers a per-setting "Reset to default". The defaults
 * must come from the server (the one-default-per-key SalesSettings accessor,
 * #539), so GET /settings/sales reports them read-only, and a reset is a
 * normal PUT of the default value through the same endpoint.
 */
class Issue549SalesSettingsDefaultValuesTest extends TenantTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The defaults a tenant that never saved anything sees. */
    private function expectedDefaults(): array
    {
        return [
            'cash_rounding' => ['enabled' => false, 'method' => 'nearest', 'precision' => 0.05],
            'invoice' => [
                'allow_custom_number' => true,
                'field_profile' => 'medical',
                'default_requires_approval' => false,
                'approval_expiry_days' => 7,
                'auto_send_to_customer_on_post' => true,
                'allow_partial_payments' => true,
                'default_payment_terms_days' => 30,
                'default_notes' => '',
                'default_terms' => 'Payment due within 30 days.',
            ],
            'portal' => [
                'acceptance_enabled' => true,
                'require_signature' => true,
                'acceptance_link_expiry_days' => 30,
                'payment_link_expiry_days' => 14,
            ],
            'delivery' => [
                'attach_pdf' => true,
                'cc_internal_email' => '',
                'document_type_on_send' => 'final_invoice',
            ],
            'delivery_order' => ['default_requires_approval' => false],
            'defaults' => ['salesperson_mode' => 'none'],
            'pricelist' => ['display' => 'show_discount'],
        ];
    }

    public function test_settings_payload_reports_the_server_defaults_and_a_fresh_tenant_matches_them(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/settings/sales')->assertOk();

        $defaults = $response->json('data.default_values');
        $this->assertEquals($this->expectedDefaults(), $defaults);

        // A tenant that never saved a key is, by definition, "unchanged".
        foreach ($defaults as $group => $fields) {
            foreach ($fields as $field => $default) {
                $this->assertEquals($default, $response->json("data.{$group}.{$field}"), "{$group}.{$field}");
            }
        }
    }

    public function test_changed_values_differ_from_the_reported_defaults_and_reset_through_put_restores_them(): void
    {
        $changed = [
            'invoice' => [
                'approval_expiry_days' => 21,
                'default_notes' => 'Thanks!',
                'default_terms' => 'Net 7.',
                'allow_partial_payments' => false,
            ],
            'portal' => ['payment_link_expiry_days' => 3],
            'delivery' => ['cc_internal_email' => 'books@example.test', 'document_type_on_send' => 'acceptance_copy'],
            'pricelist' => ['display' => 'net_price'],
        ];

        $after = $this->actingAs($this->admin)->putJson('/api/v1/settings/sales', $changed)->assertOk();

        // The defaults stay the defaults; the saved values now differ.
        $this->assertEquals($this->expectedDefaults(), $after->json('data.default_values'));
        foreach ($changed as $group => $fields) {
            foreach ($fields as $field => $value) {
                $this->assertEquals($value, $after->json("data.{$group}.{$field}"));
                $this->assertNotEquals($after->json("data.default_values.{$group}.{$field}"), $value);
            }
        }

        // "Reset to default" = PUT the reported default for each field.
        $reset = [];
        foreach ($changed as $group => $fields) {
            foreach (array_keys($fields) as $field) {
                $reset[$group][$field] = $after->json("data.default_values.{$group}.{$field}");
            }
        }

        $restored = $this->actingAs($this->admin)->putJson('/api/v1/settings/sales', $reset)->assertOk();

        foreach ($reset as $group => $fields) {
            foreach ($fields as $field => $default) {
                $this->assertEquals($default, $restored->json("data.{$group}.{$field}"), "{$group}.{$field}");
            }
        }

        // The persisted state survives a fresh read.
        $fresh = $this->actingAs($this->admin)->getJson('/api/v1/settings/sales')->assertOk();
        $this->assertSame('', $fresh->json('data.invoice.default_notes'));
        $this->assertSame('', $fresh->json('data.delivery.cc_internal_email'));
        $this->assertSame(14, $fresh->json('data.portal.payment_link_expiry_days'));
    }
}
