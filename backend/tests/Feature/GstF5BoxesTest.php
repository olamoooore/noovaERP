<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Singapore\Services\GstF5ReturnService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #745 T12: the GST F5 must produce the FULL IRAS box set (1-15), not just
 * 1-8. Box definitions come from the SG pack (data), box 13 is computed from
 * posted revenue accounts, and boxes needing manual input are recorded and
 * audited when the return is filed.
 *
 * Box numbering/wording is marked verified:false in the pack pending IRAS
 * confirmation (AC3); this test asserts the data-driven behaviour, not the
 * statutory wording.
 */
class GstF5BoxesTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@f5boxes.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function postedRevenue(string $date, float $amount): void
    {
        $revenue = DB::table('chart_of_accounts')->insertGetId([
            'code' => '4'.Str::random(3), 'name' => 'Sales Revenue', 'type' => 'revenue',
            'subtype' => 'operating_revenue', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $receivable = DB::table('chart_of_accounts')->insertGetId([
            'code' => '1'.Str::random(3), 'name' => 'Accounts Receivable', 'type' => 'asset',
            'subtype' => 'current_asset', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $jeId = DB::table('journal_entries')->insertGetId([
            'entry_number' => 'JE-'.Str::random(10), 'entry_date' => $date, 'type' => 'manual',
            'status' => 'posted', 'posted_at' => now(), 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('journal_entry_lines')->insert([
            ['journal_entry_id' => $jeId, 'account_id' => $receivable, 'debit' => $amount, 'credit' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['journal_entry_id' => $jeId, 'account_id' => $revenue, 'debit' => 0, 'credit' => $amount, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_f5_produces_all_iris_boxes_from_the_pack(): void
    {
        $return = app(GstF5ReturnService::class)->generate('2025-Q3');

        $this->assertArrayHasKey('box_1_standard_rated_supplies', $return['boxes']);
        foreach ([
            'box_9_imports_under_schemes',
            'box_10_tourist_refund_claimed',
            'box_11_bad_debt_relief',
            'box_12_pre_registration_claims',
            'box_13_revenue',
            'box_14_imported_services_reverse_charge',
            'box_15_electronic_marketplace',
        ] as $key) {
            $this->assertArrayHasKey($key, $return['boxes'], "The F5 must include {$key}.");
        }

        // Definitions are pack data and the manual ones are flagged.
        $this->assertCount(15, $return['box_definitions']);
        $this->assertContains('box_9_imports_under_schemes', $return['requires_manual_input']);
        $this->assertContains('box_15_electronic_marketplace', $return['requires_manual_input']);

        $def = collect($return['box_definitions'])->firstWhere('box', 13);
        $this->assertSame('revenue', $def['source']);
        $this->assertFalse((bool) $def['verified'], 'Box wording is unverified until confirmed against the IRAS F5 form.');
    }

    public function test_box_13_revenue_is_computed_from_posted_revenue_accounts(): void
    {
        $this->postedRevenue('2025-08-15', 4321.00);

        $return = app(GstF5ReturnService::class)->generate('2025-Q3');

        $this->assertSame(4321.00, (float) $return['boxes']['box_13_revenue'], 'Box 13 must be the posted revenue for the period.');
    }

    public function test_manual_boxes_are_recorded_and_audited_on_file(): void
    {
        $response = $this->postJson('/api/v1/accounting/statutory-returns/SG/2025-Q3/file', [
            'box_inputs' => [
                'box_9_imports_under_schemes' => 250.00,
                'box_15_electronic_marketplace' => true,
            ],
        ])->assertStatus(201);

        $boxes = $response->json('data.boxes');
        $this->assertSame(250.0, (float) ($boxes['box_9_imports_under_schemes'] ?? null), 'A provided manual box must be recorded.');
        $this->assertTrue((bool) ($boxes['box_15_electronic_marketplace'] ?? null), 'A declaration box must be recorded.');

        $audit = AuditLog::query()
            ->where('operation', 'statutory_return_box_inputs')
            ->orderByDesc('id')->first();
        $this->assertNotNull($audit, 'Manual box inputs must be audited before filing.');
        $this->assertSame(250.0, (float) data_get($audit->after_state, 'box_9_imports_under_schemes'));
    }

    public function test_box_definitions_are_pack_data(): void
    {
        $defs = (array) config('tax_packs.SG.f5_boxes');
        $defs[0]['label'] = 'TEST-ONLY LABEL';
        config(['tax_packs.SG.f5_boxes' => $defs]);

        $return = app(GstF5ReturnService::class)->generate('2025-Q3');

        $this->assertSame('TEST-ONLY LABEL', collect($return['box_definitions'])->firstWhere('box', 1)['label'], 'Box definitions must come from the pack, not a PHP constant.');
    }
}
