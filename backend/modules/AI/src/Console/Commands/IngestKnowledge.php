<?php

namespace Modules\AI\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Modules\AI\Services\KnowledgeIngestionService;

class IngestKnowledge extends Command
{
    protected $signature = 'ai:ingest-knowledge
        {--tenant= : Tenant id to ingest into (defaults to the configured storefront tenant)}';

    protected $description = 'Load the starter knowledge pack into a tenant AI knowledge base (idempotent)';

    public function handle(KnowledgeIngestionService $ingestion): int
    {
        $tenantId = (string) ($this->option('tenant') ?: config('tenancy.storefront_tenant_id') ?: '');

        if ($tenantId === '') {
            $this->error('No tenant given. Pass --tenant=<id> or set STOREFRONT_TENANT_ID.');

            return self::FAILURE;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant {$tenantId} not found.");

            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            $count = $ingestion->ingestStarterPack();
        } finally {
            tenancy()->end();
        }

        $this->info("Ingested {$count} knowledge entries for tenant {$tenantId}.");

        return self::SUCCESS;
    }
}
