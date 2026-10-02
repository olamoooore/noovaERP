<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesLegalEntityBackfillService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Services\SalesOrderService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #875 backfill contract (recorded decision 2026-10-02):
 *
 *  - Single-entity tenants: existing Sales documents and journal entries are
 *    stamped with that entity automatically.
 *  - Multi-entity tenants: nothing is written automatically. A read-only
 *    mapping report lists every unmapped document/JE with the proposed entity
 *    and the reason; unmatched rows stay blank and are listed.
 */
class HR875SalesLegalEntityBackfillTest extends TenantTestCase
{
    private string $tenantId;

    private int $entityA;

    private int $entityB;

    private int $bookA;

    private int $bookB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'HR875 Backfill Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'HR875 Backfill Group',
            'code' => '875B-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity A',
            'code' => '875BA-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity B',
            'code' => '875BB-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bookA = DB::table('entity_books')->insertGetId([
            'legal_entity_id' => $this->entityA,
            'name' => 'Primary Book A',
            'book_type' => 'general',
            'currency_code' => 'USD',
            'is_primary' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bookB = DB::table('entity_books')->insertGetId([
            'legal_entity_id' => $this->entityB,
            'name' => 'Primary Book B',
            'book_type' => 'general',
            'currency_code' => 'USD',
            'is_primary' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Accountant');

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'accountant',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /**
     * Legacy invoice with no entity columns (pre-#875 row). Model events are
     * suppressed so the creation-time stamping cannot hide the legacy state.
     */
    private function legacyInvoice(?int $customerEntityId, bool $withSalesOrderEntity = false): Invoice
    {
        $user = $this->makeUser();

        $customer = Customer::factory()->create([
            'legal_entity_id' => $customerEntityId,
            'is_active' => true,
        ]);

        $salesOrderId = null;
        if ($withSalesOrderEntity) {
            $warehouse = Warehouse::create([
                'name' => 'HR875 Warehouse',
                'code' => '875W-'.uniqid(),
                'is_active' => true,
            ]);

            $order = app(SalesOrderService::class)->createOrder([
                'customer_id' => $customer->id,
                'channel' => 'admin',
                'warehouse_id' => $warehouse->id,
                'created_by' => $user->id,
                'lines' => [[
                    'description' => 'Legacy SO line',
                    'quantity' => 1,
                    'unit_price' => 10,
                    'tax_rate' => 0,
                ]],
            ]);
            $salesOrderId = $order->id;
        }

        return Invoice::withoutEvents(fn () => Invoice::factory()->create([
            'customer_id' => $customer->id,
            'sales_order_id' => $salesOrderId,
            'created_by' => $user->id,
        ]));
    }

    private function legacySalesJournalEntry(Invoice $invoice): JournalEntry
    {
        $entry = JournalEntry::withoutEvents(fn () => JournalEntry::factory()->create([
            'type' => 'invoice',
            'reference' => $invoice->invoice_number,
        ]));

        JournalEntryLink::create([
            'source_document_type' => 'invoice',
            'source_document_id' => $invoice->id,
            'journal_entry_id' => $entry->id,
            'relation_type' => 'post',
        ]);

        return $entry;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Single-entity tenant: automatic backfill
    // ─────────────────────────────────────────────────────────────────────

    public function test_single_active_entity_tenant_is_backfilled_automatically(): void
    {
        // Make this a single-entity tenant (B inactive).
        DB::table('legal_entities')->where('id', $this->entityB)->update(['status' => 'inactive']);

        $invoice = $this->legacyInvoice(customerEntityId: null);
        $entry = $this->legacySalesJournalEntry($invoice);

        $reversal = JournalEntry::withoutEvents(fn () => JournalEntry::factory()->create([
            'type' => 'invoice',
            'is_reversal' => true,
            'reversed_entry_id' => $entry->id,
            'is_active_version' => false,
        ]));

        $result = app(SalesLegalEntityBackfillService::class)->applySingleEntityBackfill();

        $this->assertTrue($result['applied'], 'Single-entity backfill must apply');

        $this->assertSame(
            $this->entityA,
            (int) DB::table('invoices')->where('id', $invoice->id)->value('legal_entity_id'),
            'Single-entity backfill must stamp legacy invoices'
        );
        $this->assertSame(
            $this->bookA,
            (int) DB::table('invoices')->where('id', $invoice->id)->value('entity_book_id'),
            'Single-entity backfill must stamp the entity primary book on invoices'
        );
        $this->assertSame(
            $this->entityA,
            (int) DB::table('journal_entries')->where('id', $entry->id)->value('legal_entity_id'),
            'Single-entity backfill must stamp Sales journal entries'
        );
        $this->assertSame(
            $this->bookA,
            (int) DB::table('journal_entries')->where('id', $entry->id)->value('entity_book_id'),
            'Single-entity backfill must stamp the entity book on journal entries'
        );
        $this->assertSame(
            $this->entityA,
            (int) DB::table('journal_entries')->where('id', $reversal->id)->value('legal_entity_id'),
            'Reversal entries must inherit the entity of the entry they reverse'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Multi-entity tenant: report only, nothing written
    // ─────────────────────────────────────────────────────────────────────

    public function test_multi_entity_tenant_is_not_auto_backfilled(): void
    {
        $invoice = $this->legacyInvoice(customerEntityId: $this->entityB, withSalesOrderEntity: true);

        $result = app(SalesLegalEntityBackfillService::class)->applySingleEntityBackfill();

        $this->assertFalse($result['applied'], 'Multi-entity backfill must not apply automatically');
        $this->assertSame(
            0,
            $result['documents'] + $result['journal_entries'],
            'No rows may be written for a multi-entity tenant'
        );

        $this->assertNull(
            DB::table('invoices')->where('id', $invoice->id)->value('legal_entity_id'),
            'Multi-entity invoices must stay blank until the mapping report is approved'
        );
    }

    public function test_mapping_report_proposes_entities_with_reasons_and_lists_unmatched_rows(): void
    {
        // Invoice with a sales order entity: the strongest provenance.
        $invoiceFromOrder = $this->legacyInvoice(customerEntityId: null, withSalesOrderEntity: true);
        // Invoice with only a customer entity.
        $invoiceFromCustomer = $this->legacyInvoice(customerEntityId: $this->entityB);
        // Invoice with no provenance at all: unmatched, stays blank.
        $invoiceUnmatched = $this->legacyInvoice(customerEntityId: null);
        // JE linked to the SO-backed invoice.
        $entry = $this->legacySalesJournalEntry($invoiceFromOrder);

        $report = app(SalesLegalEntityBackfillService::class)->mappingReport();

        $fromOrder = $report['proposed']['invoice:'.$invoiceFromOrder->id];
        $this->assertSame($this->entityA, $fromOrder['proposed_entity_id']);
        $this->assertSame($this->bookA, $fromOrder['proposed_book_id']);
        $this->assertStringContainsString('sales order', $fromOrder['reason']);

        $fromCustomer = $report['proposed']['invoice:'.$invoiceFromCustomer->id];
        $this->assertSame($this->entityB, $fromCustomer['proposed_entity_id']);
        $this->assertStringContainsString('customer', $fromCustomer['reason']);

        $unmatched = $report['unmatched']['invoice:'.$invoiceUnmatched->id] ?? null;
        $this->assertNotNull($unmatched, 'Unmatched documents must be listed');
        $this->assertNull($unmatched['proposed_entity_id'], 'Unmatched documents must stay blank');

        $entryRow = $report['proposed']['journal_entry:'.$entry->id];
        $this->assertSame($this->entityA, $entryRow['proposed_entity_id']);
        $this->assertStringContainsString('source document', $entryRow['reason']);

        $this->assertSame(1, $report['counts']['unmatched']);
    }
}