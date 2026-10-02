<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\ReversalRequest;
use App\Models\ChartOfAccount;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Services\TaxReturnAggregator;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #742 T9: period summary and the generated return must share one lifecycle
 * filter — a reversal in June must not erase the May original from the
 * period list while the May return still counts it.
 */
class TaxReturnPeriodConsistencyTest extends TenantTestCase
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

    public function test_period_summary_and_return_agree_around_a_reversal(): void
    {
        $cash = ChartOfAccount::factory()->create(['code' => 'PC-CASH-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);
        $revenue = ChartOfAccount::factory()->create(['code' => 'PC-REV-'.Str::upper(Str::random(4)), 'type' => 'revenue', 'is_active' => true]);
        $gst = ChartOfAccount::factory()->create(['code' => 'PC-GST-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true]);

        Tax::query()->firstOrCreate(['code' => 'PC-GST-9'], ['name' => 'PC GST 9', 'type' => 'gst', 'rate' => 9, 'is_active' => true]);
        $ruleId = (int) Tax::query()->where('code', 'PC-GST-9')->value('id');

        $tax = 9.0;

        $entry = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: '2026-05-15',
            description: 'May sale',
            lines: [
                new PostingRequestLine(accountMappingKey: 'cash', accountId: $cash->id, debit: 109.0, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $revenue->id, debit: 0.0, credit: 100.0),
                new PostingRequestLine(accountMappingKey: 'sales_tax_payable', accountId: $gst->id, debit: 0.0, credit: $tax, taxCode: 'PC-GST-9', taxId: $ruleId),
            ],
            userId: $this->user->id,
            taxDeterminationResult: [
                'jurisdiction' => 'SG', 'tax_amount' => $tax,
                'tax_lines' => [[
                    'tax_code' => 'PC-GST-9', 'jurisdiction' => 'SG', 'taxable_base' => 100.0,
                    'tax_rate' => 9.0, 'tax_amount' => $tax, 'treatment' => 'standard_rated',
                ]],
            ],
        ));
        $this->assertTrue($entry->success, $entry->error ?? '');

        $reversal = app(PostingEngineInterface::class)->reverse(new ReversalRequest(
            journalEntryId: $entry->journalEntryId,
            reversalDate: '2026-06-10',
            reason: 'T9 consistency',
            reasonCode: 'TEST',
            actorUserId: $this->user->id,
            tenantId: tenant('id'),
        ));
        $this->assertTrue($reversal->success, $reversal->error ?? '');

        // Trigger precondition: the engine retires the original as `reversed`,
        // which is what made the old period list drop it while the return kept it.
        $this->assertSame('reversed', (string) DB::table('journal_entries')->where('id', $entry->journalEntryId)->value('lifecycle_status'));

        // Period list through the real route.
        $response = $this->getJson('/api/v1/accounting/statutory-returns/periods')->assertOk();
        $periods = collect($response->json('data'))->keyBy('period');

        $this->assertTrue($periods->has('2026-05'), 'May must remain in the period list.');
        $this->assertTrue($periods->has('2026-06'), 'June (the reversal) must be in the period list.');
        $this->assertSame(9.0, (float) $periods['2026-05']['output_tax'], 'May original must not be erased by the June reversal.');
        $this->assertSame(-9.0, (float) $periods['2026-06']['output_tax'], 'June carries the signed reversal.');

        // The return for the same periods must agree with the list.
        $aggregator = app(TaxReturnAggregator::class);
        $sum = fn (array $rows): float => round(array_sum(array_map(
            fn ($r) => (float) (is_array($r) ? $r['tax_amount'] : $r->tax_amount),
            $rows,
        )), 2);

        $this->assertSame(9.0, $sum($aggregator->aggregate('2026-05')['output']));
        $this->assertSame(-9.0, $sum($aggregator->aggregate('2026-06')['output']));
    }
}
