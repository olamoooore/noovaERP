<?php

namespace Modules\AccountingCountry\Http\Controllers;

use App\Models\AuditLog;
use App\Models\StatutoryReturnFiling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\AccountingCountry\Malaysia\Services\Sst02ReturnService;
use Modules\AccountingCountry\Singapore\Services\GstF5ReturnService;
use Modules\AccountingCountry\Singapore\Services\GstF7ReturnService;

/**
 * Issue #93: statutory tax-return endpoints (SG GST F5, MY SST-02) — the
 * AccountingCountry module previously had no controllers at all.
 *
 * Hostile re-review (B2) extensions:
 *   - legal-entity scoping (`entity_id` query param) so a multi-entity tenant
 *     does not file one combined return;
 *   - quarterly periods (`YYYY-Qn`) alongside monthly (`YYYY-MM`) — F5 is
 *     normally quarterly;
 *   - F7 is explicitly reported as not available (split into its own issue)
 *     rather than silently absent.
 */
class StatutoryReturnController extends Controller
{
    /**
     * Returns supported by this build.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                ['country' => 'SG', 'form' => 'GST F5', 'authority' => 'IRAS', 'period_format' => 'YYYY-MM or YYYY-Qn'],
                ['country' => 'MY', 'form' => 'SST-02', 'authority' => 'RMCD', 'period_format' => 'YYYY-MM or YYYY-Qn'],
                // Issue #265: F7 discloses errors against a filed F5.
                ['country' => 'SG', 'form' => 'GST F7', 'authority' => 'IRAS', 'period_format' => 'YYYY-MM or YYYY-Qn'],
            ],
            'planned' => [],
        ]);
    }

    /**
     * Periods with live tax activity (newest first) — powers the return
     * picker so accountants see which filing periods actually contain data.
     */
    public function periods(Request $request): JsonResponse
    {
        $entityId = $request->filled('entity_id') ? (int) $request->query('entity_id') : null;
        $country = $request->filled('country') ? strtoupper((string) $request->query('country')) : null;
        $aggregator = app(\Modules\AccountingCountry\Services\TaxReturnAggregator::class);

        // #750 TU4: with a country, list the registration's actual periods
        // (frequency + cycle start month); without, the legacy activity list.
        return response()->json([
            'data' => $country !== null && in_array($country, ['SG', 'MY'], true)
                ? $aggregator->registrationPeriods($country, $entityId)
                : $aggregator->availablePeriods($entityId),
        ]);
    }

    /**
     * Generate a jurisdiction's statutory return for a reporting period.
     *
     * Period: YYYY-MM (month), YYYY-Qn (quarter) or YYYY-Bn (bimonthly —
     * the SST-02 taxable period, #741 T8); the pack declares its shapes.
     * Entity: optional `entity_id` query parameter (the tenant's legal entity).
     */
    public function show(Request $request, string $country, string $period): JsonResponse
    {
        $country = strtoupper($country);

        $shape = $this->periodShape($period);
        if ($shape === null) {
            return response()->json([
                'message' => 'The reporting period must be in YYYY-MM, YYYY-Qn or YYYY-Bn format.',
            ], 422);
        }

        $shapeError = $this->rejectUnsupportedShape($country, $shape);
        if ($shapeError !== null) {
            return $shapeError;
        }

        $entityId = null;
        if ($request->filled('entity_id')) {
            $entityId = (int) $request->query('entity_id');
            $exists = \Illuminate\Support\Facades\DB::table('legal_entities')
                ->where('id', $entityId)
                ->exists();

            if (! $exists) {
                return response()->json([
                    'message' => 'The selected legal entity does not exist.',
                ], 422);
            }
        }

        // #736 T3: a multi-entity tenant must generate per registration.
        if ($entityId === null && $this->tenantHasMultipleEntities()) {
            return response()->json([
                'message' => 'entity_id is required for statutory returns in a multi-entity tenant.',
            ], 422);
        }

        $months = \Modules\AccountingCountry\Services\TaxReturnAggregator::periodMonths($period, $country, $entityId);

        $data = match ($country) {
            'SG' => app(GstF5ReturnService::class)->generate($period, $entityId),
            'MY' => app(Sst02ReturnService::class)->generate($period, $entityId),
            default => null,
        };

        if ($data === null) {
            return response()->json([
                'message' => "No statutory return generator is available for country {$country}.",
            ], 404);
        }

        $data['reporting_period'] = [
            'period' => $period,
            'months' => $months,
            'legal_entity_id' => $entityId,
        ];

        // #269: VERIFY-tagged pack rates are not filing-ready until sign-off.
        $data['verification_warning'] = \App\Support\TaxPackVerificationGuard::warningFor($country, $period);

        return response()->json(['data' => $data]);
    }

    /**
     * Issue #265: snapshot the current GST F5 as FILED — the F7 baseline.
     * Generalized for the filing tracker (MRD TAX-REQ-08): MY SST-02 can be
     * baselined the same way; both write the same statutory_return_filings row.
     */
    public function file(Request $request, string $country, string $period): JsonResponse
    {
        $country = strtoupper($country);

        if (! in_array($country, ['SG', 'MY'], true)) {
            return response()->json(['message' => 'Filing baselines are supported for SG (GST F5) and MY (SST-02) only.'], 422);
        }

        $shape = $this->periodShape($period);
        if ($shape === null) {
            return response()->json(['message' => 'The reporting period must be YYYY-MM, YYYY-Qn or YYYY-Bn.'], 422);
        }

        $shapeError = $this->rejectUnsupportedShape($country, $shape);
        if ($shapeError !== null) {
            return $shapeError;
        }

        $validated = $request->validate([
            'entity_id' => 'nullable|integer|exists:legal_entities,id',
            // #745 T12: operator inputs for the manual/declaration F5 boxes.
            'box_inputs' => 'nullable|array',
        ]);
        $legalEntityId = isset($validated['entity_id']) ? (int) $validated['entity_id'] : null;
        $boxInputs = (array) ($validated['box_inputs'] ?? []);

        // #736 T3: a multi-entity tenant must file per registration.
        if ($legalEntityId === null && $this->tenantHasMultipleEntities()) {
            return response()->json([
                'message' => 'entity_id is required for statutory returns in a multi-entity tenant.',
            ], 422);
        }

        // #857 RP5: filings are always per legal entity. A single-entity tenant
        // defaults to that entity so the filing closes its activity (legacy
        // key-0 filings are mapped to it when reading).
        if ($legalEntityId === null) {
            $legalEntityId = $this->singleLegalEntityId();
        }

        // The F5 has manual/declaration boxes; SST-02 does not accept inputs.
        $return = $country === 'SG'
            ? app(GstF5ReturnService::class)->generate($period, $legalEntityId, $boxInputs)
            : app(\Modules\AccountingCountry\Malaysia\Services\Sst02ReturnService::class)->generate($period, $legalEntityId);

        // #741 T8: a rule type that is neither sales nor service cannot be
        // bucketed — filing it would misstate the return. Block until mapped.
        $unclassified = (int) ($return['classification']['unclassified_rows'] ?? 0);
        if ($unclassified > 0) {
            return response()->json([
                'message' => "Filing blocked: {$unclassified} tax row(s) are unclassified. Map them to a sales or service tax rule first.",
                'data' => [
                    'period' => $period,
                    'classification' => $return['classification'],
                ],
            ], 422);
        }

        $boxes = $return['boxes'] ?? [
            'sales_tax_due' => $return['sales_tax']['tax_due'] ?? 0,
            'service_tax_due' => $return['service_tax']['tax_due'] ?? 0,
            'total_tax_payable' => $return['total_tax_payable'] ?? 0,
        ];

        // G-35: filings are immutable evidence. Re-filing APPENDS a new
        // version and supersedes the previous current row; the original
        // snapshot (F7 baseline) survives untouched.
        $filing = \DB::transaction(function () use ($country, $return, $period, $legalEntityId, $boxes, $boxInputs, $request) {
            $entityKey = StatutoryReturnFiling::entityKey($legalEntityId);

            $current = StatutoryReturnFiling::query()
                ->where('country', $country)
                ->where('form', $return['form'])
                ->where('period', $period)
                ->where('entity_key', $entityKey)
                ->whereNull('superseded_at')
                ->lockForUpdate()
                ->orderByDesc('filing_version')
                ->first();

            if ($current && $current->boxes === $boxes) {
                return $current;
            }

            $version = $current ? (int) $current->filing_version + 1 : 1;

            if ($current) {
                $current->update(['superseded_at' => now()]);
            }

            $filing = StatutoryReturnFiling::create([
                'tenant_id' => tenant()->id ?? null,
                'country' => $country,
                'form' => $return['form'],
                'period' => $period,
                'entity_key' => $entityKey,
                'filing_version' => $version,
                'boxes' => $boxes,
                'filed_by' => $request->user()?->id,
                'filed_at' => now(),
                // #279 F13: prepared is not filed — the lifecycle starts here.
                'state' => 'PREPARED',
                'state_updated_at' => now(),
            ]);

            // #745 T12 AC2: manual box inputs are recorded and audited before
            // filing — the F5 baseline must be explainable line by line.
            if ($boxInputs !== []) {
                AuditLog::create([
                    'operation' => 'statutory_return_box_inputs',
                    'reference_type' => 'statutory_return_filing',
                    'reference_id' => $filing->id,
                    'actor_id' => $request->user()?->id,
                    'source_route' => $request->path(),
                    'before_state' => null,
                    'after_state' => $boxInputs,
                    'metadata' => [
                        'country' => $country,
                        'form' => $return['form'],
                        'period' => $period,
                        'entity_key' => $entityKey,
                    ],
                ]);
            }

            // G-12 (#274): TaxReturnPrepared on the shared accounting outbox.
            app(\App\Services\TaxOutboxPublisher::class)->publish(
                'TaxReturnPrepared',
                $country.'|'.$return['form'].'|'.$period.'|'.$entityKey,
                (int) $version,
                [
                    'country' => $country,
                    'form' => $return['form'],
                    'period' => $period,
                    'entity_key' => $entityKey,
                    'filing_version' => (int) $version,
                    'boxes' => $boxes,
                    'superseded_version' => $current ? (int) $current->filing_version : null,
                ],
            );

            // G-15 (#274): filing locks its period(s) against back-posting —
            // the TaxPeriodLockGate blocks postings into locked months.
            $lockService = app(\App\Services\TaxPeriodClosingService::class);
            $tenantInt = (int) (tenant()->id ?? 0);

            foreach (\Modules\AccountingCountry\Services\TaxReportingService::expandFilingPeriod($period, $country) as $month) {
                // #278: regime-aware — another regime's lock does not
                // stand in for this return's lock.
                if (! $lockService->isPeriodLocked($month, $tenantInt, $legalEntityId, $country)) {
                    $lockService->closePeriod($month, $tenantInt, $legalEntityId, $country);
                }
            }

            return $filing;
        });

        return response()->json([
            'message' => $return['form'].' filed (version '.$filing->filing_version.')',
            'data' => $filing,
            'verification_warning' => \App\Support\TaxPackVerificationGuard::warningFor($country, $period),
        ], 201);
    }

    /**
     * Issue #265: IRAS GST F7 — disclose errors in a previously filed F5.
     */
    public function f7(Request $request, string $period): JsonResponse
    {
        if (! preg_match('/^\d{4}-(\d{2}|Q[1-4])$/', $period)) {
            return response()->json(['message' => 'The reporting period must be YYYY-MM or YYYY-Qn.'], 422);
        }

        $validated = $request->validate(['entity_id' => 'nullable|integer|exists:legal_entities,id']);
        $legalEntityId = isset($validated['entity_id']) ? (int) $validated['entity_id'] : null;

        try {
            return response()->json([
                'data' => app(GstF7ReturnService::class)->disclose($period, $legalEntityId),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * G-14 (#274): record the authority's response for a filed return —
     * separate from the immutable filing snapshot. Publishes the matching
     * RegulatorySubmission{Accepted,Rejected} outbox event.
     */
    public function recordSubmissionOutcome(Request $request, int $filing): JsonResponse
    {
        $validated = $request->validate([
            'outcome' => 'required|in:accepted,rejected',
            'reference' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:500',
        ]);

        $filingModel = StatutoryReturnFiling::query()
            ->whereNull('superseded_at')
            ->find($filing);

        if (! $filingModel) {
            return response()->json(['message' => 'Current filing not found.'], 404);
        }

        $state = strtoupper($validated['outcome']);

        // #279 F13: outcomes resolve a submission (directly or from the
        // indeterminate delivery state); voided/superseded baselines cannot
        // receive outcomes.
        if (in_array($filingModel->state, ['VOIDED'], true)) {
            return response()->json(['message' => "Filing is {$filingModel->state} and cannot receive an outcome."], 409);
        }

        $filingModel->update([
            'state' => $state,
            'authority_reference' => $validated['reference'] ?? null,
            'state_updated_at' => now(),
        ]);

        \App\Models\AuditLog::create([
            'operation' => 'filing_state_'.strtolower($state),
            'reference_type' => 'statutory_filing',
            'reference_id' => $filingModel->id,
            'actor_id' => $request->user()?->id,
            'source_route' => $request->path(),
            'before_state' => ['state' => 'SUBMITTED'],
            'after_state' => ['state' => $state, 'reference' => $validated['reference'] ?? null],
        ]);

        app(\App\Services\TaxOutboxPublisher::class)->publish(
            'RegulatorySubmission'.ucfirst($validated['outcome']),
            $filingModel->country.'|'.$filingModel->form.'|'.$filingModel->period.'|'.$filingModel->entity_key,
            (int) $filingModel->filing_version,
            [
                'country' => $filingModel->country,
                'form' => $filingModel->form,
                'period' => $filingModel->period,
                'entity_key' => (int) $filingModel->entity_key,
                'filing_version' => (int) $filingModel->filing_version,
                'outcome' => $state,
                'reference' => $validated['reference'] ?? null,
                'note' => $validated['note'] ?? null,
            ],
        );

        return response()->json([
            'message' => "Submission recorded as {$state}.",
            'data' => $filingModel->fresh(),
        ]);
    }

    /**
     * #279 F13: submit a PREPARED filing — idempotent for an existing
     * submission; blocked while the delivery state is unknown (timeout) or
     * once accepted/rejected (amendments only).
     */
    public function submit(Request $request, int $filing): JsonResponse
    {
        $validated = $request->validate([
            'reference' => 'nullable|string|max:120',
        ]);

        $result = \DB::transaction(function () use ($filing, $validated, $request) {
            $model = StatutoryReturnFiling::query()
                ->whereNull('superseded_at')
                ->lockForUpdate()
                ->find($filing);

            if (! $model) {
                return ['code' => 404, 'message' => 'Current filing not found.'];
            }

            if ($model->state === 'SUBMITTED') {
                return ['code' => 200, 'idempotent' => true, 'filing' => $model];
            }

            if ($model->state === 'PENDING_AUTHORITY_RESPONSE') {
                return ['code' => 409, 'message' => 'Delivery state unknown — reconcile the prior submission before resubmitting.'];
            }

            if (in_array($model->state, ['ACCEPTED', 'REJECTED', 'VOIDED'], true)) {
                return ['code' => 409, 'message' => "Filing is {$model->state}; file an amendment instead of resubmitting."];
            }

            $model->update([
                'state' => 'SUBMITTED',
                'submitted_at' => now(),
                'submitted_by' => $request->user()?->id,
                'submission_reference' => $validated['reference'] ?? $model->submission_reference,
                'state_updated_at' => now(),
            ]);

            \App\Models\AuditLog::create([
                'operation' => 'filing_state_submitted',
                'reference_type' => 'statutory_filing',
                'reference_id' => $model->id,
                'actor_id' => $request->user()?->id,
                'source_route' => $request->path(),
                'before_state' => ['state' => 'PREPARED'],
                'after_state' => ['state' => 'SUBMITTED', 'reference' => $validated['reference'] ?? null],
            ]);

            return ['code' => 200, 'idempotent' => false, 'filing' => $model];
        });

        if ($result['code'] !== 200) {
            return response()->json(['message' => $result['message']], $result['code']);
        }

        return response()->json([
            'message' => $result['idempotent'] ? 'Submission already recorded (idempotent).' : 'Filing submitted.',
            'idempotent' => $result['idempotent'],
            'data' => $result['filing']->fresh(),
        ]);
    }

    /**
     * #279 F13: record an UNKNOWN delivery state (submit timeout) — blocks a
     * blind duplicate until the outcome is reconciled.
     */
    public function submissionUnknown(Request $request, int $filing): JsonResponse
    {
        $validated = $request->validate(['note' => 'nullable|string|max:500']);

        $model = StatutoryReturnFiling::query()->whereNull('superseded_at')->find($filing);

        if (! $model) {
            return response()->json(['message' => 'Current filing not found.'], 404);
        }

        if ($model->state !== 'SUBMITTED') {
            return response()->json(['message' => 'Only a submitted filing can enter the unknown delivery state.'], 409);
        }

        $model->update(['state' => 'PENDING_AUTHORITY_RESPONSE', 'state_updated_at' => now()]);

        \App\Models\AuditLog::create([
            'operation' => 'filing_state_unknown',
            'reference_type' => 'statutory_filing',
            'reference_id' => $model->id,
            'actor_id' => $request->user()?->id,
            'source_route' => $request->path(),
            'before_state' => ['state' => 'SUBMITTED'],
            'after_state' => ['state' => 'PENDING_AUTHORITY_RESPONSE', 'note' => $validated['note'] ?? null],
        ]);

        return response()->json(['message' => 'Delivery state recorded as unknown; resubmission blocked until reconciled.', 'data' => $model->fresh()]);
    }

    /**
     * #736 T3: does this tenant hold more than one legal entity?
     */
    private function tenantHasMultipleEntities(): bool
    {
        return \Illuminate\Support\Facades\DB::table('legal_entities')->count() > 1;
    }

    /**
     * #857 RP5: the tenant's single legal entity, or null when it has none or
     * several (in which case entity_id is required / left tenant-wide).
     */
    private function singleLegalEntityId(): ?int
    {
        $ids = \Illuminate\Support\Facades\DB::table('legal_entities')->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /**
     * #741 T8: which period shape the request asks for.
     */
    private function periodShape(string $period): ?string
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1) {
            return 'monthly';
        }

        if (preg_match('/^\d{4}-Q[1-4]$/', $period) === 1) {
            return 'quarterly';
        }

        if (preg_match('/^\d{4}-B[1-6]$/', $period) === 1) {
            return 'bimonthly';
        }

        return null;
    }

    /**
     * #741 T8: periods are declared by the pack (SG monthly/quarterly, MY
     * monthly/bimonthly). Unknown packs skip the check (404 path follows).
     */
    private function rejectUnsupportedShape(string $country, string $shape): ?JsonResponse
    {
        $shapes = (array) config('tax_packs.'.$country.'.period_shapes', []);

        if ($shapes !== [] && ! in_array($shape, $shapes, true)) {
            return response()->json([
                'message' => "Period shape {$shape} is not supported by the {$country} pack.",
            ], 422);
        }

        return null;
    }
}
