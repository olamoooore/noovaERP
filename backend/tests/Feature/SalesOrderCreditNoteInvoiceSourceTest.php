<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesInvoiceLink;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #372 (O3) — the sales-order credit-note endpoint built its lines from
 * ORDER lines at full ordered quantity and list price, against the latest
 * linked invoice only. An order invoiced in parts therefore credited other
 * invoices' quantities against the last one, and line/header discounts and
 * tax-inclusive pricing were lost, so the credit total did not match what was
 * actually invoiced.
 *
 * Contract asserted here: the credit note is built from the chosen invoice's
 * lines, defaulting to the remaining creditable quantity, with the invoice's
 * discount and tax.
 */
class SalesOrderCreditNoteInvoiceSourceTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private SalesOrder $order;

    private Invoice $firstInvoice;

    private Invoice $secondInvoice;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '22222222-0000-9999-8888-777777777777',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'sales_orders',
            'sales_order_lines',
            'sales_invoice_links',
            'customer_credit_notes',
            'customer_credit_note_lines',
            'invoices',
            'invoice_lines',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'O3 User',
            'email' => 'o3-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->user->assignRole('admin');

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();

        // Order: line A 10 x 100, line B 5 x 40.
        $this->order = SalesOrder::create([
            'sales_order_number' => 'SO-O3-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'status' => 'invoiced',
            'created_by' => $this->user->id,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $this->order->id,
            'description' => 'Line A',
            'quantity' => 10,
            'unit_price' => 100,
            'tax_rate' => 6,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $this->order->id,
            'description' => 'Line B',
            'quantity' => 5,
            'unit_price' => 40,
            'tax_rate' => 6,
        ]);

        // Invoice 1: line A partially invoiced — 4 x 100, no discount.
        $this->firstInvoice = $this->invoice([
            ['description' => 'Line A', 'quantity' => 4, 'unit_price' => 100, 'tax_rate' => 6, 'discount_amount' => 0],
        ]);

        // Invoice 2: the remainder of line A — 6 x 100 with a 100 line
        // discount (net 500, tax 30, total 530).
        $this->secondInvoice = $this->invoice([
            ['description' => 'Line A', 'quantity' => 6, 'unit_price' => 100, 'tax_rate' => 6, 'discount_amount' => 100],
        ]);
    }

    private function invoice(array $lineSpecs): Invoice
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
            'created_by' => $this->user->id,
            'sales_order_id' => $this->order->id,
        ]);

        foreach ($lineSpecs as $spec) {
            InvoiceLine::create(array_merge([
                'invoice_id' => $invoice->id,
                'tax_inclusive' => false,
            ], $spec));
        }

        $invoice->calculateTotals();

        SalesInvoiceLink::create([
            'sales_order_id' => $this->order->id,
            'invoice_id' => $invoice->id,
        ]);

        return $invoice->fresh();
    }

    private function creditNoteFromOrder(?int $invoiceId = null)
    {
        return $this->actingAs($this->user)->postJson(
            "/api/v1/sales/orders/{$this->order->id}/credit-note",
            array_filter(['invoice_id' => $invoiceId], fn ($value) => $value !== null),
        );
    }

    public function test_credit_note_for_the_chosen_invoice_uses_only_that_invoices_lines(): void
    {
        $this->assertEqualsWithDelta(530.0, (float) $this->secondInvoice->total, 0.01, 'invoice 2 fixture must be 530');

        $response = $this->creditNoteFromOrder($this->secondInvoice->id);
        $response->assertCreated();

        $creditNote = CustomerCreditNote::findOrFail($response->json('credit_note.id'));

        $this->assertSame($this->secondInvoice->id, (int) $creditNote->invoice_id, 'the credit note must target the chosen invoice');
        $this->assertSame(1, $creditNote->lines()->count(), 'only invoice 2 has a line here — the order has two');
        $this->assertEqualsWithDelta(530.0, (float) $creditNote->total_amount, 0.01, 'the credit total must equal what invoice 2 invoiced');

        $line = $creditNote->lines()->first();
        $originLine = $this->secondInvoice->lines()->first();

        $this->assertSame($originLine->id, (int) $line->invoice_line_id, 'the line must link to the invoice line');
        $this->assertEqualsWithDelta(6.0, (float) $line->quantity, 0.01, 'the full remaining quantity of the invoice line');
        $this->assertEqualsWithDelta(100.0, (float) $line->unit_price, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $line->discount_amount, 0.01, 'the invoice line discount must carry over');
        $this->assertEqualsWithDelta(30.0, (float) $creditNote->tax_amount, 0.01, 'the invoice tax must carry over');
    }

    public function test_default_invoice_is_the_latest_linked_invoice_and_still_uses_its_lines(): void
    {
        $response = $this->creditNoteFromOrder();
        $response->assertCreated();

        $creditNote = CustomerCreditNote::findOrFail($response->json('credit_note.id'));

        $this->assertSame($this->secondInvoice->id, (int) $creditNote->invoice_id, 'the latest linked invoice is the default');
        $this->assertSame(1, $creditNote->lines()->count());
        $this->assertEqualsWithDelta(530.0, (float) $creditNote->total_amount, 0.01);

        // Invoice 1's 4 units must not be credited against invoice 2: the
        // order has 15 units across two lines, invoice 2 only 6.
        $this->assertEqualsWithDelta(6.0, (float) $creditNote->lines()->first()->quantity, 0.01);
    }

    public function test_credit_note_defaults_to_the_remaining_creditable_quantity(): void
    {
        $this->seedPostingFixtures();

        // Credit 2 of invoice 2's 6 units through the normal credit-note path.
        $create = $this->actingAs($this->user)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $this->secondInvoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'O3 partial',
            'lines' => [[
                'invoice_line_id' => $this->secondInvoice->lines()->first()->id,
                'description' => 'Line A',
                'quantity' => 2,
                'unit_price' => 100,
            ]],
        ]);
        $create->assertCreated();

        $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$create->json('data.id')}/post")
            ->assertOk();

        // The sales-order endpoint must now credit only the remaining 4 units.
        $response = $this->creditNoteFromOrder($this->secondInvoice->id);
        $response->assertCreated();

        $creditNote = CustomerCreditNote::findOrFail($response->json('credit_note.id'));
        $this->assertEqualsWithDelta(4.0, (float) $creditNote->lines()->first()->quantity, 0.01, 'already-posted credits must be excluded');
    }

    public function test_credit_note_rejects_an_invoice_that_does_not_belong_to_the_order(): void
    {
        $unrelated = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'posted',
            'created_by' => $this->user->id,
        ]);

        $response = $this->creditNoteFromOrder($unrelated->id);

        $response->assertStatus(422);
        $this->assertSame(0, CustomerCreditNote::count(), 'no credit note may be created for a foreign invoice');
    }

    public function test_credit_note_is_rejected_when_every_line_is_fully_credited(): void
    {
        $this->seedPostingFixtures();

        // Credit the whole of invoice 2 through the sales endpoint, post it,
        // then ask again — there is nothing left to credit.
        $first = $this->creditNoteFromOrder($this->secondInvoice->id);
        $first->assertCreated();

        $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$first->json('credit_note.id')}/post")
            ->assertOk();

        $second = $this->creditNoteFromOrder($this->secondInvoice->id);
        $second->assertStatus(422);
    }

    private function seedPostingFixtures(): void
    {
        $revenue = ChartOfAccount::factory()->create(['code' => '3000-O3', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-O3', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-O3', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);

        DB::table('fiscal_periods')->insert([
            'name' => now()->format('F Y'),
            'code' => now()->format('Y-m'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'period_type' => 'monthly',
            'fiscal_year' => (int) now()->format('Y'),
            'is_locked' => false,
            'status' => 'open',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
