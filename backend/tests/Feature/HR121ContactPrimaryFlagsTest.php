<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\ContactAddress;
use Modules\Contact\src\Models\ContactMethod;
use Modules\Contact\src\Models\ContactSearchIndex;
use Modules\Contact\src\Services\ContactMasterService;
use Tests\TenantTestCase;

/**
 * Issue #121 — addresses/methods could accumulate multiple primary (and
 * default billing/shipping) flags; the search index used the first address in
 * insertion order rather than the primary one.
 */
class HR121ContactPrimaryFlagsTest extends TenantTestCase
{
    private string $tenantId;

    private string $contactId;

    private ContactMasterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->service = app(ContactMasterService::class);

        $this->contactId = DB::table('core_contacts')->insertGetId([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'display_name' => 'HR121 Contact',
            'contact_type' => 'individual',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id');
    }

    public function test_only_the_latest_primary_address_and_defaults_remain_set(): void
    {
        $first = $this->service->addAddress($this->contactId, [
            'address_type' => 'office', 'line_1' => 'First Street',
            'is_primary' => true, 'is_default_billing' => true, 'is_default_shipping' => true,
        ], $this->tenantId);

        $second = $this->service->addAddress($this->contactId, [
            'address_type' => 'office', 'line_1' => 'Second Street',
            'is_primary' => true, 'is_default_billing' => true, 'is_default_shipping' => true,
        ], $this->tenantId);

        $first = $first->fresh();
        $second = $second->fresh();

        $this->assertFalse((bool) $first->is_primary, 'Previous primary address must be unset');
        $this->assertFalse((bool) $first->is_default_billing);
        $this->assertFalse((bool) $first->is_default_shipping);
        $this->assertTrue((bool) $second->is_primary);
        $this->assertTrue((bool) $second->is_default_billing);
        $this->assertTrue((bool) $second->is_default_shipping);

        $this->assertSame(1, ContactAddress::where('contact_id', $this->contactId)->where('is_primary', true)->count());
        $this->assertSame(1, ContactAddress::where('contact_id', $this->contactId)->where('is_default_billing', true)->count());
        $this->assertSame(1, ContactAddress::where('contact_id', $this->contactId)->where('is_default_shipping', true)->count());
    }

    public function test_only_the_latest_primary_method_remains_set(): void
    {
        $first = $this->service->addMethod($this->contactId, [
            'method_type' => 'email', 'method_value' => 'first@example.com', 'is_primary' => true,
        ], $this->tenantId);
        $second = $this->service->addMethod($this->contactId, [
            'method_type' => 'email', 'method_value' => 'second@example.com', 'is_primary' => true,
        ], $this->tenantId);

        $this->assertFalse((bool) $first->fresh()->is_primary, 'Previous primary method must be unset');
        $this->assertTrue((bool) $second->fresh()->is_primary);
        $this->assertSame(1, ContactMethod::where('contact_id', $this->contactId)->where('is_primary', true)->count());
    }

    public function test_search_index_uses_the_primary_address_not_the_first_inserted(): void
    {
        $this->service->addAddress($this->contactId, [
            'address_type' => 'office', 'line_1' => 'First Street', 'city' => 'Firstville',
        ], $this->tenantId);
        $this->service->addAddress($this->contactId, [
            'address_type' => 'office', 'line_1' => 'Primary Street', 'city' => 'Primaryville',
            'is_primary' => true,
        ], $this->tenantId);

        $contact = \Modules\Contact\src\Models\CoreContact::find($this->contactId);
        $this->service->rebuildSearchIndex($contact->fresh('addresses'));

        $index = ContactSearchIndex::where('contact_id', $this->contactId)->first();
        $this->assertNotNull($index);
        $this->assertStringContainsString('Primary Street', (string) $index->address_text);
        $this->assertStringNotContainsString('First Street', (string) $index->address_text,
            'Search index must carry the primary address, not the first inserted');
    }
}
