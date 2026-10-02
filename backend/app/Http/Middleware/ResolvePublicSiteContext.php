<?php

namespace App\Http\Middleware;

use App\PublicSites\PublicSiteResolver;
use App\PublicSites\PublicSiteContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolvePublicSiteContext
{
    public function __construct(private readonly PublicSiteResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $websiteUuid = $request->route('websiteUuid');
        $context = is_string($websiteUuid)
            ? $this->resolver->fromWebsiteUuid($request, $websiteUuid)
            : $this->resolver->fromHost($request);

        if (! $context) {
            abort(404);
        }

        $request->attributes->set(PublicSiteContext::class, $context);
        $request->attributes->set('public_site_context', $context);

        return $next($request);
    }
}
