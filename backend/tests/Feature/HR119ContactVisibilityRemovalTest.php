<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Contact\Contracts\ContactModuleContract;
use Modules\Contact\src\Models\CoreContact;
use Tests\TenantTestCase;

/**
 * Issue #119 (HIGH) — contact_visibility was dead schema with a contract that
 * claimed enforced per-contact visibility filtering. Decision taken (per the
 * issue's option 2): remove the dead mechanism and rely on the platform's
 * EntityAccessService, rather than build a competing visibility vocabulary.
 *
 * These assertions fail if the dead schema/model/contract claims come back.
 */
class HR119ContactVisibilityRemovalTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext((string) Str::uuid());
        $this->assertTenantSchemaReady(['core_contacts']);
    }

    public function test_contact_visibility_model_is_removed(): void
    {
        $this->assertFalse(
            class_exists('Modules\Contact\src\Models\ContactVisibility'),
            'ContactVisibility model must stay removed (dead schema, issue #119)'
        );
    }

    public function test_contact_visibility_table_is_dropped(): void
    {
        $this->assertFalse(
            Schema::hasTable('contact_visibility'),
            'contact_visibility table must stay dropped (dead schema, issue #119)'
        );
    }

    public function test_core_contact_no_longer_exposes_a_visibility_relation(): void
    {
        $this->assertFalse(
            method_exists(CoreContact::class, 'visibility'),
            'CoreContact::visibility() must stay removed'
        );
    }

    public function test_module_contract_no_longer_declares_visibility_capability(): void
    {
        $contract = app(ContactModuleContract::class);

        $this->assertNotContains(
            'contacts.visibility.manage',
            $contract->getPermissions(),
            'The contract must not declare a permission for a capability that does not exist'
        );

        $this->assertNotContains(
            'Modules\Contact\src\Models\ContactVisibility',
            $contract->getOwnedEntities(),
            'The contract must not list the removed model as an owned entity'
        );
    }
}
