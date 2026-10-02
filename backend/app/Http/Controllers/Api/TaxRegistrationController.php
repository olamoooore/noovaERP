<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaxRegistration;
use App\Services\TaxRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MRD R5.3/R6.2 — tax registration lifecycle (SST no. / GST ID / TIN per
 * jurisdiction, per legal entity, effective-dated). Feeds the Tax Engine
 * console status and document registration prints.
 */
class TaxRegistrationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => TaxRegistration::query()
                ->with('legalEntity:id,name,code')
                ->orderBy('jurisdiction')
                ->orderBy('effective_from')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        // #748 TU2: shared writer — pack-driven frequency + home-jurisdiction.
        $registration = app(TaxRegistrationService::class)->upsert($validated);

        return response()->json(['data' => $registration->load('legalEntity:id,name,code')], 201);
    }

    public function update(Request $request, TaxRegistration $registration): JsonResponse
    {
        $validated = $this->validatePayload($request, partial: true);

        $registration->update($validated);

        return response()->json(['data' => $registration->fresh()->load('legalEntity:id,name,code')]);
    }

    public function destroy(TaxRegistration $registration): JsonResponse
    {
        $registration->delete();

        return response()->json(['message' => 'Tax registration removed']);
    }

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? ['sometimes'] : [];

        $validated = $request->validate([
            'legal_entity_id' => array_merge($sometimes, ['nullable', 'integer', 'exists:legal_entities,id']),
            'jurisdiction' => array_merge($sometimes, ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/']),
            'regime' => array_merge($sometimes, ['nullable', 'string', 'max:30']),
            'registration_number' => array_merge($sometimes, ['required', 'string', 'max:50']),
            'effective_from' => array_merge($sometimes, ['required', 'date']),
            'effective_to' => array_merge($sometimes, ['nullable', 'date', 'after_or_equal:effective_from']),
            'status' => array_merge($sometimes, ['in:active,inactive,cancelled']),
            // #741 T8: cycle start month for bimonthly SST-02 periods.
            'filing_start_month' => array_merge($sometimes, ['nullable', 'integer', 'min:1', 'max:12']),
            // #748 TU2: the entity's filing frequency; validated against the
            // jurisdiction's pack shapes below.
            'filing_frequency' => array_merge($sometimes, ['nullable', 'string', 'max:20']),
            'notes' => array_merge($sometimes, ['nullable', 'string', 'max:255']),
        ]);

        if (array_key_exists('jurisdiction', $validated) && $validated['jurisdiction'] !== null) {
            $validated['jurisdiction'] = strtoupper(trim($validated['jurisdiction']));
        }

        if (array_key_exists('regime', $validated) && $validated['regime'] !== null) {
            $validated['regime'] = strtoupper(trim($validated['regime']));
        }

        return $validated;
    }
}
