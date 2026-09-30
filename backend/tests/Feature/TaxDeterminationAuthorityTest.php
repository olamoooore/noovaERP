<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\Services\Gates\TaxGate;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Tax;
use App\Models\TaxDeterminationResult;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TaxDeterminationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #274 G-05..G-09 (Section 2): determination authority regression suite.
 *
 * Covers the supersede lifecycle, entity/actor scope, versioned rule refs,
 * the fail-closed TaxGate (status, expected version, stale snapshot, booked ==
 * determined, tax-bearing determination without line tax identity) and the
 * posting rollback when no determination line matches a taxed GL line.
 */
class TaxDeterminationAuthorityTest extends TenantTestCase
{
    private User $user;

    private ChartOfAccount $ar;

    private ChartOfAccount $revenue;

    private ChartOfAccount $gst;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['journal_entries', 'journal_entry_lines', 'chart_of_accounts', 'tax_transaction_details']);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ar = ChartOfAccount::factory()->create(['code' => 'TDA-AR-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);
        $this->revenue = ChartOfAccount::factory()->create(['code' => 'TDA-REV-'.Str::upper(Str::random(4)), 'type' => 'revenue', 'is_active' => true]);
        $this->gst = ChartOfAccount::factory()->create(['code' => 'TDA-GST-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true]);
    }

    private function lines(float $tax = 9.0, string $code = 'TDA-STD'): array
    {
        return [[
            'source_line_id' => 1,
            'base' => 100.0,
            'rate' => 9.0,
            'tax' => $tax,
            'tax_code_override' => $code,
            'treatment_override' => 'standard_rated',
        ]];
    }

    // ── G-07: supersede lifecycle ─────────────────────────────────────────

    public function test_version_bump_supersedes_the_previous_determination_row(): void
    {
        $service = app(TaxDeterminationService::class);

        [$id1] = $this->flatten($service->determineForLineSet('invoice', 91001, now()->toDateString(), $this->lines(9.0), jurisdiction: 'SG'));
        $v1 = TaxDeterminationResult::where('tenant_id', tenant('id'))->where('correlation_id', $id1)->orderByDesc('result_version')->first();

        $result2 = $service->determineForLineSet('invoice', 91001, now()->toDateString(), $this->lines(18.0), jurisdiction: 'SG');
        $v2 = TaxDeterminationResult::where('tenant_id', tenant('id'))->where('correlation_id', $id1)->orderByDesc('result_version')->first();

        $this->assertSame(2, (int) $v2->result_version);
        $this->assertSame($id1, $result2['id'], 'The correlation id must be stable across versions.');

        $v1->refresh();
        $this->assertSame('SUPERSEDED', $v1->result_status, 'A superseded version must leave the DETERMINED state.');
        $this->assertSame((int) $v2->id, (int) $v1->superseded_by, 'superseded_by must point at the replacing row.');
        $this->assertTrue($v1->isSuperseded());

        $this->assertSame(
            1,
            TaxDeterminationResult::where('tenant_id', tenant('id'))
                ->where('source_record_type', 'invoice')->where('source_record_id', 91001)
                ->current()->count(),
            'Exactly one current version may remain after a bump.'
        );
    }

    // ── G-09: entity + actor scope ────────────────────────────────────────

    public function test_determination_populates_entity_and_actor_scope(): void
    {
        request()->attributes->set('legal_entity_id', 7);
        $this->actingAs($this->user);

        $result = app(TaxDeterminationService::class)
            ->determineForLineSet('invoice', 91002, now()->toDateString(), $this->lines());

        $row = TaxDeterminationResult::where('tenant_id', tenant('id'))->where('correlation_id', $result['id'])->firstOrFail();

        $this->assertSame(
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'legal_entity:7')->toString(),
            $row->entity_id,
            'entity_id must carry the deterministic legal-entity uuid.'
        );
        $this->assertSame(
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'actor:'.$this->user->id)->toString(),
            $row->determined_by,
            'determined_by must carry the deterministic actor uuid.'
        );
    }

    // ── G-08: rule versions in refs ───────────────────────────────────────

    public function test_rule_refs_bind_exact_rule_versions(): void
    {
        $rule = Tax::query()->create([
            'name' => 'Authority test rule', 'code' => 'TDA-RULE-'.Str::upper(Str::random(4)),
            'type' => 'gst', 'rate' => 9, 'is_active' => true,
        ]);
        $rule->update(['version' => 3]);

        $result = app(TaxDeterminationService::class)->determineForLineSet(
            'invoice', 91003, now()->toDateString(),
            [[
                'source_line_id' => 1,
                'base' => 100.0,
                'rate' => 9.0,
                'tax' => 9.0,
                'tax_code_override' => 'TDA-VER-CODE',
                'treatment_override' => 'standard_rated',
                'tax_id_override' => $rule->id,
            ]],
            jurisdiction: 'SG',
        );

        $row = TaxDeterminationResult::where('tenant_id', tenant('id'))->where('correlation_id', $result['id'])->firstOrFail();
        $refs = collect($row->tax_rule_refs);

        $ref = $refs->firstWhere('tax_rule_id', $rule->id);
        $this->assertNotNull($ref, 'Rule refs must include the resolved rule.');
        $this->assertSame(3, (int) $ref['rule_version'], 'Refs must bind the exact rule version.');
        $this->assertSame('TDA-VER-CODE', $ref['tax_code']);
    }

    // ── G-08: gate fail-closed matrix ─────────────────────────────────────

    public function test_gate_blocks_tax_bearing_determination_without_line_tax_identity(): void
    {
        $result = (new TaxGate)->evaluate([
            'posting_lines' => [
                ['debit' => 100.0, 'credit' => 0.0, 'tax_code' => null, 'tax_id' => null],
            ],
            'tax_determination_result' => [
                'jurisdiction' => 'SG',
                'tax_amount' => 9.0,
                'tax_lines' => [['tax_code' => 'TDA-STD', 'tax_amount' => 9.0]],
            ],
        ]);

        $this->assertTrue($result->result === \App\Accounting\Enums\GateResult::BLOCK);
        $this->assertSame('TAX_RESULT_INVALIDATED', $result->reasonCode);
    }

    public function test_gate_blocks_superseded_status(): void
    {
        $result = (new TaxGate)->evaluate([
            'posting_lines' => [['debit' => 0.0, 'credit' => 9.0, 'tax_code' => 'TDA-STD', 'tax_id' => 1]],
            'tax_determination_result' => [
                'jurisdiction' => 'SG', 'tax_amount' => 9.0, 'result_status' => 'SUPERSEDED',
                'tax_lines' => [['tax_code' => 'TDA-STD', 'tax_amount' => 9.0]],
            ],
        ]);

        $this->assertTrue($result->result === \App\Accounting\Enums\GateResult::BLOCK);
        $this->assertSame('TAX_RESULT_STALE', $result->reasonCode);
    }

    public function test_gate_blocks_expected_version_mismatch(): void
    {
        $result = (new TaxGate)->evaluate([
            'posting_lines' => [['debit' => 0.0, 'credit' => 9.0, 'tax_code' => 'TDA-STD', 'tax_id' => 1]],
            'expected_tax_result_version' => 2,
            'tax_determination_result' => [
                'jurisdiction' => 'SG', 'tax_amount' => 9.0, 'result_version' => 1,
                'tax_lines' => [['tax_code' => 'TDA-STD', 'tax_amount' => 9.0]],
            ],
        ]);

        $this->assertTrue($result->result === \App\Accounting\Enums\GateResult::BLOCK);
        $this->assertSame('TAX_RESULT_STALE', $result->reasonCode);
    }

    public function test_gate_blocks_stale_snapshot_after_document_redetermined(): void
    {
        $service = app(TaxDeterminationService::class);
        $v1 = $service->determineForLineSet('invoice', 91004, now()->toDateString(), $this->lines(9.0), jurisdiction: 'SG');
        $service->determineForLineSet('invoice', 91004, now()->toDateString(), $this->lines(18.0), jurisdiction: 'SG');

        $result = (new TaxGate)->evaluate([
            'tenant_id' => tenant('id'),
            'record_type' => 'invoice',
            'record_id' => '91004',
            'posting_lines' => [['debit' => 0.0, 'credit' => 9.0, 'tax_code' => 'TDA-STD', 'tax_id' => 1]],
            'tax_determination_result' => $v1,
        ]);

        $this->assertTrue($result->result === \App\Accounting\Enums\GateResult::BLOCK, 'A v1 snapshot must not post once v2 is current.');
        $this->assertSame('TAX_RESULT_STALE', $result->reasonCode);
    }

    public function test_gate_blocks_booked_determined_mismatch(): void
    {
        $result = (new TaxGate)->evaluate([
            'posting_lines' => [['debit' => 0.0, 'credit' => 9.0, 'tax_code' => 'TDA-STD', 'tax_id' => 1]],
            'tax_determination_result' => [
                'jurisdiction' => 'SG', 'tax_amount' => 12.0,
                'tax_lines' => [['tax_code' => 'TDA-STD', 'tax_amount' => 12.0]],
            ],
        ]);

        $this->assertTrue($result->result === \App\Accounting\Enums\GateResult::BLOCK);
        $this->assertSame('TAX_TOTAL_INCONSISTENT', $result->reasonCode);
    }

    public function test_gate_allows_reverse_charge_paired_self_assessment(): void
    {
        $result = (new TaxGate)->evaluate([
            'posting_lines' => [
                ['debit' => 18.0, 'credit' => 0.0, 'tax_code' => 'TDA-RC', 'tax_id' => 2],
                ['debit' => 0.0, 'credit' => 18.0, 'tax_code' => 'TDA-RC', 'tax_id' => 2],
            ],
            'tax_determination_result' => [
                'jurisdiction' => 'SG', 'tax_amount' => 18.0,
                'tax_lines' => [['tax_code' => 'TDA-RC', 'tax_amount' => 18.0, 'treatment' => 'reverse_charge']],
            ],
        ]);

        $this->assertSame(\App\Accounting\Enums\GateResult::PASS, $result->result, 'RC posts the determined tax twice (reclaim + payable) by design.');
    }

    // ── G-05..G-09: posting must die, not write zero-valued evidence ──────

    public function test_posting_rolls_back_when_no_determination_line_matches_a_taxed_gl_line(): void
    {
        Tax::query()->create(['name' => 'Gone rule', 'code' => 'TDA-GONE', 'type' => 'gst', 'rate' => 9, 'is_active' => true]);

        $request = new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: now()->toDateString(),
            description: 'No determination line matches GONE-CODE',
            lines: [
                new PostingRequestLine(accountMappingKey: 'accounts_receivable', accountId: $this->ar->id, debit: 109.0, credit: 0.0, description: 'AR'),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $this->revenue->id, debit: 0.0, credit: 100.0, description: 'Revenue'),
                new PostingRequestLine(accountMappingKey: 'gst_payable', accountId: $this->gst->id, debit: 0.0, credit: 9.0, description: 'GST', taxCode: 'GONE-CODE', taxId: null),
            ],
            userId: $this->user->id,
            // Amounts agree (9.00) so the gate passes; the CODE does not match.
            taxDeterminationResult: [
                'jurisdiction' => 'SG',
                'tax_amount' => 9.0,
                'tax_lines' => [['tax_code' => 'OTHER-CODE', 'tax_amount' => 9.0, 'taxable_base' => 100.0]],
            ],
        );

        $result = app(PostingEngineInterface::class)->post($request);

        $this->assertFalse($result->success, 'Unresolvable tax evidence must fail the posting.');
        $this->assertStringContainsString('TAX_RESULT_MISSING', (string) $result->error);
        $this->assertSame(0, JournalEntry::count(), 'The journal entry must be rolled back, not left ledger-only.');
        $this->assertSame(0, DB::table('tax_transaction_details')->count(), 'No zero-valued trail row may be written.');
    }

    /**
     * determineForLineSet returns [id, version] in element 0 of the result;
     * normalise to that tuple.
     *
     * @return array{0: string, 1: int}
     */
    private function flatten(array $result): array
    {
        return [$result['id'], (int) $result['result_version']];
    }
}
