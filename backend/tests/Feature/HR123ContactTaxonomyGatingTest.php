<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Modules\Contact\src\Models\ContactTaxonomy;
use Modules\Contact\src\Services\ContactTaxonomyService;
use Modules\Contact\src\Support\ContactTaxonomyRegistry;
use Tests\TenantTestCase;

/**
 * Issue #123 — the healthcare vocabulary (DOCTOR/NURSE/CLINICAL/OT_CONTACT) is
 * no longer forced onto every tenant: only flagged tenants receive it, everyone
 * else gets the generic business vocabulary, and tenants can seed their own
 * codes (validation is data-driven).
 */
class HR123ContactTaxonomyGatingTest extends TenantTestCase
{
    private ContactTaxonomyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ContactTaxonomyService::class);
    }

    public function test_generic_tenant_gets_generic_vocabulary_not_healthcare(): void
    {
        $tenantId = (string) Str::uuid();
        $this->bindTenantContext($tenantId);

        $this->service->ensureSystemTaxonomy($tenantId);

        $codes = ContactTaxonomy::where('tenant_id', $tenantId)
            ->where('taxonomy_type', ContactTaxonomyRegistry::TYPE_FUNCTIONAL_CATEGORY)
            ->pluck('code')
            ->all();

        $this->assertContains('OPERATIONS', $codes, 'Generic tenants get the business vocabulary');
        $this->assertNotContains('CLINICAL', $codes, 'Healthcare codes must not leak to generic tenants');

        // Healthcare codes are rejected for this tenant…
        try {
            $this->service->assertValidCode(ContactTaxonomyRegistry::TYPE_FUNCTIONAL_CATEGORY, 'CLINICAL');
            $this->fail('CLINICAL must be rejected for a non-healthcare tenant');
        } catch (\DomainException $e) {
            $this->assertSame('UNKNOWN_TAXONOMY_VALUE', $e->getMessage());
        }

        // …while generic codes are accepted.
        $this->assertSame('OPERATIONS', $this->service->assertValidCode(
            ContactTaxonomyRegistry::TYPE_FUNCTIONAL_CATEGORY, 'OPERATIONS'
        ));

        // Classification gating: DOCTOR is not in the generic set.
        try {
            $this->service->assertValidCode(ContactTaxonomyRegistry::TYPE_CLASSIFICATION, 'DOCTOR');
            $this->fail('DOCTOR must be rejected for a non-healthcare tenant');
        } catch (\DomainException $e) {
            $this->assertSame('UNKNOWN_TAXONOMY_VALUE', $e->getMessage());
        }
    }

    public function test_tenants_are_extensible_with_their_own_codes(): void
    {
        $tenantId = (string) Str::uuid();
        $this->bindTenantContext($tenantId);
        $this->service->ensureSystemTaxonomy($tenantId);

        // A tenant seeds its own persistent role (e.g. retail).
        ContactTaxonomy::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'taxonomy_type' => ContactTaxonomyRegistry::TYPE_PERSISTENT_ROLE,
            'code' => 'RETAIL_MANAGER',
            'display_label' => 'Retail Manager',
            'is_system' => false,
            'sort_order' => 99,
        ]);

        $this->assertSame('RETAIL_MANAGER', $this->service->assertValidCode(
            ContactTaxonomyRegistry::TYPE_PERSISTENT_ROLE, 'RETAIL_MANAGER'
        ));
    }

    public function test_flagged_healthcare_tenant_still_gets_the_healthcare_vocabulary(): void
    {
        $tenantId = (string) Str::uuid();
        config(['contact.healthcare_tenant_ids' => [$tenantId]]);
        $this->bindTenantContext($tenantId);

        $this->service->ensureSystemTaxonomy($tenantId);

        $codes = ContactTaxonomy::where('tenant_id', $tenantId)
            ->where('taxonomy_type', ContactTaxonomyRegistry::TYPE_FUNCTIONAL_CATEGORY)
            ->pluck('code')
            ->all();

        $this->assertContains('CLINICAL', $codes);
        $this->assertSame('CLINICAL', $this->service->assertValidCode(
            ContactTaxonomyRegistry::TYPE_FUNCTIONAL_CATEGORY, 'CLINICAL'
        ));
        $this->assertSame('DOCTOR', $this->service->assertValidCode(
            ContactTaxonomyRegistry::TYPE_CLASSIFICATION, 'DOCTOR'
        ));
    }
}
