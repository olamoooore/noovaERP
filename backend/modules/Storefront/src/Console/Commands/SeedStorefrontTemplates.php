<?php

namespace Modules\Storefront\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Storefront\Database\Seeders\StorefrontTemplateSeeder;

/**
 * Seed (or re-seed) the Storefront default email templates and invalidate
 * the TemplateRenderer cache entries for exactly the affected keys — the
 * renderer caches by (tenant, key, language), so a system-default update
 * must not keep serving the previous copy for an hour.
 */
class SeedStorefrontTemplates extends Command
{
    protected $signature = 'storefront:templates:seed';

    protected $description = 'Idempotently seed Storefront default communication templates (FR-MSG-03) and bust their caches';

    public function handle(StorefrontTemplateSeeder $seeder): int
    {
        $keys = $seeder->seeds();

        $cleared = 0;
        foreach ($keys as $key) {
            Cache::forget("comm_tpl:null:{$key}:en");
            $cleared++;

            $tenantIds = DB::table('communication_templates')
                ->where('key', $key)
                ->whereNotNull('tenant_id')
                ->distinct()
                ->pluck('tenant_id');

            foreach ($tenantIds as $tid) {
                Cache::forget("comm_tpl:{$tid}:{$key}:en");
                $cleared++;
            }
        }

        $this->info('Storefront templates seeded: '.count($keys).'. Cache keys invalidated: '.$cleared.'.');

        return self::SUCCESS;
    }
}
