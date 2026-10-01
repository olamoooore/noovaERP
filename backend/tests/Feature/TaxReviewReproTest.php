<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\StatutoryReturnFiling;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\TaxDeterminationService;
use App\Services\TaxEngineService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Singapore\Services\GstF5ReturnService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Ultra-hostile review repro suite. Every test asserts the CORRECT behaviour;
 * a failure confirms a defect. Goes through real services / the real router.
 */
class TaxReviewReproTest extends TenantTestCase
{
    private User $admin;

    private User $viewer;

    private string $tenantId;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => $this->tenantId]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        foreach (['Viewer', 'Admin', 'Accountant'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        $this->admin->assignRole('Admin');
        $this->viewer = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        $this->viewer->assignRole('Viewer');
        foreach ([[$this->admin, 'admin'], [$this->viewer, 'viewer']] as [$u, $role]) {
            DB::table('user_company_access')->insert([
                'user_id' => $u->id, 'tenant_id' => $tenant->id, 'role' => $role,
                'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Auth::login($this->admin);

        foreach ([
            'cash' => ['1000-RV', 'asset'], 'accounts_receivable' => ['1100-RV', 'asset'],
            'sales_tax_payable' => ['2200-RV', 'liability'], 'input_tax' => ['2210-RV', 'asset'],
            'accounts_payable' => ['2000-RV', 'liability'], 'sales_revenue' => ['3000-RV', 'revenue'],
            'general_expense' => ['4000-RV', 'expense'], 'cogs' => ['5000-RV', 'expense'],
            'inventory' => ['1200-RV', 'asset'], 'shipping_revenue' => ['3100-RV', 'revenue'],
        ] as $key => [$code, $type]) {
            $a = ChartOfAccount::create(['code' => $code, 'name' => "RV {$key}", 'type' => $type, 'account_type' => $type, 'is_active' => true]);
            AccountMapping::create(['mapping_key' => $key, 'account_id' => $a->id, 'is_active' => true]);
        }
        $this->period = now()->format('Y-m');
    }

    private function rule(string $code, float $rate, string $treatment = 'standard_rated', string $scope = 'both', array $extra = []): Tax
    {
        return Tax::create(array_merge([
            'name' => $code, 'code' => $code, 'type' => 'gst', 'regime' => 'GST', 'jurisdiction' => 'SG',
            'scope' => $scope, 'treatment' => $treatment, 'rate' => $rate, 'is_active' => true,
        ], $extra));
    }

    private function product(array $extra = []): Product
    {
        return Product::create(array_merge(['name' => 'RV P', 'sku' => 'RV-'.Str::random(8), 'is_active' => true, 'track_inventory' => false], $extra));
    }

    /** @param array<int, array{amount:float, rate:float, product?:Product}> $lines */
    private function draftInvoice(array $lines, float $headerDiscount = 0.0): Invoice
    {
        $customer = Customer::create(['name' => 'RV Cust '.Str::random(4), 'email' => Str::random(6).'@example.test', 'is_active' => true]);
        $sub = 0.0;
        $tax = 0.0;
        foreach ($lines as $l) {
            $t = round($l['amount'] * $l['rate'] / 100, 2);
            $sub += $l['amount'];
            $tax += $t;
        }
        $ratio = $headerDiscount > 0 ? max($sub - $headerDiscount, 0) / $sub : 1;
        $hdrTax = $headerDiscount > 0 ? round($tax * $ratio, 2) : $tax;
        $invoice = Invoice::create([
            'customer_id' => $customer->id, 'invoice_number' => 'INV-'.Str::random(8),
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft', 'lifecycle_status' => 'draft', 'subtotal' => $sub, 'tax_amount' => $hdrTax,
            'discount_amount' => $headerDiscount, 'total' => $sub - $headerDiscount + $hdrTax,
            'paid_amount' => 0, 'balance' => $sub - $headerDiscount + $hdrTax, 'created_by' => $this->admin->id,
        ]);
        foreach ($lines as $l) {
            $t = round($l['amount'] * $l['rate'] / 100, 2);
            InvoiceLine::create([
                'invoice_id' => $invoice->id, 'product_id' => ($l['product'] ?? $this->product())->id,
                'description' => 'RV line', 'quantity' => 1, 'unit_price' => $l['amount'], 'tax_rate' => $l['rate'],
                'tax_inclusive' => false, 'discount_amount' => 0, 'line_total' => $l['amount'] + $t, 'tax_amount' => $t,
            ]);
        }

        return $invoice;
    }

    // ── R-01: zero-rated / exempt supplies must reach F5 boxes 2/3/4 ─────────
    public function test_r01_zero_rated_supply_reaches_f5_box_2(): void
    {
        $this->rule('SG-GST-9', 9);
        $invoice = $this->draftInvoice([['amount' => 100, 'rate' => 9], ['amount' => 200, 'rate' => 0]]);
        app(InvoiceService::class)->postInvoice($invoice);

        $f5 = app(GstF5ReturnService::class)->generate($this->period);
        fwrite(STDERR, "\n[R01] F5 boxes: ".json_encode($f5['boxes'])."\n");
        fwrite(STDERR, '[R01] ttd rows: '.json_encode(DB::table('tax_transaction_details')->get(['tax_code', 'taxable_amount', 'tax_amount', 'tax_treatment'])->all())."\n");
        $this->assertSame(100.0, $f5['boxes']['box_1_standard_rated_supplies']);
        $this->assertSame(200.0, $f5['boxes']['box_2_zero_rated_supplies'], 'Zero-rated supply (200) must appear in F5 Box 2');
    }

    // ── R-02: header discount — taxable base and tax must be consistent ───────
    public function test_r02_header_discount_keeps_base_and_tax_consistent(): void
    {
        $this->rule('SG-GST-9', 9);
        $invoice = $this->draftInvoice([['amount' => 100, 'rate' => 9]], headerDiscount: 20.0);
        app(InvoiceService::class)->postInvoice($invoice);

        $row = DB::table('tax_transaction_details')->first();
        fwrite(STDERR, "\n[R02] header tax=".$invoice->fresh()->tax_amount.' ttd='.json_encode($row)."\n");
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta((float) $row->taxable_amount * 0.09, (float) $row->tax_amount, 0.02, 'taxable_amount x 9% must equal tax_amount');
    }

    // ── R-03: cross-tenant determination collision ───────────────────────────
    public function test_r03_determination_is_tenant_isolated(): void
    {
        $svc = app(TaxDeterminationService::class);
        $line = [['source_line_id' => 7, 'base' => 100.0, 'rate' => 9.0, 'tax' => 9.0, 'tax_code_override' => 'TENANT-A-SECRET']];
        $svc->determineForLineSet('invoice', 42, now()->toDateString(), $line, 'output', 'SG');

        $tenantB = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenantB->id);

        $leak = $svc->latestDeterminationLines('invoice', 42);
        $second = $svc->determineForLineSet('invoice', 42, now()->toDateString(), [['source_line_id' => 1, 'base' => 10.0, 'rate' => 0.0, 'tax' => 0.0]], 'output', 'SG');
        fwrite(STDERR, "\n[R03] tenant B sees: ".json_encode(array_map(fn ($l) => $l['tax_code'] ?? null, $leak)).' ; tenant B first version='.$second['result_version']."\n");
        $this->assertSame([], $leak, "Tenant B must not read tenant A's determination");
        $this->assertSame(1, $second['result_version'], "Tenant B's first determination must be version 1");
    }

    // ── R-04: idempotent determination ───────────────────────────────────────
    public function test_r04_same_input_returns_same_version(): void
    {
        $svc = app(TaxDeterminationService::class);
        $args = ['invoice', 9001, now()->toDateString(), [['source_line_id' => 1, 'base' => 100.0, 'rate' => 9.0, 'tax' => 9.0]], 'output', 'SG'];
        $a = $svc->determineForLineSet(...$args);
        $b = $svc->determineForLineSet(...$args);
        fwrite(STDERR, "\n[R04] versions: {$a['result_version']} then {$b['result_version']}; rows=".DB::table('tax_determination_results')->where('source_record_id', 9001)->count()."\n");
        $this->assertSame($a['result_version'], $b['result_version'], 'Same request + same input must return same result version (HO-01)');
    }

    // ── R-05: posting after a filed period + refile overwrites baseline ───────
    public function test_r05_refiling_does_not_destroy_filed_baseline(): void
    {
        $this->rule('SG-GST-9', 9);
        app(InvoiceService::class)->postInvoice($this->draftInvoice([['amount' => 100, 'rate' => 9]]));

        $this->actingAs($this->admin)->postJson("/api/v1/accounting/statutory-returns/SG/{$this->period}/file")->assertStatus(201);
        $first = StatutoryReturnFiling::first();
        $filedTax = $first->boxes['box_6_output_tax_due'];

        // G-15 (#274): filing LOCKS the period — back-posting into it must be
        // blocked (amendments belong in open periods).
        $blocked = false;
        try {
            app(InvoiceService::class)->postInvoice($this->draftInvoice([['amount' => 100, 'rate' => 9]]));
        } catch (\Exception $e) {
            $blocked = str_contains($e->getMessage(), 'TAX_PERIOD_LOCKED');
        }
        fwrite(STDERR, "\n[R05] back-post after filing blocked=".json_encode($blocked)."\n");
        $this->assertTrue($blocked, 'A filed period must block back-posting.');

        // Re-filing still appends/serves the immutable baseline.
        $this->actingAs($this->admin)->postJson("/api/v1/accounting/statutory-returns/SG/{$this->period}/file")->assertStatus(201);

        $after = StatutoryReturnFiling::first();
        fwrite(STDERR, "\n[R05] rows=".StatutoryReturnFiling::count()." filed box6 initially={$filedTax} after refile=".$after->boxes['box_6_output_tax_due']."\n");
        $this->assertSame($filedTax, $after->boxes['box_6_output_tax_due'], 'Re-filing must not overwrite the originally filed snapshot (F7 baseline)');
    }

    // ── R-06: /taxes authorization ───────────────────────────────────────────
    public function test_r06_viewer_cannot_write_tax_rules(): void
    {
        $tax = $this->rule('SG-GST-9', 9);
        $create = $this->actingAs($this->viewer)->postJson('/api/v1/taxes', ['name' => 'Evil', 'rate' => 0, 'type' => 'vat']);
        $update = $this->actingAs($this->viewer)->putJson("/api/v1/taxes/{$tax->id}", ['name' => 'Hacked', 'is_default' => true]);
        $delete = $this->actingAs($this->viewer)->deleteJson("/api/v1/taxes/{$tax->id}");
        fwrite(STDERR, "\n[R06] viewer POST={$create->status()} PUT={$update->status()} DELETE={$delete->status()}\n");
        $this->assertSame(403, $create->status());
        $this->assertSame(403, $update->status());
        $this->assertSame(403, $delete->status());
    }

    // ── R-07: versioning guard ───────────────────────────────────────────────
    public function test_r07a_noop_edit_of_live_rule_does_not_spawn_version(): void
    {
        $tax = $this->rule('SG-GST-9', 9, extra: ['effective_from' => now()->subYear()->toDateString()]);
        $resp = $this->actingAs($this->admin)->putJson("/api/v1/taxes/{$tax->id}", ['name' => 'Renamed only', 'rate' => 9]);
        fwrite(STDERR, "\n[R07a] status={$resp->status()} taxes rows=".Tax::count().' versioned='.json_encode($resp->json('versioned'))."\n");
        $this->assertSame(1, Tax::count(), 'Renaming a rule without changing identity must not create a new version');
    }

    public function test_r07b_rate_change_must_not_apply_retroactively(): void
    {
        $tax = $this->rule('SG-GST-9', 9, extra: ['effective_from' => '2026-01-01']);
        $resp = $this->actingAs($this->admin)->putJson("/api/v1/taxes/{$tax->id}", ['rate' => 10, 'effective_from' => '2026-01-01']);
        $engine = app(TaxEngineService::class);
        $past = $engine->resolve(null, 'SG', 'output', null, '2026-03-01');
        fwrite(STDERR, "\n[R07b] status={$resp->status()} rate for 2026-03-01 resolves to {$past['rate']}; rows=".json_encode(Tax::orderBy('id')->get(['id', 'rate', 'effective_from', 'effective_to'])->toArray())."\n");
        $this->assertEqualsWithDelta(9.0, (float) $past['rate'], 0.001, 'A document dated 2026-03-01 must still resolve the 9% that was live then');
    }

    // ── R-08: engine resolution semantics ────────────────────────────────────
    public function test_r08a_product_tax_mode_none_opts_out_of_default_tax(): void
    {
        $tax = $this->rule('SG-GST-9', 9, extra: ['is_default' => true]);
        \App\Models\Setting::setValue('tax', ['enabled' => true, 'default_tax_id' => $tax->id]);
        $p = $this->product(['tax_mode' => 'none']);
        $r = app(TaxEngineService::class)->resolve($p, 'SG', 'output');
        fwrite(STDERR, "\n[R08a] tax_mode=none product resolves rate={$r['rate']} source={$r['source']}\n");
        $this->assertSame(0.0, (float) $r['rate']);
    }

    public function test_r08b_output_only_product_tax_not_applied_to_purchases(): void
    {
        $out = $this->rule('SG-GST-9-OUT', 9, scope: 'output');
        $p = $this->product(['tax_id' => $out->id]);
        $r = app(TaxEngineService::class)->resolve($p, 'SG', 'input');
        fwrite(STDERR, "\n[R08b] output-scoped product tax on input direction: rate={$r['rate']} code={$r['tax_code']}\n");
        $this->assertNotSame('SG-GST-9-OUT', $r['tax_code']);
    }

    public function test_r08c_negative_amount_keeps_tax(): void
    {
        $c = TaxEngineService::calculate(-100.0, 9.0, false);
        fwrite(STDERR, "\n[R08c] calculate(-100,9,excl)=".json_encode($c)."\n");
        $this->assertSame(-9.0, $c['tax']);
    }

    public function test_r08d_multiple_active_rules_resolve_deterministically_by_priority(): void
    {
        $this->rule('SG-ZERO', 0, 'zero_rated');
        $this->rule('SG-STD', 9, 'standard_rated');
        $r = app(TaxEngineService::class)->resolve($this->product(), 'SG', 'output');
        fwrite(STDERR, "\n[R08d] two active rules, no default: engine picked {$r['tax_code']} ({$r['rate']}%)\n");
        $this->assertSame('SG-STD', $r['tax_code'], 'Standard rate should win over zero-rate when no default/priority configured — or resolution should refuse to guess');
    }

    // ── R-09: reversal must not leave a false reconciliation variance ────────
    public function test_r09_fully_reversed_invoice_leaves_reconciliation_balanced(): void
    {
        $this->rule('SG-GST-9', 9);
        $inv = app(InvoiceService::class)->postInvoice($this->draftInvoice([['amount' => 100, 'rate' => 9]]));
        $res = app(\App\Accounting\Contracts\PostingEngineInterface::class)->reverse(new \App\Accounting\DTOs\ReversalRequest(
            journalEntryId: (int) $inv->journal_entry_id, reversalDate: now()->toDateString(), reason: 'void', actorUserId: $this->admin->id,
        ));
        $this->assertTrue($res->success, 'reversal precondition: '.($res->error ?? ''));

        $rec = $this->actingAs($this->admin)->getJson("/api/v1/accounting/tax-report/reconciliation?period={$this->period}")->json('data');
        fwrite(STDERR, "\n[R09] balanced=".json_encode($rec['balanced']).' total_abs_variance='.$rec['total_abs_variance'].' uncoded='.json_encode($rec['uncoded_gl_movement']).' lines-tax_code-on-reversal='.json_encode(DB::table('journal_entry_lines')->where('journal_entry_id', '!=', $inv->journal_entry_id)->whereNotNull('tax_code')->count())."\n");
        $this->assertTrue($rec['balanced'], 'A fully reversed invoice must reconcile: trail and GL both net to zero');
    }

    // ── R-10: partial reversal of the tax line must reach the return ─────────
    public function test_r10_partial_tax_reversal_reduces_the_return(): void
    {
        $this->rule('SG-GST-9', 9);
        $inv = app(InvoiceService::class)->postInvoice($this->draftInvoice([['amount' => 100, 'rate' => 9]]));
        $taxLine = DB::table('journal_entry_lines')->where('journal_entry_id', $inv->journal_entry_id)->whereNotNull('tax_code')->first();
        $this->assertNotNull($taxLine, 'precondition: tagged tax line exists');

        $engine = app(\App\Accounting\Services\ReversalCorrectionEngine::class);
        $result = $engine->partialReversal(new \App\Accounting\DTOs\ReversalRequest(
            journalEntryId: (int) $inv->journal_entry_id, reversalDate: now()->toDateString(), reason: 'partial return', actorUserId: $this->admin->id, tenantId: $this->tenantId,
        ), [['line_id' => $taxLine->id, 'amount' => 4.50]]);
        fwrite(STDERR, "\n[R10] partial reversal success=".json_encode($result->success ?? null).' ');

        $f5 = app(GstF5ReturnService::class)->generate($this->period);
        fwrite(STDERR, 'F5 box6='.$f5['boxes']['box_6_output_tax_due']." (GL tax control after partial = 4.50)\n");
        $this->assertEqualsWithDelta(4.50, $f5['boxes']['box_6_output_tax_due'], 0.01, 'Output tax on the return must fall by the reversed 4.50');
    }
}
