<?php

namespace Modules\Communication\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Communication\Models\CommunicationPreference;

class PreferenceController extends Controller
{
    /**
     * GET /api/communication/preferences
     *
     * Get the current user's communication preferences.
     */
    public function show(Request $request): JsonResponse
    {
        $preference = CommunicationPreference::getOrCreateForUser($request->user()->id);

        return response()->json([
            'success' => true,
            'data' => $preference,
        ]);
    }

    /**
     * PUT /api/communication/preferences
     *
     * Update the current user's communication preferences.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'email_enabled' => 'nullable|boolean',
            'sms_enabled' => 'nullable|boolean',
            'push_enabled' => 'nullable|boolean',
            'in_app_enabled' => 'nullable|boolean',
            'whatsapp_enabled' => 'nullable|boolean',
            'delivery_mode' => 'nullable|in:instant,digest_daily,digest_weekly,minimal',
            'quiet_hours_start' => 'nullable|date_format:H:i',
            'quiet_hours_end' => 'nullable|date_format:H:i',
            'digest_time' => 'nullable|date_format:H:i',
            'timezone' => 'nullable|string',
            'subtype_overrides' => 'nullable|array',
            'muted_subtypes' => 'nullable|array',
        ]);

        $preference = CommunicationPreference::getOrCreateForUser($request->user()->id);

        $preference->update($request->only([
            'email_enabled',
            'sms_enabled',
            'push_enabled',
            'in_app_enabled',
            'whatsapp_enabled',
            'delivery_mode',
            'quiet_hours_start',
            'quiet_hours_end',
            'digest_time',
            'timezone',
            'subtype_overrides',
            'muted_subtypes',
        ]));

        return response()->json([
            'success' => true,
            'data' => $preference->fresh(),
        ]);
    }
}
