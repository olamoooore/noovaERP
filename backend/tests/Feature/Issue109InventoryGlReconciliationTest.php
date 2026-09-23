<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Reconciliation\InventoryGLReconciliationService;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Tests\TenantTestCase;

/**
 * Issue #109 — inventory valuation vs GL Inventory control account
 * reconciliation (the sibling of ARGL/APGL reconciliation).
 */
class Issue109InventoryGlReconciliationTest extends TenantTestCase
{
    private User $user;

    private Product $product;

    private Warehouse $warehouse;

    private ChartOfAccount $inventoryAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '10910910-9202-9303-9404-950596069707',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'products', 'warehouses', 'stock_levels', 'journal_entries', 'journal_entry_lines',
        ]);

        $this->user = User::create([
            'name' => 'Issue109 User',
            'email' => 'issue109-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Issue109 WH', 'code' => 'I109-'.Str::upper(Str::random(4)), 'is_active' => true,
        ]);
        $this->product = Product::factory()->create(['cost' => 10.00, 'price' => 20.00, 'track_inventory' => true]);

        StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
        ]);

        // The baseline COA already seeds 1200; pin the canonical inventory
        // mapping to it so the reconciliation resolves deterministically.
        $this->inventoryAccount = ChartOfAccount::where('code', '1200')->first()
            ?? ChartOfAccount::factory()->create([
                'code' => '1200', 'name' => 'Inventory', 'type' => 'asset', 'is_active' => true,
            ]);

        AccountMapping::updateOrCreate(
            ['mapping_key' => 'inventory'],
            ['account_id' => $this->inventoryAccount->id],
        );
    }

    private function postInventoryJournal(float $amount): JournalEntry
    {
        $offset = ChartOfAccount::factory()->create(['type' => 'liability', 'is_active' => true]);

        $entry = JournalEntry::create([
            'entry_number' => 'JE-I109-'.Str::upper(Str::random(6)),
            'entry_date' => now()->toDateString(),
            'status' => 'posted',
            'is_active_version' => true,
            'created_by' => $this->user->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->inventoryAccount->id,
            'debit' => $amount,
            'credit' => 0,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $offset->id,
            'debit' => 0,
            'credit' => $amount,
        ]);

        return $entry;
    }

    public function test_variance_is_detected_when_the_gl_has_no_inventory_balance(): void
    {
        $result = app(InventoryGLReconciliationService::class)->reconcile(now()->toDateString());

        $this->assertSame(100.0, round($result['inventory_valuation'], 2));
        $this->assertSame(0.0, round($result['gl_inventory_balance'], 2));
        $this->assertSame(100.0, round($result['variance'], 2));
        $this->assertFalse($result['within_tolerance']);
        $this->assertNotEmpty($result['drift_items'], 'Drift detail must be surfaced when out of tolerance.');
        $this->assertSame($this->inventoryAccount->id, $result['gl_account']['id']);
    }

    public function test_reconciled_when_the_gl_balance_matches_the_valuation(): void
    {
        $this->postInventoryJournal(100.00);

        $result = app(InventoryGLReconciliationService::class)->reconcile(now()->toDateString());

        $this->assertSame(100.0, round($result['gl_inventory_balance'], 2));
        $this->assertSame(0.0, round($result['variance'], 2));
        $this->assertTrue($result['within_tolerance']);
        $this->assertSame([], $result['drift_items']);
    }

    public function test_voided_journal_entries_do_not_count_toward_the_gl_balance(): void
    {
        $entry = $this->postInventoryJournal(100.00);
        $entry->update(['is_active_version' => false]);

        $result = app(InventoryGLReconciliationService::class)->reconcile(now()->toDateString());

        $this->assertSame(0.0, round($result['gl_inventory_balance'], 2));
        $this->assertSame(100.0, round($result['variance'], 2));
    }

    public function test_reconciliation_command_runs_for_the_tenant(): void
    {
        $this->postInventoryJournal(100.00);

        $this->artisan('inventory:reconcile-gl')->assertExitCode(0);

        $result = app(InventoryGLReconciliationService::class)->reconcile(now()->toDateString());
        $this->assertTrue($result['within_tolerance']);
    }
}
