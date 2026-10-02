<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\ChartOfAccount;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #736 T3: statutory returns are per jurisdiction — the SG F5 must not
 * include MY rows (or vice versa), NULL-jurisdiction rows are surfaced as
 * unassigned, and multi-entity tenants must pick an entity.
 */
class StatutoryReturnJurisdictionTest extends TenantTestCase
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
        $this->actingAs($this->user);
    }

    public function test_f5_and_sst02_each_contain_only_their_jurisdiction(): void
    {
        $cash = ChartOfAccount::factory()->create(['code' => 'JX-CASH-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);
        $revenue = ChartOfAccount::factory()->create(['code' => 'JX-REV-'.Str::upper(Str::random(4)), 'type' => 'revenue', 'is_active' => true]);
        $taxAccount = ChartOfAccount::factory()->create(['code' => 'JX-TAX-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true]);

        $gstRule = Tax::query()->firstOrCreate(
            ['code' => 'JX-GST-9'],
            ['name' => 'JX GST 9', 'type' => 'gst', 'jurisdiction' => 'SG', 'rate' => 9, 'is_active' => true],
        );
        $svcRule = Tax::query()->firstOrCreate(
            ['code' => 'JX-SVC-8'],
            ['name' => 'JX SVC 8', 'type' => 'service', 'jurisdiction' => 'MY', 'rate' => 8, 'is_active' => true],
        );

        $sgEntryId = $this->postTaxed($cash, $revenue, $taxAccount, $gstRule->id, 'JX-GST-9', 100.0, 9.0, 'SG');
        $this->postTaxed($cash, $revenue, $taxAccount, $svcRule->id, 'JX-SVC-8', 200.0, 16.0, 'MY');

        // Legacy/manual trail row without a jurisdiction: the gate correctly
        // blocks NEW unassigned tax postings, so the fixture writes the row
        // directly — the return must surface it as unassigned, never fold it
        // into a country's boxes.
        DB::table('tax_transaction_details')->insert([
            'journal_entry_id' => $sgEntryId,
            'source_document_type' => 'invoice',
            'source_document_id' => 0,
            'tax_code' => 'JX-GST-9',
            'tax_rule_id' => $gstRule->id,
            'tax_rate' => 9.0,
            'tax_treatment' => 'standard_rated',
            'tax_regime' => 'GST',
            'taxable_amount' => 50.0,
            'tax_amount' => 4.5,
            'reporting_period' => '2026-05',
            'jurisdiction' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sg = $this->getJson('/api/v1/accounting/statutory-returns/SG/2026-05')->assertOk();
        $this->assertSame(100.0, (float) $sg->json('data.boxes.box_1_standard_rated_supplies'));
        $this->assertSame(9.0, (float) $sg->json('data.boxes.box_6_output_tax_due'), 'F5 must not include MY or unassigned rows.');
        $this->assertSame(1, (int) $sg->json('data.unassigned.rows'), 'NULL-jurisdiction rows must be surfaced, not silently included.');

        $my = $this->getJson('/api/v1/accounting/statutory-returns/MY/2026-05')->assertOk();
        $this->assertSame(200.0, (float) $my->json('data.service_tax.taxable_value'));
        $this->assertSame(16.0, (float) $my->json('data.service_tax.tax_due'), 'SST-02 must not include SG or unassigned rows.');
        $this->assertSame(1, (int) $my->json('data.unassigned.rows'));
    }

    public function test_multi_entity_tenant_requires_an_entity(): void
    {
        DB::table('entity_groups')->insert([
            'name' => 'JX group', 'code' => 'JXG-'.Str::upper(Str::random(5)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $groupId = (int) DB::table('entity_groups')->orderByDesc('id')->value('id');

        foreach ([1, 2] as $i) {
            DB::table('legal_entities')->insert([
                'group_id' => $groupId, 'name' => "JX entity {$i}", 'code' => 'JXE'.$i.'-'.Str::upper(Str::random(4)),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->getJson('/api/v1/accounting/statutory-returns/SG/2026-05')->assertStatus(422);
    }

    private function postTaxed(
        ChartOfAccount $cash,
        ChartOfAccount $revenue,
        ChartOfAccount $taxAccount,
        int $taxId,
        string $taxCode,
        float $base,
        float $tax,
        ?string $jurisdiction,
    ): int {
        $lineDetermination = [
            'tax_code' => $taxCode, 'taxable_base' => $base,
            'tax_rate' => round($tax / $base * 100, 2), 'tax_amount' => $tax, 'treatment' => 'standard_rated',
        ];
        $determination = ['tax_amount' => $tax];

        if ($jurisdiction !== null) {
            $lineDetermination['jurisdiction'] = $jurisdiction;
            $determination['jurisdiction'] = $jurisdiction;
        }

        $determination['tax_lines'] = [$lineDetermination];

        $result = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: '2026-05-15',
            description: 'JX '.($jurisdiction ?? 'unassigned'),
            lines: [
                new PostingRequestLine(accountMappingKey: 'cash', accountId: $cash->id, debit: $base + $tax, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $revenue->id, debit: 0.0, credit: $base),
                new PostingRequestLine(accountMappingKey: 'sales_tax_payable', accountId: $taxAccount->id, debit: 0.0, credit: $tax, taxCode: $taxCode, taxId: $taxId),
            ],
            userId: $this->user->id,
            taxDeterminationResult: $determination,
        ));

        $this->assertTrue($result->success, $result->error ?? '');

        return (int) DB::table('journal_entries')
            ->where('id', '>=', 1)
            ->orderByDesc('id')
            ->value('id');
    }
}
