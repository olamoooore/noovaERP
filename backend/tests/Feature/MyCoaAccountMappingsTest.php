<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\Services\AccountMappingService;
use App\Models\AccountMapping;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #763 (A3) — the MY CoA template created accounts but no mappings, so
 * the first posting lazily auto-seeded generic 1100/2200/4100 accounts and
 * every posting went there; the tenant's chosen CoA stayed decorative.
 *
 * After applying the template: postings must hit the template's accounts
 * (124000 AR, 410000 revenue, 221300 SST control), and a role the template
 * does not cover must be a setup error, never a new generic account.
 */
class MyCoaAccountMappingsTest extends TenantTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['chart_of_accounts', 'account_mappings', 'settings', 'journal_entries', 'journal_entry_lines']);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Hermetic: the shared test DB carries a lazily-seeded generic mapping
        // set from other suites; this test is about what the template wires.
        DB::table('account_mappings')->delete();
        DB::table('settings')->where('key', 'accounting.coa_template')->delete();
    }

    private function applyTemplate(): void
    {
        (new \Database\Seeders\MalaysiaCountrySeeder)->run();
    }

    private function accountId(string $code): int
    {
        return (int) DB::table('chart_of_accounts')->where('code', $code)->value('id');
    }

    public function test_the_template_wires_its_accounts_to_the_posting_roles(): void
    {
        $this->applyTemplate();

        foreach ([
            'accounts_receivable' => '124000',
            'accounts_payable' => '221100',
            'sales_tax_payable' => '221300',
            'sales_revenue' => '410000',
            'cogs' => '510100',
            'general_expense' => '520000',
            'bank' => '120001',
            'cash' => '121001',
        ] as $role => $code) {
            $mapped = AccountMapping::where('mapping_key', $role)->whereNull('document_type')->first();
            $this->assertNotNull($mapped, "role {$role} must be mapped by the template");
            $this->assertSame($this->accountId($code), (int) $mapped->account_id, "role {$role} must point at {$code}");
        }
    }

    public function test_a_posting_uses_the_template_accounts(): void
    {
        $this->applyTemplate();

        $result = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: now()->toDateString(),
            description: '#763 template posting',
            lines: [
                new PostingRequestLine(accountMappingKey: 'accounts_receivable', accountId: null, debit: 109.0, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: null, debit: 0.0, credit: 100.0),
                new PostingRequestLine(accountMappingKey: 'sales_tax_payable', accountId: null, debit: 0.0, credit: 9.0),
            ],
            userId: $this->admin->id,
        ));

        $this->assertTrue($result->success, json_encode([$result->error ?? null, $result->message ?? null]));

        $lines = DB::table('journal_entry_lines')->where('journal_entry_id', $result->journalEntry->id)->get();

        $this->assertSame(
            109.0,
            (float) $lines->firstWhere('account_id', $this->accountId('124000'))?->debit,
            'the receivable must post to the template account 124000'
        );
        $this->assertSame(100.0, (float) $lines->firstWhere('account_id', $this->accountId('410000'))?->credit);
        $this->assertSame(9.0, (float) $lines->firstWhere('account_id', $this->accountId('221300'))?->credit);

        // Only template accounts may be referenced by the posting roles.
        $generated = AccountMapping::whereIn('mapping_key', ['accounts_receivable', 'sales_revenue', 'sales_tax_payable'])
            ->pluck('account_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
        $templateIds = [$this->accountId('124000'), $this->accountId('410000'), $this->accountId('221300')];
        sort($templateIds);
        $this->assertSame($templateIds, $generated, 'every posting role must resolve to the template accounts');
    }

    public function test_a_role_the_template_does_not_cover_is_a_setup_error_not_a_generic_account(): void
    {
        $this->applyTemplate();

        $this->assertNull(
            AccountMapping::where('mapping_key', 'inventory')->first(),
            'the template must not map a role it has no account for'
        );

        // No generic 1200 exists in this template; simulate the environment
        // where the fallback account is absent so the guard is exercised.
        DB::table('chart_of_accounts')->where('code', '1200')->update(['code' => 'X-1200-'.Str::upper(Str::random(4))]);

        try {
            app(AccountMappingService::class)->resolve('inventory');
            $this->fail('resolve must throw for a role the template does not cover');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("mapping key 'inventory'", $e->getMessage());
        }

        $this->assertNull(
            DB::table('chart_of_accounts')->where('code', '1200')->first(),
            'the uncovered role must not create the generic inventory account'
        );
    }
}
