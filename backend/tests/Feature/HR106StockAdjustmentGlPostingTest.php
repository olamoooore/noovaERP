<?php

namespace Tests\Feature;

use App\Accounting\Services\AccountMappingService;
use App\Models\AccountMapping;
use App\Models\FiscalPeriod;
use App\Models\JournalEntryLine;
use App\Models\JournalEntryLink;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockAdjustmentReason;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Services\StockAdjustmentService;
use Tests\TenantTestCase;

/**
 * Issue #106 (BLOCKER) — manual stock adjustments must post their valuation
 * change to the GL through PostingEngine.
 *
 * Verification from the issue: create a stock adjustment reducing quantity by
 * N units with a known unit cost, assert a journal entry exists debiting the
 * mapped expense account and crediting Inventory for N × unit_cost. This also
 * covers increases, per-reason mapping, zero-cost products, and atomicity
 * (a failed posting must roll the stock movement back).
 */
class HR106StockAdjustmentGlPostingTest extends TenantTestCase
{
    private User $user;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = (string) Str::uuid();
        $this->bindTenantContextWithPermissions($tenantId);

        $this->assertTenantSchemaReady([
            'products', 'warehouses', 'stock_levels', 'stock_movements',
            'stock_adjustment_reasons', 'journal_entries', 'journal_entry_lines',
        ]);

        $this->user = User::create([
            'name' => 'HR106 User',
            'email' => 'hr106-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($this->user);

        FiscalPeriod::firstOrCreate(
            ['code' => now()->format('Y-m')],
            [
                'name' => now()->format('F Y'),
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $this->user->id,
            ]
        );

        $this->warehouse = Warehouse::create([
            'name' => 'HR106 Warehouse',
            'code' => 'HR106-'.uniqid(),
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'HR106 Widget',
            'sku' => 'HR106-'.uniqid(),
            'cost' => 25.00,
            'price' => 40.00,
            'track_inventory' => true,
        ]);
    }

    private function reason(string $type = 'decrease', ?string $mappingKey = null): StockAdjustmentReason
    {
        return StockAdjustmentReason::create([
            'name' => 'HR106 '.Str::random(4),
            'code' => 'hr106-'.Str::lower(Str::random(6)),
            'type' => $type,
            'expense_account_mapping_key' => $mappingKey,
            'requires_approval' => false,
            'is_active' => true,
        ]);
    }

    private function seedLevel(int $quantity): StockLevel
    {
        return StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);
    }

    private function journalEntryLinesFor(int $movementId): \Illuminate\Support\Collection
    {
        $link = JournalEntryLink::where('source_document_type', 'stock_adjustment')
            ->where('source_document_id', $movementId)
            ->first();

        $this->assertNotNull($link, "A journal entry must be linked to stock movement #{$movementId}");

        return JournalEntryLine::where('journal_entry_id', $link->journal_entry_id)->get();
    }

    private function lineFor(\Illuminate\Support\Collection $lines, int $accountId): ?JournalEntryLine
    {
        return $lines->firstWhere('account_id', $accountId);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Verification from the issue: reducing adjustment posts DR expense / CR inventory
    // ─────────────────────────────────────────────────────────────────────

    public function test_reducing_adjustment_posts_expense_and_inventory(): void
    {
        $this->seedLevel(10);
        $reason = $this->reason('decrease');

        // Set stock to 6 → delta -4 × 25.00 = 100.00
        $movement = app(StockAdjustmentService::class)->adjust(
            $this->product->id,
            $this->warehouse->id,
            6,
            $reason->id,
            'cycle count correction'
        );

        $lines = $this->journalEntryLinesFor($movement->id);
        $this->assertCount(2, $lines);

        $mappingService = app(AccountMappingService::class);
        $expenseAccountId = $mappingService->resolveId('inventory_shrinkage');
        $inventoryAccountId = $mappingService->resolveId('inventory');

        $expenseLine = $this->lineFor($lines, $expenseAccountId);
        $inventoryLine = $this->lineFor($lines, $inventoryAccountId);

        $this->assertNotNull($expenseLine, 'Expense line must hit the mapped shrinkage account');
        $this->assertNotNull($inventoryLine, 'Inventory line must hit the inventory account');
        $this->assertSame(100.00, round((float) $expenseLine->debit, 2));
        $this->assertSame(0.00, round((float) $expenseLine->credit, 2));
        $this->assertSame(100.00, round((float) $inventoryLine->credit, 2));
        $this->assertSame(0.00, round((float) $inventoryLine->debit, 2));

        // Physical stock and product total agree with the adjustment.
        $this->assertSame(6, (int) StockLevel::where('product_id', $this->product->id)->where('warehouse_id', $this->warehouse->id)->value('quantity'));
    }

    public function test_write_off_via_stock_out_posts_to_the_reason_mapped_account(): void
    {
        $this->seedLevel(20);

        // Map this reason explicitly to the COGS account (any resolvable key works).
        $reason = $this->reason('decrease', 'cogs');

        // Write off 8 units × 25.00 = 200.00
        $movement = app(StockAdjustmentService::class)->stockOut(
            $this->product->id,
            $this->warehouse->id,
            8,
            $reason->id,
            'damaged in transit'
        );

        $lines = $this->journalEntryLinesFor($movement->id);
        $mappingService = app(AccountMappingService::class);

        $expenseLine = $this->lineFor($lines, $mappingService->resolveId('cogs'));
        $inventoryLine = $this->lineFor($lines, $mappingService->resolveId('inventory'));

        $this->assertNotNull($expenseLine, 'The reason-specific mapping key must be honoured');
        $this->assertSame(200.00, round((float) $expenseLine->debit, 2));
        $this->assertSame(200.00, round((float) $inventoryLine->credit, 2));
    }

    public function test_increasing_adjustment_debits_inventory_and_credits_gain(): void
    {
        $this->seedLevel(2);
        $reason = $this->reason('increase');

        // Set stock to 5 → delta +3 × 25.00 = 75.00
        $movement = app(StockAdjustmentService::class)->adjust(
            $this->product->id,
            $this->warehouse->id,
            5,
            $reason->id,
            'found stock'
        );

        $lines = $this->journalEntryLinesFor($movement->id);
        $mappingService = app(AccountMappingService::class);

        $inventoryLine = $this->lineFor($lines, $mappingService->resolveId('inventory'));
        $gainLine = $this->lineFor($lines, $mappingService->resolveId('inventory_adjustment_gain'));

        $this->assertNotNull($gainLine, 'Increases must credit the inventory adjustment gain account');
        $this->assertSame(75.00, round((float) $inventoryLine->debit, 2));
        $this->assertSame(75.00, round((float) $gainLine->credit, 2));
    }

    public function test_stock_in_posts_inventory_gain(): void
    {
        $this->seedLevel(0);
        $reason = $this->reason('increase');

        $movement = app(StockAdjustmentService::class)->stockIn(
            $this->product->id,
            $this->warehouse->id,
            4,
            $reason->id,
            'opening count'
        );

        $lines = $this->journalEntryLinesFor($movement->id);
        $mappingService = app(AccountMappingService::class);

        $this->assertSame(100.00, round((float) $this->lineFor($lines, $mappingService->resolveId('inventory'))->debit, 2));
        $this->assertSame(100.00, round((float) $this->lineFor($lines, $mappingService->resolveId('inventory_adjustment_gain'))->credit, 2));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Zero-cost + atomicity
    // ─────────────────────────────────────────────────────────────────────

    public function test_zero_cost_product_changes_stock_without_a_journal_entry(): void
    {
        $this->product->update(['cost' => 0]);
        $this->seedLevel(10);
        $reason = $this->reason('decrease');

        $movement = app(StockAdjustmentService::class)->adjust(
            $this->product->id,
            $this->warehouse->id,
            3,
            $reason->id,
            'zero cost write-off'
        );

        $this->assertSame(3, (int) StockLevel::where('product_id', $this->product->id)->where('warehouse_id', $this->warehouse->id)->value('quantity'));
        $this->assertFalse(
            JournalEntryLink::where('source_document_type', 'stock_adjustment')
                ->where('source_document_id', $movement->id)
                ->exists(),
            'A zero-valued movement must not create an empty journal entry'
        );
    }

    public function test_failed_posting_rolls_back_the_stock_movement(): void
    {
        $this->seedLevel(10);

        // Ensure defaults exist so we have a valid account id for the mapping.
        $accountId = app(AccountMappingService::class)->resolveId('inventory');

        // A reason mapped to an INACTIVE mapping key cannot be resolved, and the
        // per-key auto-seed will not fire because a row already exists for it.
        AccountMapping::create([
            'mapping_key' => 'hr106_blocked_key',
            'account_id' => $accountId,
            'document_type' => null,
            'is_active' => false,
        ]);
        $reason = $this->reason('decrease', 'hr106_blocked_key');

        try {
            app(StockAdjustmentService::class)->adjust(
                $this->product->id,
                $this->warehouse->id,
                1,
                $reason->id,
                'must fail'
            );
            $this->fail('Posting against an unresolvable mapping must throw');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('posting failed', strtolower($e->getMessage()));
        }

        // Stock and movement were rolled back with the failed posting.
        $this->assertSame(
            10,
            (int) StockLevel::where('product_id', $this->product->id)->where('warehouse_id', $this->warehouse->id)->value('quantity'),
            'A failed GL posting must not leave the stock movement committed'
        );
        $this->assertSame(
            0,
            \App\Models\StockMovement::where('product_id', $this->product->id)->where('reference_type', 'manual_adjustment')->count(),
            'No stock movement row may survive a failed posting'
        );
    }
}
