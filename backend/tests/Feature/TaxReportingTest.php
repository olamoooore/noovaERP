<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\AccountMapping;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\ChartOfAccount;
use App\Models\Product;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;
use Modules\POS\Services\PosPostingService;
use Spatie\Permission\Models\Permission;
use Tests\TenantTestCase;

/**
 * MRD TAX-REQ-08 — tax reporting endpoints: journal, trail↔GL reconciliation
 * and filing tracker, verified through real HTTP against the live pipeline.
 */
class TaxReportingTest extends TenantTestCase
{
    private User $user;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['tax_transaction_details', 'statutory_return_filings', 'journal_entry_lines']);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['pos.create', 'accounting.write', 'reports.view', 'reports.manage'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            $this->user->givePermissionTo($permission);
        }

        foreach (
            [
                'cash' => ['1000-RP', 'asset'],
                'accounts_receivable' => ['1100-RP', 'asset'],
                'sales_tax_payable' => ['2200-RP', 'liability'],
                'input_tax' => ['2210-RP', 'asset'],
                'accounts_payable' => ['2000-RP', 'liability'],
                'sales_revenue' => ['3000-RP', 'revenue'],
                'general_expense' => ['4000-RP', 'expense'],
            ] as $key => [$code, $type]
        ) {
            $account = ChartOfAccount::create([
                'code' => $code,
                'name' => "RP {$key}",
                'type' => $type,
                'account_type' => $type,
                'is_active' => true,
            ]);
            AccountMapping::create(['mapping_key' => $key, 'account_id' => $account->id, 'is_active' => true]);
        }

        foreach ([['SG-GST-9', 'output'], ['SG-GST-9-IN', 'input']] as [$code, $scope]) {
            Tax::create([
                'name' => "{$code}",
                'code' => $code,
                'type' => 'gst',
                'regime' => 'GST',
                'jurisdiction' => 'SG',
                'scope' => $scope,
                'treatment' => 'standard_rated',
                'rate' => 9,
                'is_active' => true,
            ]);
        }

        $this->period = now()->format('Y-m');
    }

    private function postPosSale(): void
    {
        $product = Product::create(['name' => 'RP Product', 'sku' => 'RP-'.Str::random(6), 'is_active' => true, 'track_inventory' => false]);
        $tax = round(100 * 9 / 100, 2);

        $terminal = \Modules\POS\Models\PosTerminal::create([
            'name' => 'RP Terminal', 'code' => 'RPT-'.Str::upper(Str::random(5)), 'is_active' => true,
        ]);
        $session = \Modules\POS\Models\PosSession::create([
            'terminal_id' => $terminal->id, 'user_id' => $this->user->id,
            'session_number' => 'RPS-'.Str::upper(Str::random(5)), 'status' => 'open',
            'opening_balance' => 0, 'opened_at' => now(),
        ]);

        $order = PosOrder::create([
            'external_id' => Str::uuid()->toString(),
            'order_number' => 'RP-'.Str::random(8),
            'session_id' => $session->id,
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => $tax,
            'total' => 100 + $tax,
            'created_by' => $this->user->id,
        ]);
        PosOrderItem::create([
            'pos_order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'RP',
            'sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 9,
            'tax_amount' => $tax,
            'line_total' => 100 + $tax,
        ]);

        $entry = app(PosPostingService::class)->postPaidOrder($order->load('items.product', 'items.variant'), 109.00, 'cash');
        $this->assertNotNull($entry);
    }

    private function postVendorBill(): void
    {
        $product = Product::create(['name' => 'RP Buy', 'sku' => 'RPB-'.Str::random(6), 'is_active' => true, 'track_inventory' => false]);
        $expenseAccountId = (int) AccountMapping::where('mapping_key', 'general_expense')->value('account_id');

        $bill = Bill::create([
            'vendor_id' => Vendor::factory()->create()->id,
            'bill_number' => 'RPBILL-'.Str::random(6),
            'bill_type' => 'expense',
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 200,
            'tax_amount' => 18,
            'discount_amount' => 0,
            'total' => 218,
            'paid_amount' => 0,
            'balance' => 218,
            'created_by' => $this->user->id,
        ]);
        BillLine::create([
            'bill_id' => $bill->id,
            'product_id' => $product->id,
            'description' => 'RP bill',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 9,
            'tax_amount' => 18,
            'line_total' => 200,
            'account_id' => $expenseAccountId,
        ]);

        $result = app(\App\Services\VendorBillService::class)->postBill($bill->load('lines.product', 'vendor.addresses'));
        $this->assertTrue($result->success, "bill post failed: {$result->error}");
    }

    public function test_journal_lists_signed_trail_rows_with_totals(): void
    {
        $this->postPosSale();
        $this->postVendorBill();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/accounting/tax-report/journal?from={$this->period}&to={$this->period}");

        $response->assertOk()
            ->assertJsonPath('data.totals.output_tax', 9)
            ->assertJsonPath('data.totals.input_tax', 18)
            ->assertJsonPath('data.totals.net', -9);

        $docTypes = array_column($response->json('data.rows'), 'source_document_type');
        $this->assertContains('pos_order', $docTypes);
        $this->assertContains('bill', $docTypes);
        $this->assertCount(2, $response->json('data.by_tax_code'));
    }

    public function test_reconciliation_balances_then_detects_manual_gl_drift(): void
    {
        $this->postPosSale();
        $this->postVendorBill();

        $clean = $this->actingAs($this->user)
            ->getJson("/api/v1/accounting/tax-report/reconciliation?period={$this->period}");
        $clean->assertOk()
            ->assertJsonPath('data.balanced', true)
            ->assertJsonPath('data.total_abs_variance', 0);

        $this->assertCount(2, $clean->json('data.by_tax_code'));

        foreach ($clean->json('data.by_tax_code') as $codeRow) {
            $this->assertSame(0.0, (float) $codeRow['variance'], "per-code variance must be zero for {$codeRow['tax_code']}");
        }

        // Manual journal line on the tax control account WITHOUT tax identity —
        // the classic way the ledger silently drifts from the return trail.
        $cashAccountId = (int) AccountMapping::where('mapping_key', 'cash')->value('account_id');
        $taxAccountId = (int) AccountMapping::where('mapping_key', 'sales_tax_payable')->value('account_id');

        // Date the manual JE into the same month as the trail so the variance
        // is a true same-period drift (the recon compares per-month like the return).
        $manual = app(\App\Accounting\Contracts\PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'journal_entry',
            documentId: null,
            postingDate: now()->startOfMonth()->toDateString(),
            description: 'Manual tax account adjustment',
            lines: [
                new PostingRequestLine(accountMappingKey: 'cash', accountId: $cashAccountId, debit: 100, credit: 0),
                new PostingRequestLine(accountMappingKey: 'sales_tax_payable', accountId: $taxAccountId, debit: 0, credit: 100),
            ],
            userId: $this->user->id,
        ));
        $this->assertTrue($manual->success, "manual JE failed: {$manual->error}");

        $drifted = $this->actingAs($this->user)
            ->getJson("/api/v1/accounting/tax-report/reconciliation?period={$this->period}");
        $drifted->assertOk()
            ->assertJsonPath('data.balanced', false)
            ->assertJsonPath('data.uncoded_gl_movement.count', 1)
            ->assertJsonPath('data.uncoded_gl_movement.net', 100);

        $kinds = array_column($drifted->json('data.explanations'), 'kind');
        $this->assertContains('gl_lines_without_tax_identity', $kinds);
    }

    public function test_filing_tracker_closes_open_period_after_baseline(): void
    {
        $this->postPosSale();

        $before = $this->actingAs($this->user)->getJson('/api/v1/accounting/tax-report/filings');
        $before->assertOk();
        $open = collect($before->json('data.open_periods'))
            ->first(fn ($row) => $row['country'] === 'SG' && $row['period'] === $this->period);
        $this->assertNotNull($open, 'An unfiled period with activity must appear as open.');

        $this->actingAs($this->user)->postJson("/api/v1/accounting/statutory-returns/SG/{$this->period}/file")
            ->assertCreated()
            ->assertJsonPath('data.form', 'GST F5');

        $after = $this->actingAs($this->user)->getJson('/api/v1/accounting/tax-report/filings');
        $after->assertOk()
            ->assertJsonPath('data.filings.0.form', 'GST F5');
        $stillOpen = collect($after->json('data.open_periods'))
            ->first(fn ($row) => $row['country'] === 'SG' && $row['period'] === $this->period);
        $this->assertNull($stillOpen, 'Filed periods must leave the open list.');
    }

    public function test_my_sst02_can_be_baselined_for_the_tracker(): void
    {
        $this->postPosSale();

        $this->actingAs($this->user)->postJson("/api/v1/accounting/statutory-returns/MY/{$this->period}/file")
            ->assertCreated()
            ->assertJsonPath('data.form', 'SST-02');

        $filings = $this->actingAs($this->user)->getJson('/api/v1/accounting/tax-report/filings');
        $this->assertSame('MY', $filings->json('data.filings.0.country'));
    }
}
