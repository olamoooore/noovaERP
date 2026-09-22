<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #122 — the dead contact_hierarchy_closure schema was removed (nothing
 * ever wrote to it) and the contract documentation corrected to state that
 * corporate-group hierarchy is not implemented.
 */
class HR122ContactHierarchyClosureRemovalTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext((string) Str::uuid());
    }

    public function test_dead_hierarchy_closure_table_is_gone(): void
    {
        $this->assertFalse(
            Schema::hasTable('contact_hierarchy_closure'),
            'The unused closure table must be dropped by the removal migration'
        );
    }

    public function test_contract_no_longer_documents_a_non_functional_hierarchy(): void
    {
        $doc = file_get_contents(base_path('modules/Contact/Contracts/ContactMasterServiceContract.md'));

        $this->assertStringNotContainsString(
            'contact_hierarchy_closure` | Hierarchical contact tree',
            $doc,
            'The contract must not list the removed closure table as functionality'
        );
        $this->assertStringContainsString('is NOT implemented', $doc,
            'The contract must state that corporate-group hierarchy is not implemented');
    }
}
