<?php

namespace App\PublicSites;

use App\Models\Hostname;
use App\Models\Tenant;
use App\Models\WebsitePublication;
use Illuminate\Http\Request;
use Stancl\Tenancy\Tenancy;

final class PublicSiteResolver
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function fromHost(Request $request): ?PublicSiteContext
    {
        try {
            $host = app(HostnameNormalizer::class)->normalize($request->getHost());
            $record = Hostname::query()
                ->where('hostname', $host)
                ->where('purpose', 'website')
                ->where('serving_status', 'active')
                ->first();
        } catch (\Throwable) {
            return null;
        }

        return $record ? $this->initialize($record->tenant_id, $record->website_uuid, $host, $record->id) : null;
    }

    public function fromWebsiteUuid(Request $request, string $websiteUuid): ?PublicSiteContext
    {
        $publication = WebsitePublication::query()
            ->whereKey($websiteUuid)
            ->where('publication_status', 'published')
            ->first();

        if (! $publication) {
            return null;
        }

        return $this->initialize($publication->tenant_id, $websiteUuid, $request->getHost(), null);
    }

    private function initialize(string $tenantId, string $websiteUuid, string $host, ?int $hostnameId): ?PublicSiteContext
    {
        $publication = WebsitePublication::query()
            ->whereKey($websiteUuid)
            ->where('tenant_id', $tenantId)
            ->where('publication_status', 'published')
            ->first();
        $tenant = $publication ? Tenant::find($tenantId) : null;
        if (! $tenant) {
            return null;
        }

        $this->tenancy->initialize($tenant);
        $websiteExists = \Modules\WebsiteBuilder\Models\Website::query()
            ->where('uuid', $websiteUuid)
            ->where('status', 'published')
            ->exists();
        if (! $websiteExists) {
            $this->tenancy->end();
            return null;
        }

        $canonicalHost = Hostname::query()
            ->where('tenant_id', $tenantId)
            ->where('website_uuid', $websiteUuid)
            ->where('primary_effective', true)
            ->where('serving_status', 'active')
            ->value('hostname');
        $scheme = request()->isSecure() ? 'https' : 'http';

        return new PublicSiteContext(
            $tenantId,
            $websiteUuid,
            $host,
            $canonicalHost ? 'https://'.$canonicalHost : $scheme.'://'.$host,
            $hostnameId,
        );
    }
}
