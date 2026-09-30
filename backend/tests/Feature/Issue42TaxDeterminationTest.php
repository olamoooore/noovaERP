<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\PostingEngine;
use App\Models\ChartOfAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue #42 — PostingEngine writes tax_transaction_details
 * using the canonical TaxDeterminationResult, not a hardcoded rate.
 */
class Issue42TaxDeterminationTest extends TenantTestCase
{
    protected string $tenantUuid;

    private int $taxRuleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        // journal_entry_lines.tax_id is an FK — the tests below reference a real rule.
        \App\Models\Tax::query()->firstOrCreate(['code' => 'TAXDET-RULE'], [
            'name' => 'Tax determination rule',
            'type' => 'gst',
            'rate' => 9,
            'is_active' => true,
        ]);
        $this->taxRuleId = \App\Models\Tax::query()->where('code', 'TAXDET-RULE')->value('id');

        // Seed permissions required for posting (accounting.write gate).
        // Note: This intentionally uses the minimal seeder so the test isn't
        // affected by future additions to RolePermissionSeeder.
        \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => 'accounting.write', 'guard_name' => 'web']
        );
    }

    private function createAccount(string $code, string $type): ChartOfAccount
    {
        return ChartOfAccount::create([
            'code' => $code,
            'name' => "Test {$code}",
            'type' => $type,
            'account_type' => $type,
            'is_active' => true,
        ]);
    }

    private function makeTaxLineRequest(string $taxCode, ?array $taxDeterminationResult, float $amount = 90.0): PostingRequest
    {
        $debit = $this->createAccount('TX-D-'.uniqid(), 'asset');
        $credit = $this->createAccount('TX-C-'.uniqid(), 'liability');

        $user = User::factory()->create();
        $user->givePermissionTo('accounting.write');

        return new PostingRequest(
            documentType: 'invoice',
            documentId: rand(1, 999999),
            description: 'Tax determination test',
            postingDate: now()->toDateString(),
            lines: [
                new PostingRequestLine(
                    accountMappingKey: 'tax_input',
                    accountId: $debit->id,
                    debit: $amount,
                    credit: 0,
                    description: 'GST 9% input tax',
                ),
                new PostingRequestLine(
                    accountMappingKey: 'tax_input',
                    accountId: $credit->id,
                    debit: 0,
                    credit: $amount,
                    description: 'GST 9% input tax (offset)',
                    taxCode: $taxCode,
                    taxId: $this->taxRuleId,
                ),
            ],
            userId: $user->id,
            taxDeterminationResult: $taxDeterminationResult,
        );
    }

    public function test_tax_detail_persists_persisted_rate_and_taxable_base_from_determination(): void
    {
        // Singapore GST at 9% — non-10% rate.
        $taxDetermination = [
            'id' => (string) Str::uuid(),
            'result_version' => 3,
            'jurisdiction' => 'SG',
            'tax_regime' => 'GST',
            'result_status' => 'DETERMINED',
            'tax_rate' => 0.09,
            'taxable_base' => 1000.00,
            'tax_amount' => 90.00,
            'treatment' => 'standard_rated',
            'tax_lines' => [
                ['tax_code' => 'SG-GST-9', 'taxable_base' => 1000.00, 'tax_rate' => 0.09, 'treatment' => 'standard_rated'],
            ],
        ];

        $request = $this->makeTaxLineRequest('SG-GST-9', $taxDetermination);
        $result = app(PostingEngine::class)->post($request);

        $this->assertTrue($result->success, 'Posting failed: '.$result->error);

        $detail = DB::table('tax_transaction_details')->where('journal_entry_id', $result->journalEntry->id)->first();
        $this->assertNotNull($detail);
        $this->assertSame(1000.00, (float) $detail->taxable_amount, 'Taxable base must come from determination, not division by rate.');
        $this->assertSame('0.090000', (string) $detail->tax_rate);
        $this->assertSame('SG', $detail->jurisdiction);
        $this->assertSame('standard_rated', $detail->tax_treatment);
        $this->assertSame('GST', $detail->tax_regime);
        $this->assertSame(3, (int) $detail->result_version);
        $this->assertSame($taxDetermination['id'], $detail->tax_determination_id);
    }

    public function test_zero_rated_treatment_is_preserved(): void
    {
        $taxDetermination = [
            'id' => (string) Str::uuid(),
            'result_version' => 1,
            'jurisdiction' => 'MY',
            'tax_regime' => 'SST',
            'result_status' => 'DETERMINED',
            'tax_rate' => 0.0,
            'taxable_base' => 500.00,
            'tax_amount' => 0.0,
            'treatment' => 'zero_rated',
            'tax_lines' => [
                ['tax_code' => 'MY-SST-0', 'taxable_base' => 500.00, 'tax_rate' => 0.0, 'treatment' => 'zero_rated'],
            ],
        ];

        $request = $this->makeTaxLineRequest('MY-SST-0', $taxDetermination, 0.0);
        $result = app(PostingEngine::class)->post($request);

        $this->assertTrue($result->success);
        $detail = DB::table('tax_transaction_details')->where('journal_entry_id', $result->journalEntry->id)->first();
        $this->assertNotNull($detail);
        $this->assertSame(500.00, (float) $detail->taxable_amount, 'Zero-rated must preserve the taxable base.');
        $this->assertSame('zero_rated', $detail->tax_treatment);
        $this->assertSame('0.000000', (string) $detail->tax_rate);
    }
}
