<?php

namespace Modules\Contact\src\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\ContactGroup;
use Modules\Contact\src\Models\ContactTaxonomy;
use Modules\Contact\src\Support\ContactTaxonomyRegistry;

/**
 * ADR-003A: controlled taxonomy governance for the Contact Master.
 *
 * System rows are materialized idempotently per tenant (schema-per-tenant
 * means migrations carry no tenant context). Reserved system codes cannot be
 * created, renamed or repurposed through this service (CT-VEN-015); tenant
 * administrators may only change display labels of existing system codes.
 */
class ContactTaxonomyService
{
    public const CLASSIFICATION_GROUP_TYPE = 'classification';

    /**
     * Materialize reserved taxonomy rows + classification groups for a tenant.
     * Idempotent; safe to call on every read/write path.
     */
    public function ensureSystemTaxonomy(string $tenantId): void
    {
        DB::transaction(function () use ($tenantId) {
            // Issue #123: seed the vocabulary this tenant is entitled to
            // (healthcare only for flagged tenants; generic otherwise).
            foreach ([
                ContactTaxonomyRegistry::TYPE_FUNCTIONAL_CATEGORY => ContactTaxonomyRegistry::vocabularyFor(ContactTaxonomyRegistry::TYPE_FUNCTIONAL_CATEGORY, $tenantId),
                ContactTaxonomyRegistry::TYPE_PERSISTENT_ROLE => ContactTaxonomyRegistry::vocabularyFor(ContactTaxonomyRegistry::TYPE_PERSISTENT_ROLE, $tenantId),
            ] as $type => $codes) {
                foreach ($codes as $code => $label) {
                    ContactTaxonomy::firstOrCreate(
                        ['tenant_id' => $tenantId, 'taxonomy_type' => $type, 'code' => $code],
                        [
                            'id' => Str::uuid(),
                            'display_label' => $label,
                            'is_system' => true,
                            'sort_order' => array_search($code, array_keys($codes)),
                        ]
                    );
                }
            }

            $classifications = ContactTaxonomyRegistry::vocabularyFor(ContactTaxonomyRegistry::TYPE_CLASSIFICATION, $tenantId);
            foreach ($classifications as $code => $label) {
                ContactGroup::firstOrCreate(
                    ['tenant_id' => $tenantId, 'group_name' => $code],
                    [
                        'id' => Str::uuid(),
                        'group_type' => self::CLASSIFICATION_GROUP_TYPE,
                        'description' => $label,
                        'sort_order' => array_search($code, array_keys($classifications)),
                    ]
                );
            }
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function list(string $tenantId, ?string $type = null): array
    {
        $this->ensureSystemTaxonomy($tenantId);

        $entries = [];

        $query = ContactTaxonomy::where('tenant_id', $tenantId);
        if ($type && $type !== ContactTaxonomyRegistry::TYPE_CLASSIFICATION) {
            $query->where('taxonomy_type', $type);
        }

        foreach ($query->orderBy('taxonomy_type')->orderBy('sort_order')->get() as $row) {
            $entries[] = [
                'taxonomy_type' => $row->taxonomy_type,
                'code' => $row->code,
                'display_label' => $row->display_label,
                'is_system' => $row->is_system,
            ];
        }

        if (! $type || $type === ContactTaxonomyRegistry::TYPE_CLASSIFICATION) {
            $groups = ContactGroup::where('tenant_id', $tenantId)
                ->where('group_type', self::CLASSIFICATION_GROUP_TYPE)
                ->orderBy('sort_order')
                ->get();
            foreach ($groups as $group) {
                $entries[] = [
                    'taxonomy_type' => ContactTaxonomyRegistry::TYPE_CLASSIFICATION,
                    'code' => $group->group_name,
                    'display_label' => $group->description ?: $group->group_name,
                    'is_system' => (bool) ($group->is_system ?? true),
                ];
            }
        }

        return $entries;
    }

    /**
     * Issue #123 (hostile re-review): a real API to add a tenant-owned
     * taxonomy code. Validation is explicit — code shape, non-empty label,
     * no collision with reserved or existing tenant codes.
     *
     * @return array{taxonomy_type: string, code: string, display_label: string, is_system: bool}
     */
    public function addCode(string $tenantId, string $type, string $code, string $label): array
    {
        $this->ensureSystemTaxonomy($tenantId);

        $allowedTypes = [
            ContactTaxonomyRegistry::TYPE_FUNCTIONAL_CATEGORY,
            ContactTaxonomyRegistry::TYPE_PERSISTENT_ROLE,
            ContactTaxonomyRegistry::TYPE_CLASSIFICATION,
        ];

        if (! in_array($type, $allowedTypes, true)) {
            throw new \DomainException('TAXONOMY_TYPE_INVALID');
        }

        $code = strtoupper(trim($code));
        if (! preg_match('/^[A-Z][A-Z0-9_]{1,49}$/', $code)) {
            throw new \DomainException('TAXONOMY_CODE_INVALID');
        }

        $label = trim($label);
        if ($label === '') {
            throw new \DomainException('TAXONOMY_LABEL_EMPTY');
        }

        return DB::transaction(function () use ($tenantId, $type, $code, $label) {
            if ($type === ContactTaxonomyRegistry::TYPE_CLASSIFICATION) {
                $exists = ContactGroup::where('tenant_id', $tenantId)
                    ->where('group_name', $code)
                    ->exists();

                if ($exists) {
                    throw new \DomainException('TAXONOMY_CODE_EXISTS');
                }

                ContactGroup::create([
                    'id' => Str::uuid(),
                    'tenant_id' => $tenantId,
                    'group_name' => $code,
                    'group_type' => self::CLASSIFICATION_GROUP_TYPE,
                    'description' => $label,
                    'sort_order' => 999,
                    'is_system' => false,
                ]);
            } else {
                $exists = ContactTaxonomy::where('tenant_id', $tenantId)
                    ->where('taxonomy_type', $type)
                    ->where('code', $code)
                    ->exists();

                if ($exists) {
                    throw new \DomainException('TAXONOMY_CODE_EXISTS');
                }

                ContactTaxonomy::create([
                    'id' => Str::uuid(),
                    'tenant_id' => $tenantId,
                    'taxonomy_type' => $type,
                    'code' => $code,
                    'display_label' => $label,
                    'is_system' => false,
                    'sort_order' => 999,
                ]);
            }

            return [
                'taxonomy_type' => $type,
                'code' => $code,
                'display_label' => $label,
                'is_system' => false,
            ];
        });
    }

    /**
     * Update the display label of an existing system code (CT-VEN-015).
     * Creating, renaming or repurposing reserved codes is rejected.
     */
    public function updateLabel(string $tenantId, string $type, string $code, string $label): void
    {
        $this->ensureSystemTaxonomy($tenantId);

        if (! ContactTaxonomyRegistry::isValid($type, $code)) {
            throw new \DomainException('TAXONOMY_CODE_NOT_RESERVED');
        }

        $label = trim($label);
        if ($label === '') {
            throw new \DomainException('TAXONOMY_LABEL_EMPTY');
        }

        if ($type === ContactTaxonomyRegistry::TYPE_CLASSIFICATION) {
            $affected = ContactGroup::where('tenant_id', $tenantId)
                ->where('group_type', self::CLASSIFICATION_GROUP_TYPE)
                ->where('group_name', $code)
                ->update(['description' => $label]);
        } else {
            $affected = ContactTaxonomy::where('tenant_id', $tenantId)
                ->where('taxonomy_type', $type)
                ->where('code', $code)
                ->update(['display_label' => $label]);
        }

        if (! $affected) {
            throw new \DomainException('TAXONOMY_CODE_NOT_FOUND');
        }
    }

    /**
     * Validate a code against the reserved register. Unknown values are
     * rejected — canonical codes are never invented (CT-VEN-016).
     */
    public function assertValidCode(string $type, ?string $code): string
    {
        if ($code === null || $code === '') {
            throw new \DomainException('UNKNOWN_TAXONOMY_VALUE');
        }

        $tenantId = function_exists('tenant') && tenant() ? (string) tenant()->id : null;

        // Issue #123: validation is data-driven — any code the tenant actually
        // has seeded is valid (tenants are extensible), otherwise fall back to
        // the vocabulary the tenant is entitled to (healthcare vs generic).
        if ($type === self::CLASSIFICATION_GROUP_TYPE) {
            $exists = ContactGroup::where('tenant_id', $tenantId)
                ->where('group_name', $code)
                ->exists();
        } else {
            $exists = ContactTaxonomy::where('tenant_id', $tenantId)
                ->where('taxonomy_type', $type)
                ->where('code', $code)
                ->exists();
        }

        if ($exists) {
            return $code;
        }

        if (array_key_exists($code, ContactTaxonomyRegistry::vocabularyFor($type, $tenantId))) {
            return $code;
        }

        throw new \DomainException('UNKNOWN_TAXONOMY_VALUE');
    }
}
