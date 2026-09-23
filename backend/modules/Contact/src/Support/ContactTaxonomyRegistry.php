<?php

namespace Modules\Contact\src\Support;

/**
 * ADR-003A Section 4: reserved system codes for Acme contact semantics.
 *
 * These codes are platform-reserved (CT-VEN-015). Tenant administrators may
 * change display labels but SHALL NOT create, rename or repurpose them; new
 * canonical codes require controlled platform governance.
 */
class ContactTaxonomyRegistry
{
    public const TYPE_FUNCTIONAL_CATEGORY = 'functional_category';

    public const TYPE_PERSISTENT_ROLE = 'persistent_role';

    public const TYPE_CLASSIFICATION = 'classification';

    /** ADR-003A §4.2 — Functional Category (relationship-scoped broad function). */
    public const FUNCTIONAL_CATEGORIES = [
        'CLINICAL' => 'Clinical',
        'FINANCE' => 'Finance',
        'PROCUREMENT' => 'Procurement',
        'OPERATIONS' => 'Operations',
        'ADMINISTRATION' => 'Administration',
        'OTHER' => 'Other',
    ];

    /** ADR-003A §4.5 — Persistent Relationship Roles. */
    public const PERSISTENT_ROLES = [
        'BILLING_CONTACT' => 'Billing Contact',
        'DELIVERY_CONTACT' => 'Delivery Contact',
        'PROCUREMENT_CONTACT' => 'Procurement Contact',
        'CLINICAL_CONTACT' => 'Clinical Contact',
        'OT_CONTACT' => 'OT Contact',
        'ADMIN_CONTACT' => 'Administrative Contact',
        'OTHER' => 'Other',
    ];

    /** Issue #123: generic business vocabulary for non-healthcare tenants. */
    public const GENERIC_FUNCTIONAL_CATEGORIES = [
        'FINANCE' => 'Finance',
        'PROCUREMENT' => 'Procurement',
        'OPERATIONS' => 'Operations',
        'ADMINISTRATION' => 'Administration',
        'OTHER' => 'Other',
    ];

    public const GENERIC_PERSISTENT_ROLES = [
        'BILLING_CONTACT' => 'Billing Contact',
        'DELIVERY_CONTACT' => 'Delivery Contact',
        'PROCUREMENT_CONTACT' => 'Procurement Contact',
        'ADMIN_CONTACT' => 'Administrative Contact',
        'OTHER' => 'Other',
    ];

    public const GENERIC_CLASSIFICATIONS = [
        'OTHER' => 'Other',
    ];

    /**
     * Issue #123: only the tenants listed in config('contact.healthcare_tenant_ids')
     * receive the healthcare vocabulary; everyone else gets the generic set.
     */
    public static function usesHealthcareVocabulary(?string $tenantId): bool
    {
        if ($tenantId === null) {
            return false;
        }

        return in_array($tenantId, (array) config('contact.healthcare_tenant_ids', []), true);
    }

    /**
     * The vocabulary seeded for a tenant + type.
     *
     * @return array<string, string>
     */
    public static function vocabularyFor(string $type, ?string $tenantId): array
    {
        $healthcare = self::usesHealthcareVocabulary($tenantId);

        return match ($type) {
            self::TYPE_FUNCTIONAL_CATEGORY => $healthcare
                ? self::FUNCTIONAL_CATEGORIES
                : self::GENERIC_FUNCTIONAL_CATEGORIES,
            self::TYPE_PERSISTENT_ROLE => $healthcare
                ? self::PERSISTENT_ROLES
                : self::GENERIC_PERSISTENT_ROLES,
            self::TYPE_CLASSIFICATION => $healthcare
                ? self::CLASSIFICATIONS
                : self::GENERIC_CLASSIFICATIONS,
            default => [],
        };
    }

    /** ADR-003A §4.3 — Contact Classification (profession/person class only). */
    public const CLASSIFICATIONS = [
        'DOCTOR' => 'Doctor',
        'NURSE' => 'Nurse',
        'OTHER' => 'Other',
    ];

    /** ADR-003A §7.2 — legacy read projection precedence. */
    public const CLASSIFICATION_READ_PRECEDENCE = ['DOCTOR', 'NURSE', 'OTHER'];

    public const PERSISTENT_ROLE_READ_PRECEDENCE = [
        'BILLING_CONTACT',
        'PROCUREMENT_CONTACT',
        'DELIVERY_CONTACT',
        'CLINICAL_CONTACT',
        'OT_CONTACT',
        'ADMIN_CONTACT',
        'OTHER',
    ];

    /** Default functional category per classification (§4.3). */
    public const CLASSIFICATION_DEFAULT_CATEGORY = [
        'DOCTOR' => 'CLINICAL',
        'NURSE' => 'CLINICAL',
        'OTHER' => 'OTHER',
    ];

    /** Typical functional category per persistent role (§4.5). */
    public const ROLE_DEFAULT_CATEGORY = [
        'BILLING_CONTACT' => 'FINANCE',
        'DELIVERY_CONTACT' => 'OPERATIONS',
        'PROCUREMENT_CONTACT' => 'PROCUREMENT',
        'CLINICAL_CONTACT' => 'CLINICAL',
        'OT_CONTACT' => 'OPERATIONS',
        'ADMIN_CONTACT' => 'ADMINISTRATION',
        'OTHER' => 'OTHER',
    ];

    /** Transaction-party roles (ADR-003A §4.6) — never persistent Contact roles. */
    public const TRANSACTION_ROLES = [
        'REQUESTOR',
        'BUYER',
        'DOCTOR_ON_CASE',
        'DELIVERY_PERSON',
        'INVOICE_ATTENTION',
    ];

    public static function isValid(string $type, string $code): bool
    {
        return array_key_exists($code, self::codesFor($type));
    }

    public static function codesFor(string $type): array
    {
        return match ($type) {
            self::TYPE_FUNCTIONAL_CATEGORY => self::FUNCTIONAL_CATEGORIES,
            self::TYPE_PERSISTENT_ROLE => self::PERSISTENT_ROLES,
            self::TYPE_CLASSIFICATION => self::CLASSIFICATIONS,
            default => [],
        };
    }

    /**
     * Resolve a human value (code or label, any case) to a stable code.
     * Returns null when the value does not map to a reserved code — callers
     * SHALL NOT invent codes from unknown text (CT-VEN-016).
     */
    public static function resolveCode(string $type, ?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $codes = self::codesFor($type);
        $normalized = strtoupper(str_replace([' ', '-'], '_', $value));
        if (isset($codes[$normalized])) {
            return $normalized;
        }

        foreach ($codes as $code => $label) {
            if (strcasecmp($label, $value) === 0) {
                return $code;
            }
        }

        return null;
    }
}
