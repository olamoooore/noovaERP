<?php

declare(strict_types=1);

namespace Tests;

use Modules\Contact\src\Support\ContactTaxonomyRegistry;
use Modules\Contact\src\Support\LegacyContactTypeMapping;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for LegacyContactTypeMapping — ADR-003A §7.2/§8.1.
 *
 * Tests deterministic legacy contact_type/contact_category transforms.
 */
class LegacyContactTypeMappingTest extends TestCase
{
    // ─── transformContactType ──────────────────────────────────────

    #[Test]
    public function primary_is_crm_designation_not_role(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('Primary');
        $this->assertTrue($result['is_primary']);
        $this->assertNull($result['classification']);
        $this->assertNull($result['persistent_role']);
        $this->assertFalse($result['unknown']);
    }

    #[Test]
    public function primary_contact_alias(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('Primary Contact');
        $this->assertTrue($result['is_primary']);
    }

    #[Test]
    public function doctor_maps_to_classification(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('Doctor');
        $this->assertEquals('DOCTOR', $result['classification']);
        $this->assertEquals('CLINICAL', $result['functional_category']);
        $this->assertFalse($result['unknown']);
    }

    #[Test]
    public function dr_alias_maps_to_doctor(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('Dr');
        $this->assertEquals('DOCTOR', $result['classification']);
    }

    #[Test]
    public function physician_alias_maps_to_doctor(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('Physician');
        $this->assertEquals('DOCTOR', $result['classification']);
    }

    #[Test]
    public function nurse_maps_to_classification(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('Nurse');
        $this->assertEquals('NURSE', $result['classification']);
        $this->assertEquals('CLINICAL', $result['functional_category']);
    }

    #[Test]
    public function sister_alias_maps_to_nurse(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('Sister');
        $this->assertEquals('NURSE', $result['classification']);
    }

    #[Test]
    public function billing_maps_to_persistent_role(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('Billing');
        $this->assertEquals('BILLING_CONTACT', $result['persistent_role']);
        $this->assertEquals('FINANCE', $result['functional_category']);
        $this->assertFalse($result['unknown']);
    }

    #[Test]
    public function delivery_maps_to_persistent_role(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('Delivery');
        $this->assertEquals('DELIVERY_CONTACT', $result['persistent_role']);
        $this->assertEquals('OPERATIONS', $result['functional_category']);
    }

    #[Test]
    public function ot_maps_to_persistent_role(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('OT');
        $this->assertEquals('OT_CONTACT', $result['persistent_role']);
    }

    #[Test]
    public function clinical_maps_to_persistent_role(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('Clinical');
        $this->assertEquals('CLINICAL_CONTACT', $result['persistent_role']);
        $this->assertEquals('CLINICAL', $result['functional_category']);
    }

    #[Test]
    public function procurement_maps_to_persistent_role(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('Procurement');
        $this->assertEquals('PROCUREMENT_CONTACT', $result['persistent_role']);
        $this->assertEquals('PROCUREMENT', $result['functional_category']);
    }

    #[Test]
    public function requestor_is_transaction_only(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('Requestor');
        $this->assertEquals('REQUESTOR', $result['transaction_role']);
        $this->assertNull($result['persistent_role']);
        $this->assertNull($result['classification']);
    }

    #[Test]
    public function buyer_is_transaction_only(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('Buyer');
        $this->assertEquals('BUYER', $result['transaction_role']);
        $this->assertNull($result['persistent_role']);
    }

    #[Test]
    public function doctor_on_case_is_transaction_only(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('Doctor on Case');
        $this->assertEquals('DOCTOR_ON_CASE', $result['transaction_role']);
        $this->assertNull($result['classification']);
    }

    #[Test]
    public function unknown_value_flagged(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('RandomThing');
        $this->assertTrue($result['unknown']);
    }

    #[Test]
    public function empty_value_returns_defaults(): void
    {
        $result = LegacyContactTypeMapping::transformContactType('');
        $this->assertFalse($result['unknown']);
        $this->assertFalse($result['is_primary']);
        $this->assertNull($result['classification']);
        $this->assertNull($result['persistent_role']);
        $this->assertNull($result['transaction_role']);
    }

    #[Test]
    public function null_value_returns_defaults(): void
    {
        $result = LegacyContactTypeMapping::transformContactType(null);
        $this->assertFalse($result['unknown']);
    }

    // ─── resolveFunctionalCategory ─────────────────────────────────

    #[Test]
    public function resolve_category_clinical(): void
    {
        $this->assertEquals('CLINICAL', LegacyContactTypeMapping::resolveFunctionalCategory('Clinical'));
    }

    #[Test]
    public function resolve_category_finance(): void
    {
        $this->assertEquals('FINANCE', LegacyContactTypeMapping::resolveFunctionalCategory('Finance'));
    }

    #[Test]
    public function resolve_category_accounts_payable(): void
    {
        $this->assertEquals('FINANCE', LegacyContactTypeMapping::resolveFunctionalCategory('Accounts Payable'));
    }

    #[Test]
    public function resolve_category_accounting(): void
    {
        $this->assertEquals('FINANCE', LegacyContactTypeMapping::resolveFunctionalCategory('Accounting'));
    }

    #[Test]
    public function resolve_category_procurement(): void
    {
        $this->assertEquals('PROCUREMENT', LegacyContactTypeMapping::resolveFunctionalCategory('Procurement'));
    }

    #[Test]
    public function resolve_category_purchasing(): void
    {
        $this->assertEquals('PROCUREMENT', LegacyContactTypeMapping::resolveFunctionalCategory('Purchasing'));
    }

    #[Test]
    public function resolve_category_operations(): void
    {
        $this->assertEquals('OPERATIONS', LegacyContactTypeMapping::resolveFunctionalCategory('Operations'));
    }

    #[Test]
    public function resolve_category_logistics(): void
    {
        $this->assertEquals('OPERATIONS', LegacyContactTypeMapping::resolveFunctionalCategory('Logistics'));
    }

    #[Test]
    public function resolve_category_admin(): void
    {
        $this->assertEquals('ADMINISTRATION', LegacyContactTypeMapping::resolveFunctionalCategory('Admin'));
    }

    #[Test]
    public function resolve_category_other(): void
    {
        $this->assertEquals('OTHER', LegacyContactTypeMapping::resolveFunctionalCategory('Other'));
    }

    #[Test]
    public function resolve_category_unknown_returns_null(): void
    {
        $this->assertNull(LegacyContactTypeMapping::resolveFunctionalCategory('Unknown'));
    }

    #[Test]
    public function resolve_category_empty_returns_null(): void
    {
        $this->assertNull(LegacyContactTypeMapping::resolveFunctionalCategory(''));
    }

    // ─── projectLegacyContactType ──────────────────────────────────

    #[Test]
    public function project_doctor_precedence(): void
    {
        // DOCTOR > NURSE > OTHER
        $this->assertEquals('Doctor', LegacyContactTypeMapping::projectLegacyContactType(['NURSE', 'DOCTOR'], []));
    }

    #[Test]
    public function project_nurse(): void
    {
        $this->assertEquals('Nurse', LegacyContactTypeMapping::projectLegacyContactType(['NURSE'], []));
    }

    #[Test]
    public function project_other_classification(): void
    {
        $this->assertEquals('Other', LegacyContactTypeMapping::projectLegacyContactType(['OTHER'], []));
    }

    #[Test]
    public function project_billing_role_precedence(): void
    {
        $this->assertEquals('Billing Contact', LegacyContactTypeMapping::projectLegacyContactType([], ['DELIVERY_CONTACT', 'BILLING_CONTACT']));
    }

    #[Test]
    public function project_procurement_role(): void
    {
        $this->assertEquals('Procurement Contact', LegacyContactTypeMapping::projectLegacyContactType([], ['PROCUREMENT_CONTACT']));
    }

    #[Test]
    public function project_delivery_role(): void
    {
        $this->assertEquals('Delivery Contact', LegacyContactTypeMapping::projectLegacyContactType([], ['DELIVERY_CONTACT']));
    }

    #[Test]
    public function project_defaults_to_other(): void
    {
        $this->assertEquals('Other', LegacyContactTypeMapping::projectLegacyContactType([], []));
    }

    #[Test]
    public function project_classification_over_role(): void
    {
        // Classification takes precedence over role
        $this->assertEquals('Doctor', LegacyContactTypeMapping::projectLegacyContactType(['DOCTOR'], ['BILLING_CONTACT']));
    }

    // ─── ContactTaxonomyRegistry ───────────────────────────────────

    #[Test]
    public function taxonomy_valid_codes(): void
    {
        $this->assertTrue(ContactTaxonomyRegistry::isValid('functional_category', 'CLINICAL'));
        $this->assertTrue(ContactTaxonomyRegistry::isValid('persistent_role', 'BILLING_CONTACT'));
        $this->assertTrue(ContactTaxonomyRegistry::isValid('classification', 'DOCTOR'));
    }

    #[Test]
    public function taxonomy_invalid_codes(): void
    {
        $this->assertFalse(ContactTaxonomyRegistry::isValid('functional_category', 'INVALID'));
        $this->assertFalse(ContactTaxonomyRegistry::isValid('persistent_role', 'INVALID'));
        $this->assertFalse(ContactTaxonomyRegistry::isValid('classification', 'INVALID'));
    }

    #[Test]
    public function taxonomy_resolve_code_by_label(): void
    {
        $this->assertEquals('CLINICAL', ContactTaxonomyRegistry::resolveCode('functional_category', 'Clinical'));
        $this->assertEquals('BILLING_CONTACT', ContactTaxonomyRegistry::resolveCode('persistent_role', 'Billing Contact'));
        $this->assertEquals('DOCTOR', ContactTaxonomyRegistry::resolveCode('classification', 'Doctor'));
    }

    #[Test]
    public function taxonomy_resolve_code_by_uppercase(): void
    {
        $this->assertEquals('CLINICAL', ContactTaxonomyRegistry::resolveCode('functional_category', 'CLINICAL'));
    }

    #[Test]
    public function taxonomy_resolve_unknown_returns_null(): void
    {
        $this->assertNull(ContactTaxonomyRegistry::resolveCode('functional_category', 'Unknown'));
    }

    #[Test]
    public function taxonomy_transaction_roles_not_in_persistent(): void
    {
        foreach (ContactTaxonomyRegistry::TRANSACTION_ROLES as $role) {
            $this->assertArrayNotHasKey($role, ContactTaxonomyRegistry::PERSISTENT_ROLES);
        }
    }

    #[Test]
    public function all_classifications_have_default_category(): void
    {
        foreach (ContactTaxonomyRegistry::CLASSIFICATIONS as $code => $label) {
            $this->assertArrayHasKey($code, ContactTaxonomyRegistry::CLASSIFICATION_DEFAULT_CATEGORY);
        }
    }

    #[Test]
    public function all_persistent_roles_have_default_category(): void
    {
        foreach (ContactTaxonomyRegistry::PERSISTENT_ROLES as $code => $label) {
            $this->assertArrayHasKey($code, ContactTaxonomyRegistry::ROLE_DEFAULT_CATEGORY);
        }
    }
}
