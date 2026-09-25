<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Tenant branding (company name + logo) for tenant-facing output that is NOT
 * rendered by the Template Builder — public customer portals, legacy PDF
 * blades and report exports.
 *
 * NoovaERP is the ERP platform (like Odoo): documents and portals must be
 * branded as the tenant, falling back to the tenant's company name when no
 * logo is configured — never the platform brand.
 */
final class TenantBranding
{
    /** @return array{name: string, logo_url: string} */
    public static function forCurrentTenant(): array
    {
        try {
            $settings = DB::table('company_settings')
                ->select('company_name', 'company_logo_url')
                ->first();
        } catch (\Throwable) {
            $settings = null;
        }

        return [
            'name' => (string) ($settings->company_name ?? ''),
            'logo_url' => (string) ($settings->company_logo_url ?? ''),
        ];
    }
}
