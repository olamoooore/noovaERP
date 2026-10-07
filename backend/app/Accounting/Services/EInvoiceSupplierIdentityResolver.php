<?php

namespace App\Accounting\Services;

use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\LegalEntity;
use App\Models\Payment;
use App\Services\LegalEntityContextResolver;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #668 (EI6): the e-invoice supplier identity and the country gate
 * belong to the document's legal entity, not to the tenant.
 *
 * The entity is resolved through the platform LegalEntityContextResolver
 * (#875) from the document's own pointers (entity column, sales order,
 * invoice, customer). When the entity carries its own statutory identity
 * (TIN or registration number), its letterhead identifiers (#612 — name, TIN,
 * registration number, SST/GST number, address, phone, email) are laid over
 * the tenant-level CountryConfiguration provider context; statutory
 * identifiers it lacks are left empty rather than borrowed from the tenant.
 * An entity without a statutory identity (or no entity) uses the tenant
 * configuration. Every tenant value used is reported as a fallback.
 *
 * Only context keys the existing country packs already read are written; the
 * provider credentials stay tenant-level (there is no entity-level credential
 * storage) and are always reported as a tenant fallback.
 */
class EInvoiceSupplierIdentityResolver
{
    public const SOURCE_LEGAL_ENTITY = 'legal_entity';

    public const SOURCE_TENANT_CONFIGURATION = 'tenant_configuration';

    /**
     * Provider-context key => legal_entities column.
     *
     * @var array<string, string>
     */
    private const ENTITY_FIELDS = [
        'supplier_name' => 'name',
        'supplier_tin' => 'tax_id',
        'supplier_registration_number' => 'registration_number',
        'supplier_sst_registration_number' => 'sst_registration_number',
        'supplier_address' => 'address',
        'supplier_phone' => 'phone',
        'supplier_email' => 'email',
    ];

    /**
     * The statutory identifiers. They are never mixed between the entity and
     * the tenant: once the entity carries its own TIN or registration number,
     * a statutory identifier it lacks is left empty (the pack's validator
     * then refuses the document) instead of borrowing the tenant's value,
     * which would pair one company's TIN with another company's BRN.
     *
     * @var list<string>
     */
    private const STATUTORY_FIELDS = [
        'supplier_tin',
        'supplier_registration_number',
        'supplier_id_type',
        'supplier_id_number',
        'supplier_sst_registration_number',
    ];

    /**
     * Identity keys the tenant configuration may carry that have no
     * entity-level storage; when used they are always a tenant fallback.
     *
     * @var list<string>
     */
    private const TENANT_ONLY_FIELDS = [
        'supplier_city',
        'supplier_state',
        'supplier_postal_code',
        'supplier_msic_code',
        'supplier_business_activity_description',
    ];

    public function __construct(private readonly LegalEntityContextResolver $entities) {}

    /**
     * The legal entity that issued the document, when one can be resolved.
     */
    public function entityFor(mixed $source): ?LegalEntity
    {
        if (! is_object($source) || ! Schema::hasTable('legal_entities')) {
            return null;
        }

        $entityId = match (true) {
            $source instanceof Invoice => $this->entities->resolveEntity(
                explicit: $this->intOrNull($source->legal_entity_id ?? null),
                salesOrderId: $this->intOrNull($source->sales_order_id ?? null),
                invoiceId: $this->intOrNull($source->id ?? null),
                customerId: $this->intOrNull($source->customer_id ?? null),
                required: false,
            ),
            $source instanceof CustomerCreditNote, $source instanceof Payment => $this->entities->resolveEntity(
                explicit: $this->intOrNull($source->legal_entity_id ?? null),
                salesOrderId: $this->intOrNull($source->sales_order_id ?? null),
                invoiceId: $this->intOrNull($source->invoice_id ?? null),
                customerId: $this->intOrNull($source->customer_id ?? null),
                required: false,
            ),
            default => null,
        };

        return $entityId ? LegalEntity::query()->find($entityId) : null;
    }

    /**
     * The country the document is issued from: its entity's country, or null
     * when no entity (or no entity country) is known.
     */
    public function countryFor(mixed $source): ?string
    {
        $country = strtoupper(trim((string) ($this->entityFor($source)?->country_code ?? '')));

        return $country !== '' ? $country : null;
    }

    /**
     * Lay the document entity's supplier identity over the tenant provider
     * context.
     *
     * @param  array<string, mixed>  $tenantContext  CountryConfiguration::getProviderContext()
     * @return array{context: array<string, mixed>, report: array<string, mixed>}
     */
    public function resolve(mixed $source, array $tenantContext): array
    {
        $entity = $this->entityFor($source);
        $context = $tenantContext;
        $entityFields = [];
        $missingFields = [];

        // The entity is the supplier only when it carries its own statutory
        // identity (TIN or registration number). An entity without one is
        // submitted under the tenant configuration as a whole — its name or
        // address is not paired with another company's TIN.
        $entityOwnsStatutoryIdentity = $entity !== null && (
            trim((string) ($entity->tax_id ?? '')) !== ''
            || trim((string) ($entity->registration_number ?? '')) !== ''
        );

        if ($entityOwnsStatutoryIdentity) {
            foreach (self::ENTITY_FIELDS as $key => $column) {
                $value = trim((string) ($entity->{$column} ?? ''));
                if ($value !== '') {
                    $context[$key] = $value;
                    $entityFields[] = $key;
                }
            }

            // The registration number is the supplier's identity document.
            // Keep the id type/number pair consistent with it instead of
            // pairing the entity's registration with the tenant's id number
            // (same BRN inference the MyInvois mapper applies to parties).
            if (in_array('supplier_registration_number', $entityFields, true)) {
                $context['supplier_id_type'] = 'BRN';
                $context['supplier_id_number'] = $context['supplier_registration_number'];
                $entityFields[] = 'supplier_id_type';
                $entityFields[] = 'supplier_id_number';
            }

            foreach (self::STATUTORY_FIELDS as $key) {
                if (in_array($key, $entityFields, true)) {
                    continue;
                }

                // SST registration is optional (non-registered suppliers);
                // it is only reported when a tenant value was withheld.
                $tenantHadValue = trim((string) ($tenantContext[$key] ?? '')) !== '';
                if ($key !== 'supplier_sst_registration_number' || $tenantHadValue) {
                    $missingFields[] = $key;
                }
                unset($context[$key]);
            }
        }

        $identityKeys = array_merge(
            array_keys(self::ENTITY_FIELDS),
            ['supplier_id_type', 'supplier_id_number'],
            self::TENANT_ONLY_FIELDS,
        );

        $fallbackFields = array_values(array_filter(
            $identityKeys,
            fn (string $key) => ! in_array($key, $entityFields, true)
                && ! in_array($key, $missingFields, true)
                && trim((string) ($tenantContext[$key] ?? '')) !== '',
        ));

        $source = $entityFields === []
            ? self::SOURCE_TENANT_CONFIGURATION
            : self::SOURCE_LEGAL_ENTITY;

        $context['supplier_identity_source'] = $source;
        $context['supplier_identity_entity_fields'] = $entityFields;

        return [
            'context' => $context,
            'report' => [
                'source' => $source,
                'legal_entity_id' => $entity?->id,
                'legal_entity_country' => $entity?->country_code ? strtoupper((string) $entity->country_code) : null,
                'entity_fields' => $entityFields,
                'fallback_fields' => $fallbackFields,
                'missing_fields' => $missingFields,
                'fallback_used' => $fallbackFields !== [],
                'credentials_source' => self::SOURCE_TENANT_CONFIGURATION,
            ],
        ];
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value ? (int) $value : null;
    }
}
