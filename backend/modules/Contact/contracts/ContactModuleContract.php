<?php

namespace Modules\Contact\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * Contact Master module contract (ADR 000 Pillar 3 — Module Contract adoption).
 *
 * Declares platform-level metadata: permissions, events, activation rules,
 * owned entities, and AI capabilities for the Contact Master service.
 */
class ContactModuleContract extends BaseModuleContract
{
    public function getKey(): string
    {
        return 'contact';
    }

    public function getName(): string
    {
        return 'Contact';
    }

    public function getType(): string
    {
        return 'core';
    }

    public function getDescription(): string
    {
        return 'Shared Contact Master: identity, roles, identifiers, addresses, methods, relationships, visibility, hierarchy, and module profiles.';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDependencies(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'contacts.view',
            'contacts.create',
            'contacts.edit',
            'contacts.delete',
            'contacts.search',
            'contacts.import',
            'contacts.merge',
            'contacts.roles.manage',
            'contacts.identifiers.manage',
            'contacts.addresses.manage',
            'contacts.methods.manage',
            'contacts.relationships.manage',
            'contacts.visibility.manage',
            'contacts.profiles.view',
            'contacts.profiles.manage',
            'contacts.bank_accounts.view',
            'contacts.bank_accounts.edit',
            'contacts.export',
        ];
    }

    public function getEvents(): array
    {
        return [
            'contact.created',
            'contact.updated',
            'contact.deleted',
            'contact.role.added',
            'contact.role.removed',
            'contact.identifier.added',
            'contact.address.added',
            'contact.method.added',
            'contact.relationship.added',
            'contact.merged',
            'contact.duplicate.detected',
            'contact.import.completed',
            'contact.profile.updated',
        ];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['contact_import_or_create'],
            'default_settings'     => ['default_currency' => 'USD'],
            'recommended_roles'    => ['admin', 'sales_rep'],
        ];
    }

    public function getActivationRules(): array
    {
        return [
            'requires'  => [],
            'settings'  => [],
            'min_tier'  => null,
        ];
    }

    public function getDeactivationRules(): array
    {
        return [
            'removes_data'       => false,
            'preserves_entities' => [
                \Modules\Contact\src\Models\CoreContact::class,
            ],
            'cleanup_jobs'       => [],
        ];
    }

    public function getOwnedEntities(): array
    {
        return [
            \Modules\Contact\src\Models\CoreContact::class,
            \Modules\Contact\src\Models\ContactRole::class,
            \Modules\Contact\src\Models\ContactIdentifier::class,
            \Modules\Contact\src\Models\ContactAddress::class,
            \Modules\Contact\src\Models\ContactMethod::class,
            \Modules\Contact\src\Models\ContactRelationship::class,
            \Modules\Contact\src\Models\ContactVisibility::class,
            \Modules\Contact\src\Models\ContactGroup::class,
            \Modules\Contact\src\Models\ContactGroupMember::class,
            \Modules\Contact\src\Models\ContactRegistration::class,
            \Modules\Contact\src\Models\ContactTaxProfile::class,
            \Modules\Contact\src\Models\ContactBankAccount::class,
            \Modules\Contact\src\Models\ContactSearchIndex::class,
            \Modules\Contact\src\Models\ContactRelatedRecord::class,
            \Modules\Contact\src\Models\ContactDuplicate::class,
            \Modules\Contact\src\Models\AccountingCustomerProfile::class,
            \Modules\Contact\src\Models\AccountingVendorProfile::class,
            \Modules\Contact\src\Models\CrmContactProfile::class,
            \Modules\Contact\src\Models\HrStaffProfile::class,
            \Modules\Contact\src\Models\PosLoyaltyProfile::class,
            \Modules\Contact\src\Models\PropertyContactProfile::class,
            \Modules\Contact\src\Models\ProjectContactProfile::class,
        ];
    }

    public function getExternalModeConfig(): array
    {
        return [
            'supports_coupled'        => true,
            'supports_decoupled'      => true,
            'supports_external'       => false,
            'external_api_endpoints'  => [],
        ];
    }

    public function getAICapabilities(): array
    {
        return [
            'has_ai_assistant' => false,
            'ai_suggestions'   => true,
            'ai_automation'    => false,
            'ai_data_access'   => ['summary'],
        ];
    }
}
