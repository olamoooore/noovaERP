<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\Accounting\AccountingFinding;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AssuranceBlockingPolicySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #66 (hostile re-review HR66-03): real PostingEngine-path acceptance for
 * the assurance baseline.
 *
 *   - a CRITICAL unresolved finding matching the source document blocks the
 *     posting through the real path (ASSURANCE_BLOCKED), with zero mutation:
 *     no journal entry, no lines, no link, no outbox artifact;
 *   - once the finding is resolved the same posting succeeds;
 *   - a HIGH finding (WARNING_ONLY baseline) does not block.
 */
class HR66AssurancePostingPathTest extends TenantTestCase
{
    private string $tenantId;
    private User $user;
    private ChartOfAccount $ar;
    private ChartOfAccount $revenue;

    private const DOCUMENT_REFERENCE = 'HR66-INV-1';

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = (string) $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->assertTenantSchemaReady([
            'journal_entries', 'journal_entry_lines', 'chart_of_accounts',
            'accounting_findings', 'accounting_outbox_events',
        ]);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Central assurance policy baseline (BLOCK CRITICAL / WARNING_ONLY HIGH).
        $this->seed(AssuranceBlockingPolicySeeder::class);

        $this->ar = ChartOfAccount::factory()->create([
            'code' => 'HR66-AR-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true,
        ]);
        $this->revenue = ChartOfAccount::factory()->create([
            'code' => 'HR66-REV-'.Str::upper(Str::random(4)), 'type' => 'revenue', 'is_active' => true,
        ]);
    }

    private function finding(string $severity, string $status = 'open'): AccountingFinding
    {
        return AccountingFinding::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'finding_type' => 'control_weakness',
            'severity' => $severity,
            'status' => $status,
            'entity_id' => 'hr66-entity',
            'description' => 'HR66 assurance acceptance fixture',
            'source_type' => 'invoice',
            // Numeric document ids cannot live in the uuid source_id column;
            // document findings bind via reference_number.
            'reference_number' => self::DOCUMENT_REFERENCE,
            'title' => 'HR66 fixture finding',
        ]);
    }

    private function postViaEngine(): \App\Accounting\DTOs\PostingResult
    {
        return app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: now()->toDateString(),
            description: 'HR66 assurance acceptance posting',
            reference: self::DOCUMENT_REFERENCE,
            lines: [
                new PostingRequestLine('accounts_receivable', $this->ar->id, 100.00, 0.00, 'AR'),
                new PostingRequestLine('sales_revenue', $this->revenue->id, 0.00, 100.00, 'Revenue'),
            ],
            userId: $this->user->id,
        ));
    }

    public function test_critical_finding_blocks_the_real_posting_path_with_zero_mutation(): void
    {
        $this->finding('critical');

        $result = $this->postViaEngine();

        $this->assertFalse($result->success, 'A critical unresolved finding must block the posting.');
        $this->assertStringContainsString('ASSURANCE_BLOCKED', (string) $result->error);

        // Zero-mutation proof.
        $this->assertSame(0, JournalEntry::count(), 'No journal entry may be created.');
        $this->assertSame(0, DB::table('journal_entry_lines')->count(), 'No journal entry line may be created.');
        $this->assertSame(0, DB::table('journal_entry_links')->count(), 'No document link may be created.');
        $this->assertSame(0, DB::table('accounting_outbox_events')->count(), 'No success outbox artifact may be emitted.');
    }

    public function test_resolving_the_finding_allows_the_same_posting(): void
    {
        $finding = $this->finding('critical');

        $this->assertFalse($this->postViaEngine()->success);

        $finding->update(['status' => 'resolved', 'resolved_at' => now()]);

        $result = $this->postViaEngine();
        $this->assertTrue($result->success, "Resolved finding must allow the posting: {$result->error}");
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_high_finding_does_not_block_under_the_warning_only_baseline(): void
    {
        $this->finding('high');

        $result = $this->postViaEngine();

        $this->assertTrue($result->success, "HIGH findings are WARNING_ONLY in the baseline and must not block: {$result->error}");
        $this->assertSame(1, JournalEntry::count());
    }
}
