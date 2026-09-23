<?php

namespace Tests\Feature;

use Tests\TenantTestCase;

/**
 * AR-004: Posting engine boundary separation.
 *
 * Issue #108 removed App\Accounting\InventoryPostingEngine — a declared
 * "engine-level stock-mutation boundary" that had zero production callers and
 * deferred accounting to an InventoryAccountingJob that never existed. The
 * real invariants it claimed to enforce are asserted here directly against the
 * live classes:
 *
 *   - stock mutation authority: InventoryService::adjustStock() (plus the
 *     module services that delegate to it)
 *   - accounting authority: PostingEngine / domain posts — which must never
 *     mutate stock
 *   - the dead boundary class must stay removed (it must not accrete a fourth
 *     COGS-posting path)
 */
class AR004PostingEngineBoundaryTest extends TenantTestCase
{
    // ── The dead boundary class stays removed ────────────────────────────────

    public function test_dead_inventory_posting_engine_is_removed(): void
    {
        $this->assertFalse(
            class_exists(\App\Accounting\InventoryPostingEngine::class),
            'InventoryPostingEngine must stay removed (dead code, issue #108)'
        );
    }

    public function test_no_production_code_references_the_removed_class(): void
    {
        $offenders = [];

        foreach ([base_path('app'), base_path('modules')] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                if (str_contains(file_get_contents($file->getPathname()), 'InventoryPostingEngine')) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'No production code may reference the removed dead class: '.implode(', ', $offenders));
    }

    // ── Accounting engine does NOT cross into stock ──────────────────────────

    public function test_accounting_posting_engine_does_not_mutate_stock_levels(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringNotContainsString('StockLevel::', $source,
            'PostingEngine must not reference StockLevel — stock is InventoryService\'s domain.');
    }

    public function test_accounting_posting_engine_does_not_create_stock_movements(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringNotContainsString('StockMovement::create', $source,
            'PostingEngine must not directly create StockMovement records.');
    }

    public function test_accounting_posting_engine_does_not_call_adjust_stock(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringNotContainsString('adjustStock', $source,
            'PostingEngine must not call adjustStock() — stock mutation is InventoryService\'s domain.');
    }

    public function test_posting_engine_is_responsible_for_journal_entries(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString('JournalEntry', $source,
            'PostingEngine must work with JournalEntry (its primary domain).');
        $this->assertStringContainsString('JournalEntryLink', $source,
            'PostingEngine must use JournalEntryLink for idempotent posting.');
    }

    // ── Stock mutation authority is InventoryService ─────────────────────────

    public function test_inventory_service_owns_the_stock_mutation_path(): void
    {
        $source = file_get_contents(base_path('modules/Inventory/src/Services/InventoryService.php'));

        $this->assertStringContainsString('function adjustStock', $source,
            'InventoryService::adjustStock must remain the canonical stock mutation method.');
    }

    public function test_live_stock_mutation_callers_delegate_to_inventory_service(): void
    {
        $callers = [
            'modules/POS/src/Services/PosPaymentCompletionService.php',
            'modules/Ecommerce/src/Services/WebOrderPostingService.php',
            'modules/Sales/src/Services/DeliveryOrderService.php',
            'modules/Inventory/src/Services/StockAdjustmentService.php',
        ];

        foreach ($callers as $relative) {
            $source = file_get_contents(base_path($relative));

            $this->assertStringContainsString('adjustStock', $source,
                "{$relative} must mutate stock through adjustStock().");
        }

        // StockController delegates to the module service (#106), which in turn
        // calls adjustStock — the invariant holds one layer down.
        $controller = file_get_contents(base_path('modules/Inventory/src/Http/Controllers/StockController.php'));
        $this->assertStringContainsString('StockAdjustmentService', $controller,
            'StockController must mutate stock through StockAdjustmentService.');
        $this->assertStringNotContainsString('StockLevel::create', $controller,
            'StockController must not mutate stock tables directly.');
    }

    public function test_cogs_posting_paths_route_through_posting_engine(): void
    {
        // The real accounting integration is a direct PostingEngine call — the
        // removed boundary class claimed to own this but never called it.
        foreach ([
            'modules/POS/src/Services/PosPostingService.php',
            'modules/Ecommerce/src/Services/WebOrderPostingService.php',
            'app/Services/InvoiceService.php',
        ] as $relative) {
            $source = file_get_contents(base_path($relative));

            $this->assertTrue(
                str_contains($source, 'PostingEngine') || str_contains($source, 'postingEngine'),
                "{$relative} must post through PostingEngine (not a bespoke path)."
            );
        }
    }
}
