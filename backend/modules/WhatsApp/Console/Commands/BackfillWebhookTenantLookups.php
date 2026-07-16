<?php

namespace Modules\WhatsApp\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\WhatsApp\Models\WhatsAppApiKey;

class BackfillWebhookTenantLookups extends Command
{
    protected $signature = 'whatsapp:backfill-webhook-tenant-lookups {--tenant= : Limit the backfill to a single tenant ID}';

    protected $description = 'Populate the shared WhatsApp webhook tenant lookup table from existing tenant API keys';

    public function handle(): int
    {
        if (! Schema::hasTable(WhatsAppApiKey::webhookTenantLookupBaseTable())) {
            $this->error('Shared WhatsApp webhook lookup table not found. Run the latest database migrations first.');

            return self::FAILURE;
        }

        $tenantQuery = Tenant::query()->orderBy('id');
        $tenantId = $this->option('tenant');

        if ($tenantId) {
            $tenantQuery->whereKey($tenantId);
        }

        $tenants = $tenantQuery->get();

        if ($tenants->isEmpty()) {
            $this->warn('No matching tenants found.');

            return self::SUCCESS;
        }

        $totalSynced = 0;

        foreach ($tenants as $tenant) {
            try {
                $tenantSynced = $tenant->run(function (): int {
                    if (! Schema::hasTable('whatsapp_api_keys')) {
                        return 0;
                    }

                    $synced = 0;

                    WhatsAppApiKey::query()
                        ->select(['id'])
                        ->orderBy('id')
                        ->chunkById(200, function ($keys) use (&$synced): void {
                            foreach ($keys as $apiKey) {
                                $apiKey->syncWebhookTenantLookup();
                                $synced++;
                            }
                        });

                    return $synced;
                });
            } catch (\Throwable $e) {
                $this->warn("Tenant {$tenant->id}: backfill skipped ({$e->getMessage()})");

                continue;
            }

            $totalSynced += $tenantSynced;
            $this->line("Tenant {$tenant->id}: synced {$tenantSynced} webhook lookup(s).");
        }

        $this->info("Backfilled {$totalSynced} shared WhatsApp webhook lookup(s).");

        return self::SUCCESS;
    }
}
