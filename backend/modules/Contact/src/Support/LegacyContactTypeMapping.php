<?php

namespace Modules\Contact\src\Support;

/**
 * ADR-003A §7.2/§8.1 — deterministic legacy contact_type/contact_category
 * compatibility transforms.
 *
 * Legacy fields are NON-authoritative facades over canonical semantics:
 *   - Functional Category (relationship-scoped)
 *   - Contact Classification (profession only: DOCTOR/NURSE/OTHER)
 *   - Persistent Relationship Roles
 *   - CRM is_primary designation
 *   - Transaction-party context (never persisted as Contact roles)
 *
 * The mapping here is fixed by the ADR and SHALL NOT be reinterpreted by
 * individual clients.
 */
class LegacyContactTypeMapping
{
    /**
     * Transform a legacy contact_type value into canonical actions.
     *
     * Returns an array with keys:
     *   classification      ?string  canonical classification code
     *   functional_category ?string  canonical functional category code
     *   persistent_role     ?string  canonical persistent role code
     *   is_primary          bool     CRM primary designation
     *   transaction_role    ?string  preserved transaction/source context only
     *   unknown             bool     true when no approved mapping exists
     */
    public static function transformContactType(?string $value): array
    {
        $result = [
            'classification' => null,
            'functional_category' => null,
            'persistent_role' => null,
            'is_primary' => false,
            'transaction_role' => null,
            'unknown' => false,
        ];

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $result;
        }

        // Primary is a CRM designation, never a role/classification (CT-VEN-006).
        if (in_array($normalized, ['primary', 'primary contact'], true)) {
            $result['is_primary'] = true;

            return $result;
        }

        // Profession values -> Classification + default CLINICAL category (§8.1).
        if (in_array($normalized, ['doctor', 'dr', 'physician'], true)) {
            $result['classification'] = 'DOCTOR';
            $result['functional_category'] = 'CLINICAL';

            return $result;
        }
        if (in_array($normalized, ['nurse', 'sister', 'staff nurse'], true)) {
            $result['classification'] = 'NURSE';
            $result['functional_category'] = 'CLINICAL';

            return $result;
        }

        // Persistent function values -> Persistent Relationship Role.
        $persistentMap = [
            'billing' => 'BILLING_CONTACT',
            'billing contact' => 'BILLING_CONTACT',
            'delivery' => 'DELIVERY_CONTACT',
            'delivery contact' => 'DELIVERY_CONTACT',
            'ot' => 'OT_CONTACT',
            'ot contact' => 'OT_CONTACT',
            'operating theatre' => 'OT_CONTACT',
            'clinical' => 'CLINICAL_CONTACT',
            'clinical contact' => 'CLINICAL_CONTACT',
            'admin' => 'ADMIN_CONTACT',
            'admin contact' => 'ADMIN_CONTACT',
            'administrative contact' => 'ADMIN_CONTACT',
            'procurement' => 'PROCUREMENT_CONTACT',
            'procurement contact' => 'PROCUREMENT_CONTACT',
        ];
        if (isset($persistentMap[$normalized])) {
            $result['persistent_role'] = $persistentMap[$normalized];
            $result['functional_category'] = ContactTaxonomyRegistry::ROLE_DEFAULT_CATEGORY[$persistentMap[$normalized]];

            return $result;
        }

        // Transaction-only values -> staged/source context, never permanent
        // roles (CT-VEN-009). Buyer becomes persistent only when the source
        // explicitly identifies an ongoing procurement contact.
        $transactionMap = [
            'requestor' => 'REQUESTOR',
            'buyer' => 'BUYER',
            'doctor on case' => 'DOCTOR_ON_CASE',
            'delivery person' => 'DELIVERY_PERSON',
            'invoice attention' => 'INVOICE_ATTENTION',
        ];
        if (isset($transactionMap[$normalized])) {
            $result['transaction_role'] = $transactionMap[$normalized];

            return $result;
        }

        // Unknown controlled value — never invent a canonical code.
        $result['unknown'] = true;

        return $result;
    }

    /**
     * Resolve a legacy contact_category value to a canonical Functional
     * Category code. Returns null when unmapped (warn, do not invent).
     */
    public static function resolveFunctionalCategory(?string $value): ?string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        $aliases = [
            'clinical' => 'CLINICAL',
            'finance' => 'FINANCE',
            'accounts payable' => 'FINANCE',
            'accounting' => 'FINANCE',
            'procurement' => 'PROCUREMENT',
            'purchasing' => 'PROCUREMENT',
            'materials management' => 'PROCUREMENT',
            'operations' => 'OPERATIONS',
            'logistics' => 'OPERATIONS',
            'administration' => 'ADMINISTRATION',
            'admin' => 'ADMINISTRATION',
            'office' => 'ADMINISTRATION',
            'other' => 'OTHER',
        ];

        return $aliases[$normalized] ?? ContactTaxonomyRegistry::resolveCode(
            ContactTaxonomyRegistry::TYPE_FUNCTIONAL_CATEGORY,
            $value
        );
    }

    /**
     * ADR-003A §7.2 read projection (legacy scalar only).
     *
     * 1) if a classification exists, return it using precedence
     *    DOCTOR > NURSE > OTHER;
     * 2) otherwise return the highest-priority persistent role using
     *    BILLING > PROCUREMENT > DELIVERY > CLINICAL > OT > ADMIN > OTHER;
     * 3) otherwise OTHER.
     */
    public static function projectLegacyContactType(array $classificationCodes, array $persistentRoleCodes): string
    {
        foreach (ContactTaxonomyRegistry::CLASSIFICATION_READ_PRECEDENCE as $code) {
            if (in_array($code, $classificationCodes, true)) {
                return ContactTaxonomyRegistry::CLASSIFICATIONS[$code];
            }
        }

        foreach (ContactTaxonomyRegistry::PERSISTENT_ROLE_READ_PRECEDENCE as $code) {
            if (in_array($code, $persistentRoleCodes, true)) {
                return ContactTaxonomyRegistry::PERSISTENT_ROLES[$code];
            }
        }

        return ContactTaxonomyRegistry::PERSISTENT_ROLES['OTHER'];
    }
}
