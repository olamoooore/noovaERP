<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #888 (deferred revenue, delivered by #882): editing a DRAFT invoice
 * must preserve the per-line deferred service period just like creation does.
 * Before the fix, update() recreated lines without the deferred columns, so
 * the flag fell back to false, the dates to NULL, and a later posting
 * recognised the whole amount up front instead of crediting deferred revenue.
 */
class Issue888DraftEditPreservesDeferralTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private ChartOfAccount $servicesRevenue;

    private ChartOfAccount $deferredRevenue;

    private ChartOfAccount $receivable;

    private Product $deferredProduct;

    private int $legalEntityId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext((string) $tenant->id);

        $this->admin = User::factory()->create(['email' => 'tmp888-'.uniqid().'@example.local']);
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        CompanySettings::query()->create([
            'company_name' => 'TMP888 Sdn Bhd',
            'base_currency' => 'MYR',
        ]);

        $entityGroupId = DB::table('entity_groups')->insertGetId([
            'name' => 'TMP888 Group',
            'code' => 'TMP888-G-'.Str::upper(Str::random(5)),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->legalEntityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $entityGroupId,
            'name' => 'TMP888 Entity',
            'code' => 'TMP888-E-'.Str::upper(Str::random(5)),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create([
            'legal_entity_id' => $this->legalEntityId,
            'payment_terms' => 30,
        ]);

        $this->servicesRevenue = ChartOfAccount::factory()->create([
            'code' => 'TMP-4110'.Str::upper(Str::random(3)),
            'name' => 'TMP Services Income',
            'type' => 'revenue',
            'is_active' => true,
        ]);
        $this->deferredRevenue = ChartOfAccount::factory()->create([
            'code' => 'TMP-2300'.Str::upper(Str::random(3)),
            'name' => 'TMP Deferred Revenue',
            'type' => 'liability',
            'is_active' => true,
        ]);

        $this->receivable = ChartOfAccount::factory()->create([
            'code' => 'TMP-1240'.Str::upper(Str::random(3)),
            'name' => 'TMP Accounts Receivable',
            'type' => 'asset',
            'is_active' => true,
        ]);

        foreach ([
            'accounts_receivable' => $this->receivable->id,
            'sales_revenue' => $this->servicesRevenue->id,
            'deferred_revenue' => $this->deferredRevenue->id,
        ] as $key => $accountId) {
            DB::table('account_mappings')->where('mapping_key', $key)->delete();
            DB::table('account_mappings')->insert([
                'mapping_key' => $key,
                'account_id' => $accountId,
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->deferredProduct = Product::factory()->create([
            'name' => 'TMP Annual Maintenance',
            'sku' => 'TMP-SRV-'.Str::upper(Str::random(5)),
            'price' => 1200,
            'cost' => 0,
            'track_inventory' => false,
            'income_account_id' => $this->servicesRevenue->id,
            'is_deferred' => true,
            'deferred_months' => 12,
        ]);

        DB::table('currencies')->updateOrInsert(
            ['code' => 'MYR'],
            ['name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'decimal_places' => 2, 'is_active' => true, 'is_sellable' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function test_draft_edit_preserves_the_product_default_deferral(): void
    {
        $create = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'product_id' => $this->deferredProduct->id,
                'description' => 'Annual maintenance contract',
                'quantity' => 1,
                'unit_price' => 1200,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $create->assertCreated($create->getContent());

        $invoiceId = $create->json('id');
        $line = Invoice::findOrFail($invoiceId)->lines()->firstOrFail();
        $this->assertTrue((bool) $line->is_deferred, 'Precondition: product default must defer the line at creation.');

        $edit = $this->actingAs($this->admin)->putJson("/api/v1/invoices/{$invoiceId}", [
            'lines' => [[
                'product_id' => $this->deferredProduct->id,
                'description' => 'Annual maintenance contract (edited)',
                'quantity' => 1,
                'unit_price' => 1200,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $edit->assertOk($edit->getContent());

        // Consequence first: posting after a benign draft edit recognises the
        // whole 1200 on the invoice date instead of crediting deferred revenue.
        $post = $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoiceId}/post");
        $post->assertOk($post->getContent());

        $posted = Invoice::with('journalEntry.lines')->findOrFail($invoiceId);
        $deferredCredit = (float) $posted->journalEntry->lines
            ->where('account_id', $this->deferredRevenue->id)->sum('credit');
        $incomeCredit = (float) $posted->journalEntry->lines
            ->where('account_id', $this->servicesRevenue->id)->sum('credit');

        $this->assertSame(
            round((float) $posted->journalEntry->lines->sum('debit'), 2),
            round((float) $posted->journalEntry->lines->sum('credit'), 2),
            'Posting after a draft edit must still produce a balanced journal entry.'
        );
        $this->assertSame(
            1200.0,
            round($deferredCredit, 2),
            'After editing a deferred draft and posting, the unearned amount must still credit deferred revenue.'
        );
        $this->assertSame(
            0.0,
            round($incomeCredit, 2),
            'After editing a deferred draft and posting, no revenue may be recognised up front.'
        );

        $edited = Invoice::findOrFail($invoiceId)->lines()->firstOrFail();
        $this->assertTrue(
            (bool) $edited->is_deferred,
            'Editing a draft line must preserve the deferred flag (same product).'
        );
        $this->assertNotNull($edited->service_start_date, 'Edited draft line must keep its service start date.');
        $this->assertNotNull($edited->service_end_date, 'Edited draft line must keep its service end date.');
    }

    public function test_draft_edit_accepts_explicit_per_line_period(): void
    {
        $create = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'product_id' => $this->deferredProduct->id,
                'description' => 'Annual maintenance contract',
                'quantity' => 1,
                'unit_price' => 1200,
                'tax_rate' => 0,
                'discount_amount' => 0,
                'service_start_date' => now()->toDateString(),
                'service_end_date' => now()->addDays(90)->toDateString(),
            ]],
        ]);
        $create->assertCreated($create->getContent());

        $invoiceId = $create->json('id');
        $line = Invoice::findOrFail($invoiceId)->lines()->firstOrFail();
        $this->assertSame(now()->addDays(90)->toDateString(), $line->service_end_date->toDateString());

        $edit = $this->actingAs($this->admin)->putJson("/api/v1/invoices/{$invoiceId}", [
            'lines' => [[
                'product_id' => $this->deferredProduct->id,
                'description' => 'Annual maintenance contract (edited)',
                'quantity' => 2,
                'unit_price' => 1200,
                'tax_rate' => 0,
                'discount_amount' => 0,
                'service_start_date' => now()->toDateString(),
                'service_end_date' => now()->addDays(90)->toDateString(),
            ]],
        ]);
        $edit->assertOk($edit->getContent());

        $edited = Invoice::findOrFail($invoiceId)->lines()->firstOrFail();
        $this->assertSame(
            now()->addDays(90)->toDateString(),
            $edited->service_end_date?->toDateString(),
            'Editing a draft line must accept/keep the explicit per-line service end date.'
        );
    }
}
