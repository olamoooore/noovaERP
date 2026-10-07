<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\CustomerCreditNoteOutcomeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;
use Tests\TenantTestCase;

/**
 * Issue #532 (SALES-ODOO): credit note analysis — credit-rate KPIs
 * (credited ÷ invoiced) by reason, product, salesperson and customer, and net
 * revenue on the dashboard subtracting posted credit notes.
 *
 * The business rule asserted for every grouping: each row's credited amount is
 * the sum of the POSTED credit notes' net (tax-excluded) line amounts in the
 * tenant BASE currency (#355/#363) that fall in that row; drafts and voided
 * notes contribute nothing; invoiced is the posted invoices' net base revenue
 * for the same row; and the report is scoped by the #593 filters (legal
 * entity included) and readable with reports.view OR sales.view only.
 * Everything goes through the real HTTP routes.
 */
class Issue532CreditNoteAnalysisTest extends TenantTestCase
{
    private User $admin;

    private User $alice;

    private Customer $hospital;

    private Customer $clinic;

    private Product $implant;

    private Product $service;

    private Invoice $hospitalInvoice;

    private Invoice $clinicInvoice;

    private CustomerCreditNote $damaged;

    private CustomerCreditNote $pricing;

    private CustomerCreditNote $damagedUsd;

    private CustomerCreditNote $draft;

    private CustomerCreditNote $voided;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Credit Note Analysis 532 Tenant');
        CompanySettings::query()->create(['base_currency' => 'MYR']);

        $this->alice = User::factory()->create(['name' => '532 Alice']);

        $this->hospital = Customer::factory()->create([
            'name' => '532 Hospital', 'is_active' => true, 'salesperson_id' => $this->alice->id,
        ]);
        $this->clinic = Customer::factory()->create([
            'name' => '532 Clinic', 'is_active' => true, 'salesperson_id' => null,
        ]);

        $this->implant = Product::factory()->create([
            'name' => '532 Implant', 'sku' => 'IMP532-'.uniqid(), 'price' => 300, 'cost' => 100, 'track_inventory' => false,
        ]);
        $this->service = Product::factory()->create([
            'name' => '532 Service', 'sku' => 'SRV532-'.uniqid(), 'price' => 100, 'cost' => 0, 'track_inventory' => false,
        ]);

        // Invoiced (net, base): hospital 500 (implant 300 + service 200),
        // clinic 400 (implant). Tax on the hospital implant line must not
        // reach the net figures.
        $this->hospitalInvoice = $this->postInvoice($this->hospital, [
            ['product_id' => $this->implant->id, 'quantity' => 1, 'unit_price' => 300, 'tax_rate' => 6],
            ['product_id' => $this->service->id, 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 0],
        ]);
        $this->clinicInvoice = $this->postInvoice($this->clinic, [
            ['product_id' => $this->implant->id, 'quantity' => 1, 'unit_price' => 400, 'tax_rate' => 0],
        ]);

        // Posted credit notes (net, base):
        //   Damaged goods / hospital / implant  50
        //   Pricing error / clinic   / implant 100
        //   Damaged goods / hospital / service  20 USD at 4.0 = 80 MYR
        $this->damaged = $this->creditNote($this->hospitalInvoice, $this->implant, 50, 'Damaged goods', taxRate: 6);
        $this->pricing = $this->creditNote($this->clinicInvoice, $this->implant, 100, 'Pricing error');
        $this->damagedUsd = $this->creditNote($this->hospitalInvoice, $this->service, 20, 'Damaged goods');
        // Snapshot a foreign document rate on the posted note: the analysis
        // must convert at the note's own rate, like every #355 report.
        DB::table('customer_credit_notes')->where('id', $this->damagedUsd->id)
            ->update(['currency_code' => 'USD', 'exchange_rate' => 4.0]);

        // Excluded: a draft, and a posted-then-voided note.
        $this->draft = $this->creditNote($this->hospitalInvoice, $this->implant, 999, 'Damaged goods', post: false);
        $this->voided = $this->creditNote($this->clinicInvoice, $this->implant, 77, 'Pricing error');
        $this->actingAs($this->admin)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$this->voided->id}/void", ['reason' => '532 void'])
            ->assertOk();
        $this->assertSame('voided', $this->voided->fresh()->status);

        // A POS sale is revenue but not an invoice: it is in the dashboard's
        // net revenue, never in the credit-rate denominator.
        $pos = PosOrder::factory()->create([
            'customer_id' => $this->hospital->id, 'status' => 'paid', 'is_return' => false,
            'subtotal' => 1000, 'tax_amount' => 0, 'total' => 1000, 'discount_amount' => 0,
            'currency' => 'MYR', 'paid_at' => now(), 'payment_method' => 'cash',
        ]);
        PosOrderItem::create([
            'pos_order_id' => $pos->id, 'product_id' => $this->implant->id, 'name' => $this->implant->name,
            'quantity' => 1, 'unit_price' => 1000, 'tax_amount' => 0, 'line_total' => 1000,
        ]);
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function postInvoice(Customer $customer, array $lines): Invoice
    {
        $create = $this->actingAs($this->admin)->postJson('/api/v1/accounting/origin/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'auto_post' => true,
            'lines' => array_map(fn (array $line) => $line + [
                'line_type' => 'product',
                'description' => '532 invoice line',
                'discount_amount' => 0,
            ], $lines),
        ]);
        $create->assertCreated();

        $invoice = Invoice::findOrFail((int) $create->json('id'));
        $this->assertSame('posted', $invoice->lifecycle_status);

        return $invoice;
    }

    private function creditNote(Invoice $invoice, Product $product, float $net, string $reason, float $taxRate = 0.0, bool $post = true): CustomerCreditNote
    {
        $tax = round($net * $taxRate / 100, 2);

        $note = CustomerCreditNote::create([
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => $net,
            'tax_amount' => $tax,
            'total_amount' => round($net + $tax, 2),
            'reason' => $reason,
        ]);
        $note->lines()->create([
            'product_id' => $product->id,
            'description' => '532 credit line',
            'quantity' => 1,
            'unit_price' => $net,
            'tax_rate' => $taxRate,
        ]);

        if ($post) {
            $this->actingAs($this->admin)
                ->postJson("/api/v1/accounting/customers/credit-notes/{$note->id}/post")
                ->assertOk();
            $this->assertSame('posted', $note->fresh()->status);
        }

        return $note->fresh();
    }

    private function range(): array
    {
        return [
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ];
    }

    private function analysis(string $groupBy, array $params = [], ?User $as = null, array $headers = [])
    {
        return $this->actingAs($as ?? $this->admin)->getJson(
            '/api/v1/analytics/sales/credit-notes?'.http_build_query(array_merge($this->range(), ['group_by' => $groupBy], $params)),
            $headers,
        );
    }

    private function rows(string $groupBy, array $params = []): Collection
    {
        $response = $this->analysis($groupBy, $params);
        $response->assertOk();

        return collect($response->json('data.rows'))->keyBy('label');
    }

    /** Posted, non-voided credit-note net (tax-excluded) lines in base currency, straight from the tables. */
    private function postedCreditedFromTables(): float
    {
        return round((float) DB::table('customer_credit_note_lines as l')
            ->join('customer_credit_notes as n', 'n.id', '=', 'l.credit_note_id')
            ->where('n.status', 'posted')
            ->whereNull('n.voided_at')
            ->sum(DB::raw('(l.line_total - l.tax_amount) * COALESCE(n.exchange_rate, 1)')), 2);
    }

    private function assertRow(Collection $rows, string $label, float $credited, float $invoiced, int $notes): void
    {
        $this->assertTrue($rows->has($label), "row [{$label}] is missing; got: ".$rows->keys()->implode(', '));
        $row = $rows[$label];
        $this->assertSame($credited, round((float) $row['credited_amount'], 2), "credited for [{$label}]");
        $this->assertSame($invoiced, round((float) $row['invoiced_amount'], 2), "invoiced for [{$label}]");
        $this->assertSame($notes, (int) $row['credit_note_count'], "credit-note count for [{$label}]");
        $this->assertSame(
            round($credited / $invoiced * 100, 2),
            round((float) $row['credit_rate'], 2),
            "credit rate (credited ÷ invoiced) for [{$label}]"
        );
    }

    // ── groupings ─────────────────────────────────────────────────────────

    public function test_by_reason_totals_posted_base_credit_notes_against_total_invoiced(): void
    {
        $this->assertSame(230.0, $this->postedCreditedFromTables(), 'fixture: posted base credit is 50 + 100 + 80');

        $response = $this->analysis('reason');
        $response->assertOk();
        $rows = collect($response->json('data.rows'))->keyBy('label');

        // Invoices carry no reason: each reason's rate is over all invoicing.
        $this->assertRow($rows, 'Damaged goods', 130.0, 900.0, 2);
        $this->assertRow($rows, 'Pricing error', 100.0, 900.0, 1);
        $this->assertCount(2, $rows, 'drafts and voided notes create no rows');

        $this->assertSame(230.0, round((float) $response->json('data.summary.total_credited'), 2));
        $this->assertSame(900.0, round((float) $response->json('data.summary.total_invoiced'), 2));
        $this->assertSame(25.56, round((float) $response->json('data.summary.credit_rate'), 2));
        $this->assertSame(3, (int) $response->json('data.summary.credit_note_count'));
        $this->assertSame('MYR', $response->json('data.summary.base_currency'));
    }

    public function test_by_product(): void
    {
        $rows = $this->rows('product');

        $this->assertRow($rows, '532 Implant', 150.0, 700.0, 2);
        $this->assertRow($rows, '532 Service', 80.0, 200.0, 1);
        $this->assertSame(230.0, round((float) $rows->sum('credited_amount'), 2));
        $this->assertSame($this->implant->id, $rows['532 Implant']['key'] === null ? null : (int) $rows['532 Implant']['key']);
    }

    public function test_by_customer(): void
    {
        $rows = $this->rows('customer');

        $this->assertRow($rows, '532 Hospital', 130.0, 500.0, 2);
        $this->assertRow($rows, '532 Clinic', 100.0, 400.0, 1);
        $this->assertSame(230.0, round((float) $rows->sum('credited_amount'), 2));
    }

    public function test_by_salesperson_uses_the_customers_assigned_salesperson(): void
    {
        $rows = $this->rows('salesperson');

        $this->assertRow($rows, '532 Alice', 130.0, 500.0, 2);
        $this->assertRow($rows, 'Unassigned', 100.0, 400.0, 1);
        $this->assertSame(230.0, round((float) $rows->sum('credited_amount'), 2));
    }

    public function test_an_unknown_grouping_is_rejected(): void
    {
        $this->analysis('colour')->assertStatus(422);
        $this->analysis('reason', ['nonsense' => '1'])->assertStatus(422);
    }

    // ── applied / refunded state (CustomerCreditNoteOutcomeService) ──────

    public function test_outcome_state_matches_the_credit_note_outcome_service(): void
    {
        $notes = CustomerCreditNote::with('invoice')
            ->whereIn('id', [$this->damaged->id, $this->pricing->id, $this->damagedUsd->id])
            ->get();
        app(CustomerCreditNoteOutcomeService::class)->annotate($notes);
        $expected = $notes->countBy(fn ($n) => $n->outcome['state']);

        $response = $this->analysis('customer');
        $response->assertOk();
        $outcome = $response->json('data.summary.outcome');

        foreach (['applied', 'partially_applied', 'customer_credit'] as $state) {
            $this->assertSame((int) ($expected[$state] ?? 0), (int) $outcome[$state], "{$state} count");
        }

        $rows = collect($response->json('data.rows'))->keyBy('label');
        $this->assertSame(
            2,
            array_sum(array_map('intval', $rows['532 Hospital']['outcome'])),
            'each row counts its own notes by outcome'
        );
    }

    // ── drafts / voided, entity scope, permissions ────────────────────────

    public function test_entity_scoping(): void
    {
        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => '532 Group', 'code' => 'E532', 'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => '532 A', 'code' => 'E532A', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => '532 B', 'code' => 'E532B', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('invoices')->update(['legal_entity_id' => $entityA]);
        DB::table('customer_credit_notes')->update(['legal_entity_id' => $entityA]);
        DB::table('pos_orders')->update(['legal_entity_id' => $entityA]);
        DB::table('invoices')->where('id', $this->clinicInvoice->id)->update(['legal_entity_id' => $entityB]);
        DB::table('customer_credit_notes')->where('invoice_id', $this->clinicInvoice->id)->update(['legal_entity_id' => $entityB]);

        $rows = $this->rows('customer', ['legal_entity_id' => $entityA]);
        $this->assertCount(1, $rows, 'entity B documents are out of scope');
        $this->assertRow($rows, '532 Hospital', 130.0, 500.0, 2);

        // The request's entity context (X-Entity-Id) scopes it too.
        DB::table('user_entity_access')->insert([
            'user_id' => $this->admin->id, 'legal_entity_id' => $entityB, 'role' => 'viewer',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $scoped = $this->analysis('reason', [], null, ['X-Entity-Id' => (string) $entityB]);
        $scoped->assertOk();
        $this->assertSame(100.0, round((float) $scoped->json('data.summary.total_credited'), 2));
        $this->assertSame(400.0, round((float) $scoped->json('data.summary.total_invoiced'), 2));
    }

    public function test_requires_reports_view_or_sales_view(): void
    {
        $tenantId = (string) DB::table('user_company_access')
            ->where('user_id', DB::table('public.users')->where('email', $this->admin->email)->value('id'))
            ->value('tenant_id');

        $userWithRole = function (string $role) use ($tenantId): User {
            $user = User::factory()->create();
            DB::table('public.users')->updateOrInsert(
                ['email' => $user->email],
                ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()],
            );
            $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
            DB::table('user_company_access')->updateOrInsert(
                ['user_id' => $centralId, 'tenant_id' => $tenantId],
                ['role' => $role, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()],
            );

            return $user;
        };

        // Cashier holds neither reports.view nor sales.view.
        $this->analysis('reason', [], $userWithRole('cashier'))->assertForbidden();
        // Sales holds sales.view only; Accountant holds reports.view only.
        $this->analysis('reason', [], $userWithRole('sales'))->assertOk();
        $this->analysis('reason', [], $userWithRole('accountant'))->assertOk();
    }

    // ── drill-down (#596) and dashboard net revenue ───────────────────────

    public function test_every_row_drills_down_to_its_posted_credit_notes(): void
    {
        foreach (['reason', 'product', 'customer', 'salesperson'] as $groupBy) {
            foreach ($this->rows($groupBy) as $label => $row) {
                $drill = $this->actingAs($this->admin)->getJson('/api/v1/analytics/sales/drilldown?'.http_build_query(array_merge(
                    $this->range(),
                    [
                        'dimension' => $groupBy,
                        'key' => $row['key'] === null ? 'none' : (string) $row['key'],
                        'document_type' => 'credit_note',
                    ],
                )));
                $drill->assertOk();

                $documents = collect($drill->json('data.documents'));
                $this->assertTrue(
                    $documents->every(fn ($d) => $d['document_type'] === 'credit_note'),
                    "{$groupBy} [{$label}] drill lists credit notes only"
                );
                $this->assertNotContains($this->draft->id, $documents->pluck('document_id')->all());
                $this->assertNotContains($this->voided->id, $documents->pluck('document_id')->all());
                // Credit notes reduce revenue, so their net amounts are negative.
                $this->assertSame(
                    -round((float) $row['credited_amount'], 2),
                    round((float) $drill->json('data.summary.total_net_amount'), 2),
                    "{$groupBy} [{$label}] drill total equals the row's credited amount"
                );
                $this->assertSame((int) $row['credit_note_count'], $documents->count());
            }
        }
    }

    public function test_dashboard_net_revenue_subtracts_posted_credit_notes(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/by-product?'.http_build_query($this->range()));
        $response->assertOk();

        // 900 invoiced + 1000 POS − 230 posted credit notes (draft/voided ignored).
        $this->assertSame(1670.0, round((float) collect($response->json('data.products'))->sum('total_revenue'), 2));
    }
}
