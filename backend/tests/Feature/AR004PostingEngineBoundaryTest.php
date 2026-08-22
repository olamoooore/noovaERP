<?php

namespace Tests\Feature;

use Tests\TenantTestCase;

/**
 * AR-004: Posting Engine Boundary Separation
 *
 * InventoryPostingEngine (stock mutations) and AccountingPostingEngine / PostingEngine
 * (GL journal entries) must have clearly separated responsibilities.
 *
 * Evidence required (from closure checklist):
 *   - InventoryPostingEngine class exists and delegates to InventoryService
 *   - AccountingPostingEngine (PostingEngine) does NOT directly mutate stock
 *   - InventoryPostingEngine does NOT directly create JournalEntry records
 *   - Boundary is documented and enforced by tests
 */
class AR004PostingEngineBoundaryTest extends TenantTestCase
{
    // ── InventoryPostingEngine existence and structure ────────────────────────

    public function test_inventory_posting_engine_class_exists(): void
    {
        $this->assertTrue(
            class_exists(\App\Accounting\InventoryPostingEngine::class),
            'InventoryPostingEngine must exist as the declared stock mutation engine boundary.'
        );
    }

    public function test_inventory_posting_engine_file_is_in_accounting_namespace(): void
    {
        $source = file_get_contents(base_path('app/Accounting/InventoryPostingEngine.php'));

        $this->assertStringContainsString('namespace App\Accounting', $source,
            'InventoryPostingEngine must be in the App\Accounting namespace alongside PostingEngine.');
    }

    public function test_inventory_posting_engine_declares_post_method(): void
    {
        $source = file_get_contents(base_path('app/Accounting/InventoryPostingEngine.php'));

        $this->assertStringContainsString('public function post(', $source,
            'InventoryPostingEngine must expose a post() method as its primary API.');
    }

    public function test_inventory_posting_engine_returns_stock_movement(): void
    {
        $source = file_get_contents(base_path('app/Accounting/InventoryPostingEngine.php'));

        $this->assertStringContainsString('StockMovement', $source,
            'InventoryPostingEngine::post() must return a StockMovement record.');
    }

    // ── InventoryPostingEngine delegates correctly ────────────────────────────

    public function test_inventory_posting_engine_delegates_to_inventory_service(): void
    {
        $source = file_get_contents(base_path('app/Accounting/InventoryPostingEngine.php'));

        $this->assertStringContainsString('InventoryService', $source,
            'InventoryPostingEngine must inject and use InventoryService.');
        $this->assertStringContainsString('adjustStock(', $source,
            'InventoryPostingEngine must call adjustStock() — not mutate stock directly.');
    }

    public function test_inventory_posting_engine_passes_idempotency_key_to_inventory_service(): void
    {
        $source = file_get_contents(base_path('app/Accounting/InventoryPostingEngine.php'));

        $this->assertStringContainsString('idempotencyKey', $source,
            'InventoryPostingEngine must forward the idempotency key to InventoryService (AR-002 compliance).');
    }

    // ── InventoryPostingEngine boundary: does NOT cross into accounting ───────

    public function test_inventory_posting_engine_does_not_create_journal_entries_directly(): void
    {
        $source = file_get_contents(base_path('app/Accounting/InventoryPostingEngine.php'));

        $this->assertStringNotContainsString('JournalEntry::create', $source,
            'InventoryPostingEngine must not directly create JournalEntry records — that is PostingEngine\'s domain.');
        $this->assertStringNotContainsString('JournalEntry::make', $source,
            'InventoryPostingEngine must not directly instantiate JournalEntry models.');
    }

    public function test_inventory_posting_engine_does_not_call_posting_engine_post(): void
    {
        $source = file_get_contents(base_path('app/Accounting/InventoryPostingEngine.php'));

        // Source may mention PostingEngine in comments; guard against actual usage only.
        $this->assertStringNotContainsString('use App\Accounting\PostingEngine', $source,
            'InventoryPostingEngine must not import PostingEngine.');
        $this->assertStringNotContainsString('new PostingEngine(', $source,
            'InventoryPostingEngine must not instantiate PostingEngine.');
        $this->assertStringNotContainsString('$postingEngine->post(', $source,
            'InventoryPostingEngine must not call PostingEngine::post() inline (AR-004 boundary).');
    }

    public function test_inventory_posting_engine_documents_deferred_accounting_pattern(): void
    {
        $source = file_get_contents(base_path('app/Accounting/InventoryPostingEngine.php'));

        // The class must explain WHY it doesn't call PostingEngine inline
        $this->assertStringContainsString('outbox', $source,
            'InventoryPostingEngine must document the deferred/outbox accounting pattern.');
    }

    // ── AccountingPostingEngine boundary: does NOT cross into stock ──────────

    public function test_accounting_posting_engine_does_not_mutate_stock_levels(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringNotContainsString('StockLevel::', $source,
            'PostingEngine (AccountingPostingEngine) must not reference StockLevel — that is InventoryPostingEngine\'s domain.');
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
            'PostingEngine must not call adjustStock() — stock mutation is InventoryPostingEngine\'s domain.');
    }

    // ── Structural: distinct class responsibilities documented ────────────────

    public function test_posting_engine_is_responsible_for_journal_entries(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString('JournalEntry', $source,
            'PostingEngine must work with JournalEntry (its primary domain).');
        $this->assertStringContainsString('JournalEntryLink', $source,
            'PostingEngine must use JournalEntryLink for idempotent posting.');
    }

    public function test_inventory_posting_engine_is_responsible_for_stock_movements(): void
    {
        $source = file_get_contents(base_path('app/Accounting/InventoryPostingEngine.php'));

        $this->assertStringContainsString('StockMovement', $source,
            'InventoryPostingEngine must work with StockMovement (its primary domain).');
    }

    // ── Reflexive: both engines exist as siblings ─────────────────────────────

    public function test_both_engines_coexist_in_accounting_namespace(): void
    {
        $this->assertTrue(
            class_exists(\App\Accounting\PostingEngine::class),
            'PostingEngine (AccountingPostingEngine) must exist.'
        );
        $this->assertTrue(
            class_exists(\App\Accounting\InventoryPostingEngine::class),
            'InventoryPostingEngine must exist as its sibling.'
        );
    }
}
