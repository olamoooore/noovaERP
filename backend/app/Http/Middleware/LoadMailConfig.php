<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Services\MailConfigLoader;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LoadMailConfig
{
    public function __construct(
        private MailConfigLoader $mailConfigLoader,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->mailConfigLoader->apply(Setting::getValue('mail_config'));
        } catch (\Throwable $e) {
            // Fails silently if database connection is not yet established
            // This prevents console/early-boot errors
        }

        return $next($request);
    }
}
