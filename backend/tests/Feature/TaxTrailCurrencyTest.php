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
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #272 G-39: the tax trail carries the document currency and booking exchange
 * rate, and reversal mirrors preserve them.
 */
class TaxTrailCurrencyTest extends TenantTestCase
{
    private User $user;

    private ChartOfAccount $ar;

    private ChartOfAccount $revenue;

    private ChartOfAccount $gst;

    private int $taxRuleId;

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

        $this->ar = ChartOfAccount::factory()->create(['code' => 'FX-AR-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);
        $this->revenue = ChartOfAccount::factory()->create(['code' => 'FX-REV-'.Str::upper(Str::random(4)), 'type' => 'revenue', 'is_active' => true]);
        $this->gst = ChartOfAccount::factory()->create(['code' => 'FX-GST-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true]);

        Tax::query()->firstOrCreate(['code' => 'FX-GST-9'], [
            'name' => 'FX GST 9', 'type' => 'gst', 'rate' => 9, 'is_active' => true,
        ]);
        $this->taxRuleId = (int) Tax::query()->where('code', 'FX-GST-9')->value('id');
    }

    public function test_trail_records_document_currency_and_reversal_preserves_it(): void
    {
        $request = new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: now()->toDateString(),
            description: 'FX tax-bearing posting',
            currencyCode: 'USD',
            exchangeRate: 1.35,
            lines: [
                new PostingRequestLine(accountMappingKey: 'accounts_receivable', accountId: $this->ar->id, debit: 109.0, credit: 0.0, description: 'AR'),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $this->revenue->id, debit: 0.0, credit: 100.0, description: 'Revenue'),
                new PostingRequestLine(accountMappingKey: 'gst_payable', accountId: $this->gst->id, debit: 0.0, credit: 9.0, description: 'GST', taxCode: 'GST-STD', taxId: $this->taxRuleId),
            ],
            userId: $this->user->id,
            taxDeterminationResult: [
                'jurisdiction' => 'SG',
                'tax_amount' => 9.0,
                'tax_lines' => [[
                    'tax_code' => 'GST-STD',
                    'taxable_base' => 100.00,
                    'tax_rate' => 9.0,
                    'tax_amount' => 9.00,
                    'treatment' => 'standard_rated',
                ]],
            ],
        );

        $result = app(PostingEngineInterface::class)->post($request);
        $this->assertTrue($result->success, 'Posting must succeed: '.($result->error ?? ''));

        $detail = DB::table('tax_transaction_details')->where('journal_entry_id', $result->journalEntry->id)->first();
        $this->assertNotNull($detail);
        $this->assertSame('USD', $detail->currency_code, 'The trail must record the document currency.');
        $this->assertEqualsWithDelta(1.35, (float) $detail->exchange_rate, 0.000001, 'The trail must record the booking FX rate.');

        $reversal = app(PostingEngineInterface::class)->reverse(new ReversalRequest(
            journalEntryId: (int) $result->journalEntry->id,
            reversalDate: now()->toDateString(),
            reason: 'G-39 test reversal',
            reasonCode: 'TEST',
            actorUserId: $this->user->id,
            tenantId: tenant('id'),
        ));

        $this->assertTrue($reversal->success, 'Reversal must succeed: '.($reversal->error ?? ''));

        $mirror = DB::table('tax_transaction_details')->where('journal_entry_id', $reversal->journalEntry->id)->first();
        $this->assertNotNull($mirror, 'The reversal must mirror the trail row.');
        $this->assertSame('USD', $mirror->currency_code, 'The reversal mirror keeps the currency.');
        $this->assertEqualsWithDelta(1.35, (float) $mirror->exchange_rate, 0.000001, 'The reversal mirror keeps the FX rate.');
        $this->assertEqualsWithDelta(-9.0, (float) $mirror->tax_amount, 0.001);
    }
}