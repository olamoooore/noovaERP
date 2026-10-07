<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

use App\Models\CompanySettings;
use App\Models\LegalEntity;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Modules\TemplateBuilder\Support\StatutoryDocumentFields;

/**
 * Builds the shared `company` merge-field block.
 *
 * Issue #612 (TP2): the block describes the SELLING legal entity of the
 * document being printed, not whichever tenant happens to be current. A
 * multi-entity tenant must print entity B's invoices with entity B's name,
 * registration number, TIN, SST/GST number, address, logo and bank details.
 * Only when the document has no entity (or an entity leaves a field empty)
 * does each field fall back to the tenant's CompanySettings / Setting KV.
 *
 * Issue #614 (TP4): TIN is no longer collapsed into the SST/GST registration
 * number — `tin` is its own token, `sst_number`/`gst_number` expose the
 * registration number, `bank.*` groups the payment details, and the labels +
 * requiredness come from the document jurisdiction's country pack.
 */
final class CompanyDataSource
{
    /** @return array<string,mixed> */
    public static function forCurrentTenant(?string $jurisdiction = null): array
    {
        $company = CompanySettings::current();
        $bank = self::bankDetails(Setting::getValue('bank_details', []));
        $registrationNumber = $company?->gst_registration_number
            ?? $company?->tax_registration_number
            ?? Setting::getValue('gst_registration_number', '');

        $values = [
            'name' => $company?->company_name ?? Setting::getValue('business_name', config('app.name', 'Your Company')),
            'logo_url' => $company?->company_logo_url ?? Setting::getValue('business_logo_url'),
            'email' => $company?->email ?? Setting::getValue('business_email', ''),
            'phone' => $company?->phone ?? Setting::getValue('business_phone', ''),
            'address' => $company?->address ?? Setting::getValue('business_address', ''),
            'website' => $company?->website ?? Setting::getValue('business_website', ''),
            // Legacy collapsed token kept for compatibility (#612); new
            // statutory tokens below keep TIN and SST/GST separate.
            'tax_id' => $company?->tax_id ?? $registrationNumber,
            'sst_registration_number' => $registrationNumber,
            'registration_number' => $company?->company_registration_number ?? '',
            'tin' => $company?->tax_id ?? '',
            'sst_number' => $registrationNumber,
            'gst_number' => $registrationNumber,
            'bank_name' => $bank['bank_name'] ?? '',
            'bank_account_name' => $bank['account_name'] ?? '',
            'bank_account_number' => $bank['account_number'] ?? '',
            'bank_swift_code' => $bank['swift_code'] ?? '',
            'bank_code' => $bank['bank_code'] ?? '',
            'bank' => [
                'name' => $bank['bank_name'] ?? '',
                'account_name' => $bank['account_name'] ?? '',
                'account_number' => $bank['account_number'] ?? '',
                'swift_code' => $bank['swift_code'] ?? '',
                'code' => $bank['bank_code'] ?? '',
            ],
            'currency_code' => $company?->base_currency ?? 'MYR',
        ];

        return self::withPackLabels($values, $jurisdiction ?? self::tenantJurisdiction());
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
    public static function forDocument(mixed $document, ?string $jurisdiction = null): array
    {
        return self::forEntity(self::entityIdFor($document), $jurisdiction);
    }

    /** @return array<string,mixed> */
    public static function forEntity(?int $legalEntityId, ?string $jurisdiction = null): array
    {
        $entity = ($legalEntityId !== null && $legalEntityId > 0)
            ? LegalEntity::find($legalEntityId)
            : null;

        // #614: the pack that supplies the statutory labels is the document's
        // jurisdiction — the entity's country when present, else tenant-wide.
        $packJurisdiction = $jurisdiction ?: $entity?->country_code;

        $tenant = self::forCurrentTenant($packJurisdiction);

        if (! $entity) {
            return $tenant;
        }

        $bank = self::bankDetails($entity->bank_details);

        $values = [
            'name' => self::firstFilled($entity->name, $tenant['name']),
            'logo_url' => self::firstFilled($entity->logo_url, $tenant['logo_url']),
            'email' => self::firstFilled($entity->email, $tenant['email']),
            'phone' => self::firstFilled($entity->phone, $tenant['phone']),
            'address' => self::firstFilled($entity->address, $tenant['address']),
            'website' => self::firstFilled($entity->website, $tenant['website']),
            'tax_id' => self::firstFilled($entity->tax_id, $tenant['tax_id']),
            'sst_registration_number' => self::firstFilled($entity->sst_registration_number, $tenant['sst_registration_number']),
            'registration_number' => self::firstFilled($entity->registration_number, $tenant['registration_number']),
            // #614: TIN is the entity's tax_id; the SST/GST registration
            // number is its sst_registration_number (the field #612 introduced
            // as the regime registration number; a legacy gst_registration_number
            // column wins when a tenant has one).
            'tin' => self::firstFilled($entity->tax_id, $tenant['tin']),
            'sst_number' => self::firstFilled($entity->sst_registration_number, $tenant['sst_number']),
            // #1019: firstFilled() takes two arguments — the former
            // three-argument call silently dropped the tenant fallback, so the
            // company GST registration number never reached entity documents.
            'gst_number' => self::firstFilled(
                self::firstFilled($entity->gst_registration_number ?? null, $entity->sst_registration_number),
                $tenant['gst_number'],
            ),
            'bank_name' => self::firstFilled($bank['bank_name'] ?? null, $tenant['bank_name']),
            'bank_account_name' => self::firstFilled($bank['account_name'] ?? null, $tenant['bank_account_name']),
            'bank_account_number' => self::firstFilled($bank['account_number'] ?? null, $tenant['bank_account_number']),
            'bank_swift_code' => self::firstFilled($bank['swift_code'] ?? null, $tenant['bank_swift_code']),
            'bank_code' => self::firstFilled($bank['bank_code'] ?? null, $tenant['bank_code']),
            'bank' => [
                'name' => self::firstFilled($bank['bank_name'] ?? null, $tenant['bank']['name'] ?? ''),
                'account_name' => self::firstFilled($bank['account_name'] ?? null, $tenant['bank']['account_name'] ?? ''),
                'account_number' => self::firstFilled($bank['account_number'] ?? null, $tenant['bank']['account_number'] ?? ''),
                'swift_code' => self::firstFilled($bank['swift_code'] ?? null, $tenant['bank']['swift_code'] ?? ''),
                'code' => self::firstFilled($bank['bank_code'] ?? null, $tenant['bank']['code'] ?? ''),
            ],
            'currency_code' => self::firstFilled($entity->base_currency, $tenant['currency_code']),
        ];

        return self::withPackLabels($values, $packJurisdiction);
    }

    /** @return array<string,mixed> */
    public static function sample(): array
    {
        return self::withPackLabels([
            'name' => 'Acme Sdn Bhd',
            'logo_url' => null,
            'email' => 'billing@acme.example',
            'phone' => '+60 3-1234 5678',
            'address' => 'Level 12, Menara Acme, 1 Sentral, Kuala Lumpur, 50470, Malaysia',
            'website' => 'https://acme.example',
            'tax_id' => 'MY123456789',
            'sst_registration_number' => 'SST-123456789',
            'registration_number' => '201001000123 (1234567-A)',
            'tin' => 'MY123456789',
            'sst_number' => 'SST-123456789',
            'gst_number' => 'SST-123456789',
            'bank_name' => 'Maybank',
            'bank_account_name' => 'Acme Sdn Bhd',
            'bank_account_number' => '5141 2233 4455',
            'bank_swift_code' => 'MBBEMYKL',
            'bank_code' => 'MBBEMYKL',
            'bank' => [
                'name' => 'Maybank',
                'account_name' => 'Acme Sdn Bhd',
                'account_number' => '5141 2233 4455',
                'swift_code' => 'MBBEMYKL',
                'code' => 'MBBEMYKL',
            ],
            'currency_code' => 'SGD',
        ], 'MY');
    }

    /**
     * Attach the jurisdiction pack's labels + requiredness and pick the
     * single `tax_number`/`tax_number_label` pair matching the regime (SST
     * documents print the SST number, GST documents the GST number).
     *
     * @param  array<string,mixed>  $company
     * @return array<string,mixed>
     */
    private static function withPackLabels(array $company, ?string $jurisdiction): array
    {
        $fields = StatutoryDocumentFields::forJurisdiction($jurisdiction);
        $regime = self::regimeFor($jurisdiction);
        $isGstRegime = strtoupper($regime) === 'GST';

        $company['tax_regime'] = $regime;
        $company['tin_label'] = (string) ($fields['tin']['label'] ?? 'Tax Identification No.');
        $company['tin_required'] = (bool) ($fields['tin']['required'] ?? false);
        $company['sst_number_label'] = (string) ($fields['sst_number']['label'] ?? 'Sales/Service Tax Registration No.');
        $company['sst_number_required'] = (bool) ($fields['sst_number']['required'] ?? false);
        $company['gst_number_label'] = (string) ($fields['gst_number']['label'] ?? 'GST Registration No.');
        $company['gst_number_required'] = (bool) ($fields['gst_number']['required'] ?? false);
        $company['bank_label'] = (string) ($fields['bank']['label'] ?? 'Bank Details');
        $company['bank_required'] = (bool) ($fields['bank']['required'] ?? false);

        $company['tax_number'] = (string) ($isGstRegime ? ($company['gst_number'] ?? '') : ($company['sst_number'] ?? ''));
        $company['tax_number_label'] = (string) ($isGstRegime ? $company['gst_number_label'] : $company['sst_number_label']);

        return $company;
    }

    private static function tenantJurisdiction(): string
    {
        try {
            return (string) app(\App\Services\TaxEngineService::class)->tenantJurisdiction();
        } catch (\Throwable) {
            return '';
        }
    }

    private static function regimeFor(?string $jurisdiction): string
    {
        try {
            return strtoupper((string) app(\App\Services\TaxPackRegistry::class)->regime((string) $jurisdiction));
        } catch (\Throwable) {
            return 'GST';
        }
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
