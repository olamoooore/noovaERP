<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TenantTestCase;

/**
 * MRD Phase 5 mechanical items:
 *  - R4.5 withholding v1: rule-driven deduction posting (wht_payable),
 *    excluded from GST/SST return buckets, visible in reconciliation and
 *    the withholding summary endpoint.
 *  - R7.5 tax.view/tax.manage route switch + mirror-sync command.
 */
class TaxEnginePhase5WithholdingTest extends TenantTestCase
{
    private User $user;

    private string $tenantId;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => $this->tenantId]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['tax_transaction_details']);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->user = User::factory()->create();
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['accounting.write', 'tax.view', 'tax.manage', 'reports.view', 'reports.manage'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            $this->user->givePermissionTo($permission);
        }

        foreach (
            [
                'accounts_payable' => ['1000-W5', 'liability'],
                'general_expense' => ['4000-W5', 'expense'],
            ] as $key => [$code, $type]
        ) {
            $account = ChartOfAccount::create([
                'code' => $code, 'name' => "W5 {$key}", 'type' => $type,
                'account_type' => $type, 'is_active' => true,
            ]);
            AccountMapping::create(['mapping_key' => $key, 'account_id' => $account->id, 'is_active' => true]);
        }

        Setting::setValue('tax', ['enabled' => true, 'pricing_mode' => 'exclusive', 'jurisdiction' => 'MY']);
        $this->period = now()->format('Y-m');
    }

    public function test_withholding_is_deducted_from_payable_and_booked_to_wht_payable(): void
    {
        $wht = Tax::create([
            'name' => 'MY WHT s.109B 15%', 'code' => 'MY-WHT-15', 'type' => 'withholding',
            'regime' => 'SST', 'jurisdiction' => 'MY', 'scope' => 'input',
            'treatment' => 'standard_rated', 'rate' => 15, 'is_active' => true,
        ]);
        $product = Product::create(['name' => 'W5 svc', 'sku' => 'W5S-'.Str::random(6), 'is_active' => true, 'track_inventory' => false]);
        $product->forceFill(['tax_id' => $wht->id, 'type' => 'service'])->save();

        $expenseAccountId = (int) AccountMapping::where('mapping_key', 'general_expense')->value('account_id');

        // Accounting-origin AP bills are the product-aware service-purchase path.
        $bill = app(\App\Services\AccountingOriginBillService::class)->create(
            new \App\Accounting\DTOs\AccountingOriginBillData(
                vendorId: Vendor::factory()->create()->id,
                billType: 'expense',
                lines: [new \App\Accounting\DTOs\AccountingDocumentLineData(
                    lineType: 'service',
                    description: 'Non-resident service',
                    quantity: 1,
                    unitPrice: 1000.00,
                    productId: $product->id,
                    glAccountId: $expenseAccountId,
                    taxRate: 15,
                )],
                autoPost: true,
            ),
        );

        // Withholding is a DEDUCTION from the payable, not tax on top.
        $this->assertSame(850.0, round((float) $bill->total, 2));
        $this->assertSame(150.0, round((float) $bill->tax_amount, 2));

        $whtLine = DB::table('journal_entry_lines')->where('tax_code', 'MY-WHT-15')->first();
        $this->assertNotNull($whtLine);
        $this->assertSame(150.0, (float) $whtLine->credit);
        $whtAccountId = (int) AccountMapping::where('mapping_key', 'wht_payable')->value('account_id');
        $this->assertSame($whtAccountId, (int) $whtLine->account_id, 'WHT must land on the 2220 payable account.');

        $apLine = DB::table('journal_entry_lines')->where('account_id', (int) AccountMapping::where('mapping_key', 'accounts_payable')->value('account_id'))->first();
        $this->assertSame(850.0, (float) $apLine->credit, 'AP must be net of withheld tax.');

        // Trail exists for audit, but GST/SST returns must NOT include WHT.
        $this->assertDatabaseHas('tax_transaction_details', [
            'source_document_type' => 'bill',
            'tax_code' => 'MY-WHT-15',
            'tax_rule_id' => $wht->id,
        ]);

        $aggregate = app(\Modules\AccountingCountry\Services\TaxReturnAggregator::class)->aggregate($this->period);
        $this->assertCount(0, $aggregate['input'], 'Withheld rows never belong in the input-credit bucket.');
        $this->assertCount(0, $aggregate['output']);

        // Recon still ties (withheld expected as payable-credit).
        $recon = app(\Modules\AccountingCountry\Services\TaxReportingService::class)->reconciliation($this->period);
        $this->assertTrue($recon['balanced'], json_encode($recon['by_tax_code']));

        $summary = app(\Modules\AccountingCountry\Services\TaxReportingService::class)->withholdingSummary();
        $this->assertSame(150.0, $summary['total']);
    }

    public function test_withholding_summary_endpoint_is_served(): void
    {
        $wht = Tax::create([
            'name' => 'MY WHT 10%', 'code' => 'MY-WHT-10', 'type' => 'withholding',
            'regime' => 'SST', 'jurisdiction' => 'MY', 'scope' => 'input',
            'treatment' => 'standard_rated', 'rate' => 10, 'is_active' => true,
        ]);
        $period = $this->period;
        $jeId = DB::table('journal_entries')->insertGetId([
            'entry_number' => 'W5JE-'.Str::random(6), 'entry_date' => now()->toDateString(),
            'type' => 'bill', 'status' => 'posted', 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tax_transaction_details')->insert([
            'journal_entry_id' => $jeId, 'source_document_type' => 'bill', 'source_document_id' => 1,
            'tax_code' => 'MY-WHT-10', 'tax_rule_id' => $wht->id, 'taxable_amount' => 500,
            'tax_amount' => 50, 'jurisdiction' => 'MY', 'reporting_period' => $period,
            'tax_rate' => 10, 'tax_treatment' => 'standard_rated', 'tax_regime' => 'SST',
            'result_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/tax-report/withholding')
            ->assertOk()
            ->assertJsonPath('data.total', 50);
    }

    public function test_sync_command_mirrors_report_permissions_to_tax_permissions(): void
    {
        $tenant = DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'W5 Tenant', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Accountant-equivalent role holding reports.view in THIS schema.
        $roleId = DB::table('roles')->insertGetId(['name' => 'W5 Finance', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        $reportsView = (int) DB::table('permissions')->where('name', 'reports.view')->where('guard_name', 'web')->value('id');
        $this->assertGreaterThan(0, $reportsView, 'RolePermissionSeeder must create reports.view');
        DB::table('role_has_permissions')->insert(['permission_id' => $reportsView, 'role_id' => $roleId]);

        $granted = app(\App\Console\Commands\SyncTaxPermissions::class)->syncCurrentSchema();
        $this->assertGreaterThanOrEqual(1, $granted);

        $taxViewId = (int) DB::table('permissions')->where('name', 'tax.view')->where('guard_name', 'web')->value('id');
        $this->assertGreaterThan(0, $taxViewId, 'tax.view permission must exist after sync.');
        $this->assertNotFalse(
            DB::table('role_has_permissions')->where('permission_id', $taxViewId)->where('role_id', $roleId)->first(),
            'Role holding reports.view must receive tax.view.',
        );

        // Idempotent second run: no duplicate grants.
        $again = app(\App\Console\Commands\SyncTaxPermissions::class)->syncCurrentSchema();
        $this->assertSame(0, $again);
        $this->assertSame(1, DB::table('role_has_permissions')
            ->where('permission_id', $taxViewId)->where('role_id', $roleId)->count());
    }
}
