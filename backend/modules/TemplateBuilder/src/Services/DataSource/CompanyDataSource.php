<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

use App\Models\CompanySettings;
use App\Models\LegalEntity;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds the shared `company` merge-field block.
 *
 * Issue #612 (TP2): the block describes the SELLING legal entity of the
 * document being printed, not whichever tenant happens to be current. A
 * multi-entity tenant must print entity B's invoices with entity B's name,
 * registration number, TIN, SST/GST number, address, logo and bank details.
 * Only when the document has no entity (or an entity leaves a field empty)
 * does each field fall back to the tenant's CompanySettings / Setting KV.
 */
final class CompanyDataSource
{
    /** @return array<string,mixed> */
    public static function forCurrentTenant(): array
    {
        $company = CompanySettings::current();
        $bank = self::bankDetails(Setting::getValue('bank_details', []));

        return [
            'name' => $company?->company_name ?? Setting::getValue('business_name', config('app.name', 'Your Company')),
            'logo_url' => $company?->company_logo_url ?? Setting::getValue('business_logo_url'),
            'email' => $company?->email ?? Setting::getValue('business_email', ''),
            'phone' => $company?->phone ?? Setting::getValue('business_phone', ''),
            'address' => $company?->address ?? Setting::getValue('business_address', ''),
            'website' => $company?->website ?? Setting::getValue('business_website', ''),
            'tax_id' => $company?->tax_id ?? $company?->gst_registration_number ?? $company?->tax_registration_number ?? Setting::getValue('gst_registration_number', ''),
            'sst_registration_number' => $company?->gst_registration_number ?? $company?->tax_registration_number ?? Setting::getValue('gst_registration_number', ''),
            'registration_number' => $company?->company_registration_number ?? '',
            'bank_name' => $bank['bank_name'] ?? '',
            'bank_account_name' => $bank['account_name'] ?? '',
            'bank_account_number' => $bank['account_number'] ?? '',
            'bank_swift_code' => $bank['swift_code'] ?? '',
            'bank_code' => $bank['bank_code'] ?? '',
            'currency_code' => $company?->base_currency ?? 'MYR',
        ];
    }

    /**
     * Build the `company` block for a document, using the selling legal
     * entity that owns it.
     *
     * Accepts the document model, a loaded data source (anything exposing
     * legalEntityId()), a LegalEntity, or a bare entity id. When nothing
     * resolves, the tenant defaults are returned unchanged.
     *
     * @return array<string,mixed>
     */
    public static function forDocument(mixed $document): array
    {
        return self::forEntity(self::entityIdFor($document));
    }

    /** @return array<string,mixed> */
    public static function forEntity(?int $legalEntityId): array
    {
        $tenant = self::forCurrentTenant();

        if ($legalEntityId === null || $legalEntityId <= 0) {
            return $tenant;
        }

        $entity = LegalEntity::find($legalEntityId);

        if (! $entity) {
            return $tenant;
        }

        $bank = self::bankDetails($entity->bank_details);

        return [
            'name' => self::firstFilled($entity->name, $tenant['name']),
            'logo_url' => self::firstFilled($entity->logo_url, $tenant['logo_url']),
            'email' => self::firstFilled($entity->email, $tenant['email']),
            'phone' => self::firstFilled($entity->phone, $tenant['phone']),
            'address' => self::firstFilled($entity->address, $tenant['address']),
            'website' => self::firstFilled($entity->website, $tenant['website']),
            'tax_id' => self::firstFilled($entity->tax_id, $tenant['tax_id']),
            'sst_registration_number' => self::firstFilled($entity->sst_registration_number, $tenant['sst_registration_number']),
            'registration_number' => self::firstFilled($entity->registration_number, $tenant['registration_number']),
            'bank_name' => self::firstFilled($bank['bank_name'] ?? null, $tenant['bank_name']),
            'bank_account_name' => self::firstFilled($bank['account_name'] ?? null, $tenant['bank_account_name']),
            'bank_account_number' => self::firstFilled($bank['account_number'] ?? null, $tenant['bank_account_number']),
            'bank_swift_code' => self::firstFilled($bank['swift_code'] ?? null, $tenant['bank_swift_code']),
            'bank_code' => self::firstFilled($bank['bank_code'] ?? null, $tenant['bank_code']),
            'currency_code' => self::firstFilled($entity->base_currency, $tenant['currency_code']),
        ];
    }

    /** @return array<string,mixed> */
    public static function sample(): array
    {
        return [
            'name' => 'Acme Sdn Bhd',
            'logo_url' => null,
            'email' => 'billing@acme.example',
            'phone' => '+60 3-1234 5678',
            'address' => 'Level 12, Menara Acme, 1 Sentral, Kuala Lumpur, 50470, Malaysia',
            'website' => 'https://acme.example',
            'tax_id' => 'MY123456789',
            'sst_registration_number' => 'SST-123456789',
            'registration_number' => '201001000123 (1234567-A)',
            'bank_name' => 'Maybank',
            'bank_account_name' => 'Acme Sdn Bhd',
            'bank_account_number' => '5141 2233 4455',
            'bank_swift_code' => 'MBBEMYKL',
            'bank_code' => 'MBBEMYKL',
            'currency_code' => 'SGD',
        ];
    }

    /**
     * The selling entity id for whatever the caller hands us.
     */
    private static function entityIdFor(mixed $document): ?int
    {
        if ($document === null || $document === '') {
            return null;
        }

        if ($document instanceof LegalEntity) {
            return (int) $document->id;
        }

        if (is_object($document) && method_exists($document, 'legalEntityId')) {
            $entityId = $document->legalEntityId();

            return $entityId !== null ? (int) $entityId : null;
        }

        if ($document instanceof Model) {
            $entityId = $document->getAttribute('legal_entity_id');
            if ($entityId !== null && $entityId !== '') {
                return (int) $entityId;
            }

            foreach (['customer', 'salesOrder'] as $relation) {
                if (! method_exists($document, $relation)) {
                    continue;
                }

                $related = $document->getRelationValue($relation);
                $relatedEntityId = $related?->legal_entity_id;
                if ($relatedEntityId !== null && $relatedEntityId !== '') {
                    return (int) $relatedEntityId;
                }
            }

            return null;
        }

        if (is_numeric($document)) {
            return (int) $document;
        }

        return null;
    }

    /** @param array<string,mixed>|mixed $bank */
    private static function bankDetails(mixed $bank): array
    {
        return is_array($bank) ? $bank : [];
    }

    private static function firstFilled(mixed $primary, mixed $fallback): mixed
    {
        if ($primary === null || $primary === '' || $primary === []) {
            return $fallback;
        }

        return $primary;
    }
}
