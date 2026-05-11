<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * DB-001: Database integrity constraints and indexes tested.
 *
 * Contract-focused assertions over migration sources keep this suite stable
 * while still proving that required DB-level guarantees are declared.
 */
class DB001DatabaseIntegrityContractTest extends TestCase
{
    public function test_stock_movements_declares_structural_unique_constraint(): void
    {
        $migration = file_get_contents(
            base_path('database/migrations/tenant/2026_04_27_190000_add_unique_constraint_to_stock_movements.php')
        );

        $this->assertStringContainsString('stock_movements_ref_product_type_unique', $migration);
        $this->assertStringContainsString("['reference_type', 'reference_id', 'product_id', 'type']", $migration);
    }

    public function test_stock_movements_declares_idempotency_unique_key(): void
    {
        $migration = file_get_contents(
            base_path('database/migrations/tenant/2026_05_09_000001_add_idempotency_key_to_stock_movements.php')
        );

        $this->assertStringContainsString("'idempotency_key'", $migration);
        $this->assertStringContainsString('->unique()', $migration);
        $this->assertStringContainsString('->nullable()', $migration);
    }

    public function test_journal_entry_links_declares_indexes_and_idempotency_unique_constraint(): void
    {
        $baseMigration = file_get_contents(
            base_path('database/migrations/tenant/2026_03_25_000004_create_journal_entry_links_table.php')
        );
        $uniqueMigration = file_get_contents(
            base_path('database/migrations/tenant/2026_04_30_000001_add_unique_constraint_journal_entry_links.php')
        );

        $this->assertStringContainsString("->index(['source_document_type', 'source_document_id']", $baseMigration);
        $this->assertStringContainsString("->index('journal_entry_id')", $baseMigration);
        $this->assertStringContainsString('journal_entry_links_source_post_unique', $uniqueMigration);
        $this->assertStringContainsString("['source_document_type', 'source_document_id', 'relation_type']", $uniqueMigration);
    }

    public function test_document_correction_links_declares_fk_and_runtime_indexes(): void
    {
        $createMigration = file_get_contents(
            base_path('database/migrations/tenant/2026_04_21_000002_create_document_correction_links_table.php')
        );
        $indexMigration = file_get_contents(
            base_path('database/migrations/tenant/2026_04_25_000001_remove_tenant_id_from_document_correction_links.php')
        );

        $this->assertStringContainsString("->constrained('users')->nullOnDelete()", $createMigration);
        $this->assertStringContainsString("->index(['document_type', 'document_id'])", $indexMigration);
        $this->assertStringContainsString("->index(['related_document_type', 'related_document_id'])", $indexMigration);
        $this->assertStringContainsString("->index(['relation_type'])", $indexMigration);
    }

    public function test_document_correction_links_enum_is_expanded_for_void_relations(): void
    {
        $migration = file_get_contents(
            base_path('database/migrations/tenant/2026_05_10_000001_expand_document_correction_relation_types.php')
        );

        $this->assertStringContainsString('document_correction_links_relation_type_check', $migration);
        $this->assertStringContainsString("'void'", $migration);
        $this->assertStringContainsString("'reversal_of'", $migration);
        $this->assertStringContainsString("'refund_for'", $migration);
    }
}
