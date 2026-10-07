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
 * Issue #921 (SS5): a setting the Settings screen saves must be declared once
 * in the platform settings registry, with the code paths that read it. A
 * saved value that nothing reads is the "silently ignored" failure (#539).
 *
 * Driven through the real routes: PUT /api/v1/settings/sales persists the
 * values, and GET /api/v1/settings/sales reports, for every field, the
 * registry entry ("where used", #555) generated from that same registry.
 */
class Issue921SalesSettingsWhereUsedTest extends TenantTestCase
{
    private User $admin;

    /** The settings screen's own read/echo methods never count as a reader. */
    private const SCREEN_METHODS = [
        'App\\Http\\Controllers\\Api\\CompanySettingsController::getSalesSettings',
        'App\\Http\\Controllers\\Api\\CompanySettingsController::updateSalesSettings',
    ];

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

    /** A PUT body that changes every field the Sales Settings screen accepts. */
    private function fullPayload(): array
    {
        $reviewers = ['reviewer_user_ids' => [$this->admin->id], 'reviewer_roles' => ['manager']];

        return [
            'cash_rounding' => ['enabled' => true, 'method' => 'up', 'precision' => 0.1],
            'invoice' => [
                'allow_custom_number' => false,
                'field_profile' => 'trading',
                'default_requires_approval' => true,
                'approval_expiry_days' => 21,
                'auto_send_to_customer_on_post' => false,
                'allow_partial_payments' => false,
                'default_payment_terms_days' => 14,
                'default_notes' => 'Thank you.',
                'default_terms' => 'Net 14.',
            ] + $reviewers,
            'portal' => [
                'acceptance_enabled' => false,
                'require_signature' => false,
                'acceptance_link_expiry_days' => 10,
                'payment_link_expiry_days' => 5,
            ],
            'tax' => ['prices_include_tax' => true, 'default_tax_treatment' => 'exempt'],
            'delivery' => [
                'attach_pdf' => false,
                'cc_internal_email' => 'books@example.test',
                'document_type_on_send' => 'acceptance_copy',
            ],
            'delivery_order' => ['default_requires_approval' => true] + $reviewers,
            'quotation' => $reviewers,
            'sales_order' => $reviewers,
            'invoice_threshold' => $reviewers,
            'defaults' => ['salesperson_mode' => 'current_user', 'team_by_user' => [(string) $this->admin->id => 'North']],
            'pricelist' => ['display' => 'net_price'],
        ];
    }

    /** @return array<string, string> key => raw stored value */
    private function storedSettings(): array
    {
        return DB::table('settings')->pluck('value', 'key')->map(fn ($v) => (string) $v)->all();
    }

    public function test_every_setting_the_screen_saves_is_declared_with_a_reader_outside_the_screen(): void
    {
        $before = $this->storedSettings();

        $this->actingAs($this->admin)->putJson('/api/v1/settings/sales', $this->fullPayload())->assertOk();

        $after = $this->storedSettings();
        $persistedKeys = array_keys(array_filter(
            $after,
            fn ($value, $key) => ! array_key_exists($key, $before) || $before[$key] !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));
        sort($persistedKeys);

        // Sanity: the PUT really persisted the screen's values.
        $this->assertContains('sales.invoice.approval_expiry_days', $persistedKeys);
        $this->assertContains('tax', $persistedKeys);
        $this->assertContains('sales.cash_rounding', $persistedKeys);

        $whereUsed = $this->actingAs($this->admin)->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->json('data.where_used');

        $this->assertIsArray($whereUsed, 'GET /settings/sales must report where each setting is used (#921/#555).');

        $declaredKeys = array_values(array_unique(array_column($whereUsed, 'key')));

        // Business rule: nothing the screen saves is undeclared (an orphan).
        $undeclared = array_values(array_diff($persistedKeys, $declaredKeys));
        $this->assertSame([], $undeclared, 'Saved by PUT /settings/sales but not declared in the settings registry.');

        // Every screen field the PUT accepted is declared, with >= 1 reader
        // that is not the settings screen echoing its own value back.
        foreach ($this->fullPayload() as $group => $fields) {
            foreach (array_keys($fields) as $field) {
                $path = "{$group}.{$field}";
                $this->assertArrayHasKey($path, $whereUsed, "{$path} is not declared in the settings registry.");

                $entry = $whereUsed[$path];
                foreach (['key', 'type', 'scope', 'edit_permission', 'readers'] as $attribute) {
                    $this->assertArrayHasKey($attribute, $entry, "{$path} is missing '{$attribute}'.");
                }
                $this->assertContains($entry['scope'], ['tenant', 'entity', 'user'], $path);
                $this->assertSame('settings.write', $entry['edit_permission'], $path);

                $liveReaders = array_values(array_diff($entry['readers'], self::SCREEN_METHODS));
                $this->assertNotEmpty($liveReaders, "{$path} ({$entry['key']}) is saved but nothing outside the settings screen reads it.");
            }
        }
    }

    public function test_where_used_is_read_only_and_does_not_change_what_put_persists(): void
    {
        $payload = $this->fullPayload();
        $payload['where_used'] = ['invoice.approval_expiry_days' => ['key' => 'evil.key', 'readers' => []]];

        $this->actingAs($this->admin)->putJson('/api/v1/settings/sales', $payload)->assertOk();

        $this->assertNull(DB::table('settings')->where('key', 'evil.key')->first());
        $this->assertSame(21, $this->actingAs($this->admin)->getJson('/api/v1/settings/sales')->json('data.invoice.approval_expiry_days'));
    }
}
