<?php

namespace App\PublicSites;

use App\Models\WebsitePublication;
use Modules\WebsiteBuilder\Models\Website;

final class WebsitePublicationService
{
    public function sync(Website $website, ?string $forcedStatus = null): WebsitePublication
    {
        $tenantId = (string) tenant('id');
        $status = $forcedStatus ?? ($website->trashed() ? 'deleted' : $website->status);

        return WebsitePublication::query()->updateOrCreate(
            ['website_uuid' => $website->uuid],
            [
                'tenant_id' => $tenantId,
                'slug' => $website->slug,
                'publication_status' => $status,
                'published_at' => $website->published_at,
                'source_event_sequence' => now()->getTimestampMs(),
                'last_reconciled_at' => now(),
            ],
        );
    }
}
