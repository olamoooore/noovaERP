<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

use App\Models\CompanySettings;
use App\Models\Setting;

/**
 * Builds the shared `company` merge-field block for the current tenant.
 *
 * Pulled from CompanySettings (structured) with Setting KV fallbacks so that
 * templates never break when optional fields are missing.
 */
final class CompanyDataSource
{
    /** @return array<string,mixed> */
    public static function forCurrentTenant(): array
    {
        $company = CompanySettings::current();

        return [
            'name' => $company?->company_name ?? Setting::getValue('business_name', config('app.name', 'Your Company')),
            'logo_url' => $company?->company_logo_url ?? Setting::getValue('business_logo_url'),
            'email' => Setting::getValue('business_email', ''),
            'phone' => Setting::getValue('business_phone', ''),
            'address' => Setting::getValue('business_address', ''),
            'website' => Setting::getValue('business_website', ''),
            'tax_id' => $company?->tax_registration_number ?? Setting::getValue('gst_registration_number', ''),
            'registration_number' => $company?->company_registration_number ?? '',
            'currency_code' => $company?->base_currency ?? 'MYR',
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
            'registration_number' => '201001000123 (1234567-A)',
            'currency_code' => 'MYR',
        ];
    }
}
