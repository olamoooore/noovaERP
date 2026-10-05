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
 * Issue #883 (SAU1) — the invoice Journal tab.
 *
 * AC: "Preview and posting share one builder; the preview never writes."
 *
 * Everything below goes through the real HTTP routes. The draft preview must
 * return the exact ledger lines the same invoice would post, and a GET of the
 * preview must not create a journal entry, a link, a tax determination result
 * or any other row. After posting, the same endpoint returns the ACTUAL entry
 * with enough identity (account code/name, entity, currencies) for each line
 * to link to the account ledger.
 */
class InvoiceJournalTabPreviewTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private ChartOfAccount $receivable;

    private ChartOfAccount $defaultRevenue;

    private ChartOfAccount $servicesRevenue;

    private Product $serviceProduct;

    private int $legalEntityId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);

        $this->admin = User::factory()->create(['email' => 'journal-tab-'.uniqid().'@example.local']);
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
            'company_name' => 'Journal Tab Sdn Bhd',
            'base_currency' => 'MYR',
        ]);

        $entityGroupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Journal Tab Group',
            'code' => 'JT-G-'.Str::upper(Str::random(5)),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->legalEntityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $entityGroupId,
            'name' => 'Journal Tab Entity',
            'code' => 'JT-E-'.Str::upper(Str::random(5)),
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

        $this->receivable = ChartOfAccount::factory()->create([
            'code' => 'JT-1240',
            'name' => 'JT Accounts Receivable',
            'type' => 'asset',
            'is_active' => true,
        ]);
        $this->defaultRevenue = ChartOfAccount::factory()->create([
            'code' => 'JT-4100',
            'name' => 'JT Default Sales Revenue',
            'type' => 'revenue',
            'is_active' => true,
        ]);
        $this->servicesRevenue = ChartOfAccount::factory()->create([
            'code' => 'JT-4110',
            'name' => 'JT Services Income',
            'type' => 'revenue',
            'is_active' => true,
        ]);

        foreach (['accounts_receivable' => $this->receivable->id, 'sales_revenue' => $this->defaultRevenue->id] as $key => $accountId) {
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

        $this->serviceProduct = Product::factory()->create([
            'name' => 'JT Consulting Hours',
            'sku' => 'JT-SRV-'.Str::upper(Str::random(5)),
            'price' => 100,
            'cost' => 0,
            'track_inventory' => false,
            'income_account_id' => $this->servicesRevenue->id,
        ]);

        DB::table('currencies')->updateOrInsert(
            ['code' => 'MYR'],
            ['name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'decimal_places' => 2, 'is_active' => true, 'is_sellable' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('currencies')->updateOrInsert(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true, 'is_sellable' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        \App\Models\ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'MYR',
            'rate' => 1.3527,
            'effective_date' => now()->subDay()->toDateString(),
            'source' => 'manual',
        ]);
    }

    /**
     * AC: the draft preview is the would-be journal, and reading it writes
     * nothing. The same endpoint then returns the ACTUAL entry after posting,
     * and the two agree line for line.
     */
    public function test_draft_preview_is_the_would_be_entry_and_never_writes(): void
    {
        $invoice = $this->createInvoice(['currency' => 'USD']);

        $preview = $this->actingAs($this->admin)
            ->getJson("/api/v1/invoices/{$invoice->id}/journal");
        $preview->assertOk($preview->getContent());

        $preview->assertJsonPath('source', 'preview')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('is_balanced', true)
            ->assertJsonPath('currency.document', 'USD')
            ->assertJsonPath('currency.base', 'MYR')
            ->assertJsonPath('currency.exchange_rate', 1.3527)
            ->assertJsonPath('legal_entity.id', $this->legalEntityId)
            ->assertJsonPath('legal_entity.name', 'Journal Tab Entity')
            ->assertJsonPath('error', null);

        $previewLines = collect($preview->json('lines'));

        $this->assertNotEmpty($previewLines, 'The preview must show the entry the invoice will post.');

        // Same builder as posting: the product's own income account (#876)
        // supplies the revenue line, in base currency with the document amount
        // and snapshot rate carried for dual display.
        $revenue = $previewLines->firstWhere('account_id', $this->servicesRevenue->id);
        $this->assertNotNull($revenue, 'The preview must resolve the product income account, not just the role default.');
        $this->assertEqualsWithDelta(135.27, (float) $revenue['credit'], 0.001);
        $this->assertEqualsWithDelta(100.00, (float) $revenue['original_amount'], 0.001);
        $this->assertSame('MYR', $revenue['currency_code']);
        $this->assertSame('JT-4110', $revenue['account_code']);
        $this->assertSame('JT Services Income', $revenue['account_name']);

        $ar = $previewLines->firstWhere('account_id', $this->receivable->id);
        $this->assertNotNull($ar, 'The preview must resolve the receivable role.');
        $this->assertEqualsWithDelta(135.27, (float) $ar['debit'], 0.001);

        // "The preview never writes."
        $this->assertSame(0, DB::table('journal_entries')->count(), 'The preview must not create a journal entry.');
        $this->assertSame(0, DB::table('journal_entry_lines')->count(), 'The preview must not create journal lines.');
        $this->assertSame(0, DB::table('journal_entry_links')->count(), 'The preview must not create a posting link.');
        $this->assertSame(0, DB::table('tax_determination_results')->count(), 'The preview must not persist a tax determination.');
        $this->assertSame(0, DB::table('deferred_revenue_schedules')->count());
        $this->assertNull($invoice->fresh()->journal_entry_id, 'The preview must leave the invoice as a draft.');
        $this->assertSame('draft', $invoice->fresh()->status);

        // Now post through the real route and fetch the actual entry from the
        // same tab endpoint.
        $postResponse = $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post");
        $postResponse->assertOk($postResponse->getContent());

        $actual = $this->actingAs($this->admin)
            ->getJson("/api/v1/invoices/{$invoice->id}/journal");
        $actual->assertOk($actual->getContent());

        $actual->assertJsonPath('source', 'actual')
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('is_balanced', true);
        $this->assertNotNull($actual->json('journal_entry.entry_number'), 'A posted invoice must expose its journal entry number for the tab.');

        $actualLines = collect($actual->json('lines'));
        $this->assertNotEmpty($actualLines);

        // One builder: preview line-for-line equals what posting wrote.
        $normalise = fn ($lines) => $lines
            ->map(fn ($line) => [
                'account_id' => (int) $line['account_id'],
                'debit' => round((float) $line['debit'], 2),
                'credit' => round((float) $line['credit'], 2),
                'tax_code' => $line['tax_code'] ?? null,
            ])
            ->sortBy(fn ($line) => $line['account_id'].'|'.$line['debit'].'|'.$line['credit'])
            ->values()
            ->all();

        $this->assertSame(
            $normalise($previewLines),
            $normalise($actualLines),
            'The preview and the posted entry must come from the same builder.'
        );

        // Drill-down identity on the actual lines.
        $this->assertNotNull($actualLines->first()['account_code'] ?? null, 'Each posted line must name its account for the ledger link.');
        $postedRevenue = $actualLines->firstWhere('account_id', $this->servicesRevenue->id);
        $this->assertNotNull($postedRevenue);
        $this->assertSame('JT-4110', $postedRevenue['account_code']);
    }

    /**
     * The proposed tab surfaces setup errors before Post: when no legal entity
     * can be resolved for the document (the tenant has entities), the preview
     * reports it as an error, not a 500, and nothing is written.
     */
    public function test_preview_reports_a_missing_entity_as_a_setup_error(): void
    {
        $entitylessCustomer = Customer::factory()->create([
            'legal_entity_id' => null,
            'payment_terms' => 30,
        ]);

        $payload = [
            'customer_id' => $entitylessCustomer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'product_id' => $this->serviceProduct->id,
                'description' => 'Consulting',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ];

        $create = $this->actingAs($this->admin)->postJson('/api/v1/invoices', $payload);
        $create->assertCreated($create->getContent());

        $invoice = Invoice::with('lines')->findOrFail($create->json('id'));
        $invoice->forceFill(['legal_entity_id' => null, 'entity_book_id' => null])->save();

        // A second active entity makes "the tenant's only entity" ambiguous —
        // the document cannot be bound to an entity and posting would fail.
        $secondGroupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Journal Tab Second Group',
            'code' => 'JT-G2-'.Str::upper(Str::random(5)),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_entities')->insert([
            'group_id' => $secondGroupId,
            'name' => 'Journal Tab Second Entity',
            'code' => 'JT-E2-'.Str::upper(Str::random(5)),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/invoices/{$invoice->id}/journal");
        $response->assertOk($response->getContent());

        $response->assertJsonPath('source', 'preview')
            ->assertJsonPath('status', 'draft');

        $this->assertNotNull($response->json('error'), 'A missing legal entity must be reported on the preview.');
        $this->assertStringContainsStringIgnoringCase('legal entity', (string) $response->json('error'));

        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertNull($invoice->fresh()->journal_entry_id);
    }

    private function createInvoice(array $options = []): Invoice
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'product_id' => $this->serviceProduct->id,
                'description' => 'Consulting',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ];

        if (($options['currency'] ?? null) !== null) {
            $payload['currency_code'] = $options['currency'];
        }

        $response = $this->actingAs($this->admin)->postJson('/api/v1/invoices', $payload);
        $response->assertCreated($response->getContent());

        return Invoice::with('lines')->findOrFail($response->json('id'));
    }
}
