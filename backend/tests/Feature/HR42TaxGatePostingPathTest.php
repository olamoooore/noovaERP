<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #42 (hostile re-review HR42-01/02/05): a tax-bearing line must reach
 * the TaxGate through the real PostingEngine, and a missing determination must
 * fail the posting closed with the canonical TAX_RESULT_MISSING code — before
 * any journal entry is written.
 */
class HR42TaxGatePostingPathTest extends TenantTestCase
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
        $this->assertTenantSchemaReady([
            'journal_entries', 'journal_entry_lines', 'chart_of_accounts', 'tax_transaction_details',
        ]);

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

        $this->ar = ChartOfAccount::factory()->create([
            'code' => 'HR42-AR-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true,
        ]);
        $this->revenue = ChartOfAccount::factory()->create([
            'code' => 'HR42-REV-'.Str::upper(Str::random(4)), 'type' => 'revenue', 'is_active' => true,
        ]);
        $this->gst = ChartOfAccount::factory()->create([
            'code' => 'HR42-GST-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true,
        ]);
    }

    private function postingRequest(?array $taxDeterminationResult): PostingRequest
    {
        return new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: now()->toDateString(),
            description: 'Tax-bearing posting (HR42)',
            lines: [
                new PostingRequestLine(
                    accountMappingKey: 'accounts_receivable',
                    accountId: $this->ar->id,
                    debit: 109.00,
                    credit: 0.00,
                    description: 'AR',
                ),
                new PostingRequestLine(
                    accountMappingKey: 'sales_revenue',
                    accountId: $this->revenue->id,
                    debit: 0.00,
                    credit: 100.00,
                    description: 'Revenue',
                ),
                new PostingRequestLine(
                    accountMappingKey: 'gst_payable',
                    accountId: $this->gst->id,
                    debit: 0.00,
                    credit: 9.00,
                    description: 'GST',
                    taxCode: 'GST-STD',
                    taxId: 1,
                ),
            ],
            userId: $this->user->id,
            taxDeterminationResult: $taxDeterminationResult,
        );
    }

    public function test_tax_bearing_posting_without_determination_is_blocked_before_any_ledger_write(): void
    {
        $result = app(PostingEngineInterface::class)->post($this->postingRequest(null));

        $this->assertFalse($result->success, 'A tax-bearing posting without a determination must not succeed.');
        $this->assertStringContainsString(
            'TAX_RESULT_MISSING',
            (string) $result->error,
            'The block must use the canonical TAX_RESULT_MISSING reason code.'
        );
        $this->assertSame(0, JournalEntry::count(), 'No journal entry may be written when the TaxGate blocks.');
        $this->assertSame(0, DB::table('tax_transaction_details')->count());
    }

    public function test_tax_bearing_posting_with_a_determination_posts_and_writes_tax_details(): void
    {
        $determination = [
            'jurisdiction' => 'SG',
            'tax_amount' => 9.00,
            'tax_lines' => [[
                'tax_code' => 'GST-STD',
                'taxable_base' => 100.00,
                'tax_rate' => 9.0,
                'treatment' => 'standard',
                'tax_regime' => 'gst',
                'result_version' => 1,
                // tax_determination_id is a uuid column.
                'id' => (string) Str::uuid(),
            ]],
        ];

        $result = app(PostingEngineInterface::class)->post($this->postingRequest($determination));

        $this->assertTrue($result->success, "Positive path must post: {$result->error}");
        $this->assertSame(1, JournalEntry::count());

        $details = DB::table('tax_transaction_details')->get();
        $this->assertCount(1, $details, 'The tax-bearing line must produce tax audit details.');
        $this->assertSame('GST-STD', $details->first()->tax_code);
        $this->assertSame('SG', $details->first()->jurisdiction);
        $this->assertSame(100.00, round((float) $details->first()->taxable_amount, 2),
            'The taxable base must come from the determination, not a hardcoded rate.');
        $this->assertSame(9.00, round((float) $details->first()->tax_amount, 2));
    }
}
