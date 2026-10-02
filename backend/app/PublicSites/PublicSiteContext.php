<?php

namespace App\PublicSites;

final readonly class PublicSiteContext
{
    public function __construct(
        public string $tenantId,
        public string $websiteUuid,
        public string $hostname,
        public string $canonicalOrigin,
        public ?int $hostnameId,
    ) {}
}
