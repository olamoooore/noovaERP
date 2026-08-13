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
            foreach ([
                ContactTaxonomyRegistry::TYPE_FUNCTIONAL_CATEGORY => ContactTaxonomyRegistry::FUNCTIONAL_CATEGORIES,
                ContactTaxonomyRegistry::TYPE_PERSISTENT_ROLE => ContactTaxonomyRegistry::PERSISTENT_ROLES,
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

            foreach (ContactTaxonomyRegistry::CLASSIFICATIONS as $code => $label) {
                ContactGroup::firstOrCreate(
                    ['tenant_id' => $tenantId, 'group_name' => $code],
                    [
                        'id' => Str::uuid(),
                        'group_type' => self::CLASSIFICATION_GROUP_TYPE,
                        'description' => $label,
                        'sort_order' => array_search($code, array_keys(ContactTaxonomyRegistry::CLASSIFICATIONS)),
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
                    'is_system' => true,
                ];
            }
        }

        return $entries;
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
        $resolved = ContactTaxonomyRegistry::resolveCode($type, $code);
        if ($resolved === null) {
            throw new \DomainException('UNKNOWN_TAXONOMY_VALUE');
        }

        return $resolved;
    }
}
