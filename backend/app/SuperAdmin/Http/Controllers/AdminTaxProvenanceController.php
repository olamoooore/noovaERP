<?php

namespace App\SuperAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\SuperAdmin\Application\TaxProvenanceReport;
use Illuminate\Http\JsonResponse;

/**
 * SuperAdmin read-only data-provenance report for tax filing trails.
 * Surfaced in the platform console so demo/synthetic tenants are visible
 * before anyone treats their statutory returns as filable data.
 */
class AdminTaxProvenanceController extends Controller
{
    public function __construct(private readonly TaxProvenanceReport $report) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->report->build()]);
    }
}
