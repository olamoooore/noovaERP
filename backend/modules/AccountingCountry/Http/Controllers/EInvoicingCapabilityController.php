<?php

namespace Modules\AccountingCountry\Http\Controllers;

use App\Accounting\Contracts\SubmissionProviderContract;
use App\Accounting\Plugin\CountryPluginRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Issue #99: e-invoicing capability disclosure. A jurisdiction's submission
 * provider must be reported as configured/unconfigured (and whether signing is
 * available) instead of appearing functional when it cannot actually submit.
 */
class EInvoicingCapabilityController extends Controller
{
    public function index(): JsonResponse
    {
        $registry = app(CountryPluginRegistry::class);

        $rows = [];

        foreach (['SG', 'MY'] as $country) {
            try {
                $provider = $registry->has($country)
                    ? $registry->resolve($country, SubmissionProviderContract::class)
                    : null;
            } catch (\Throwable) {
                // Compliance plugins are only registered when the compliance
                // feature is enabled — report the country as unavailable.
                $provider = null;
            }

            // A missing provider means the jurisdiction cannot submit: report
            // it as unavailable rather than unknown, so consumers never treat
            // an unregistered country as functional.
            $configured = $provider && method_exists($provider, 'isConfigured')
                ? (bool) $provider->isConfigured()
                : false;
            $signing = $provider && method_exists($provider, 'signingAvailable')
                ? (bool) $provider->signingAvailable()
                : false;

            $rows[] = [
                'country' => $country,
                'provider' => $provider ? get_class($provider) : null,
                'configured' => $configured,
                'signing_available' => $signing,
                'submission_available' => $configured && $signing,
            ];
        }

        return response()->json(['data' => $rows]);
    }
}
