<?php

namespace Modules\AccountingCountry\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AccountingCountry\Malaysia\Services\MySstAuditListingService;
use Modules\AccountingCountry\Singapore\Services\SgIafExportService;
use Symfony\Component\HttpFoundation\Response;

/**
 * #861 (RP7): audit exports — the SG IRAS audit file (IAF) data listing and
 * the MY SST transaction listing. Both are scoped to one legal entity and one
 * reporting period and share the #860 lifecycle scope with the tax reports.
 */
class TaxAuditExportController extends Controller
{
    public function __construct(
        private readonly SgIafExportService $sgIaf,
        private readonly MySstAuditListingService $mySst,
    ) {}

    public function sgIaf(Request $request): JsonResponse|Response
    {
        [$period, $entityId, $format] = $this->context($request, 'SG');

        $data = $this->sgIaf->build($period, $entityId);

        return $this->respond($data, $format, $this->sgIaf->toCsv($data), "sg_iaf_{$period}.csv");
    }

    public function mySstListing(Request $request): JsonResponse|Response
    {
        [$period, $entityId, $format] = $this->context($request, 'MY');

        $data = $this->mySst->build($period, $entityId);

        return $this->respond($data, $format, $this->mySst->toCsv($data), "my_sst_listing_{$period}.csv");
    }

    /**
     * Resolve (and validate) the export scope: period, entity and format.
     *
     * @return array{0: string, 1: ?int, 2: string}
     */
    private function context(Request $request, string $country): array
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2]|Q[1-4]|B[1-6])$/'],
            'entity_id' => ['nullable', 'integer', 'exists:legal_entities,id'],
            'format' => ['nullable', 'in:json,csv'],
        ]);

        $format = $validated['format'] ?? 'json';
        $entityId = isset($validated['entity_id']) ? (int) $validated['entity_id'] : null;

        // #861 AC3: the export is always one entity's. A multi-entity tenant
        // must say which; a single-entity tenant defaults to that entity.
        if ($entityId === null) {
            $entityIds = DB::table('legal_entities')->where('status', 'active')->pluck('id');

            if ($entityIds->count() > 1) {
                throw ValidationException::withMessages([
                    'entity_id' => 'entity_id is required for audit exports in a multi-entity tenant.',
                ]);
            }

            $entityId = $entityIds->count() === 1 ? (int) $entityIds->first() : null;
        }

        // #767 pattern: an SG audit file must not be filed for a MY entity
        // (and vice versa). A missing country on the entity is left alone —
        // the tax-trail jurisdiction filter still applies.
        if ($entityId !== null) {
            $entityCountry = strtoupper(trim((string) DB::table('legal_entities')
                ->where('id', $entityId)
                ->value('country_code')));

            if ($entityCountry !== '' && $entityCountry !== $country) {
                throw ValidationException::withMessages([
                    'entity_id' => "The {$country} audit export requires a {$country} legal entity.",
                ]);
            }
        }

        return [$validated['period'], $entityId, $format];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respond(array $data, string $format, string $csv, string $filename): JsonResponse|Response
    {
        if ($format === 'csv') {
            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);
        }

        return response()->json(['data' => $data]);
    }
}
