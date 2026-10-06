<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Notifications\NotificationPreference;
use App\Platform\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Sales\Notifications\SalesNotificationCatalogue;

/**
 * SalesNotificationPreferenceController — the user-facing mute surface for the
 * #903 sales notifications.
 *
 * Issue #907 extends the #903 index into the preference matrix: every
 * catalogue event is a row, every matrix channel (in-app, email, WhatsApp) is
 * a column, each cell carries its effective value, whether that value is still
 * the catalogue/role default, and whether the event can deliver on it at all.
 * The controller also owns the document-scoped mute endpoint ("mute all from
 * this document"), stored additively on the same
 * platform_notification_preferences table via NotificationService.
 *
 * It only exposes the events in SalesNotificationCatalogue (a user cannot
 * invent preference rows for arbitrary events). WhatsApp is a stored
 * preference column only: its transport is still a stub, so writing it never
 * becomes a delivered channel.
 */
class SalesNotificationPreferenceController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        // Only event-wide rows shape the matrix; document-scoped mutes are a
        // separate scope ("mute this document") surfaced in the inbox.
        $rows = NotificationPreference::query()
            ->where('user_id', $userId)
            ->whereNull('document_type')
            ->get()
            ->keyBy(fn (NotificationPreference $preference) => $preference->event.'|'.$preference->channel);

        $events = [];

        foreach (SalesNotificationCatalogue::preferenceEvents() as $event => $meta) {
            $defaults = $meta['channels'];
            $channels = [];
            $isDefault = [];
            $available = [];

            foreach (SalesNotificationCatalogue::MATRIX_CHANNELS as $channel) {
                $row = $rows->get($event.'|'.$channel);
                $deliverable = in_array($channel, $defaults, true);

                // `is_default` is "no explicit row yet", so the UI can mark the
                // pre-ticked role/catalogue defaults.
                $channels[$channel] = $row !== null ? (bool) $row->mail_enabled : $deliverable;
                $isDefault[$channel] = $row === null;
                $available[$channel] = $deliverable
                    || $channel === SalesNotificationCatalogue::CHANNEL_WHATSAPP;
            }

            $events[] = [
                'event' => $event,
                'label' => $meta['label'],
                'channels' => $channels,
                'is_default' => $isDefault,
                'available' => $available,
            ];
        }

        return response()->json(['data' => $events]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event' => 'required|string|max:100',
            'channel' => 'required|string|max:20',
            'enabled' => 'required|boolean',
        ]);

        $event = (string) $validated['event'];
        $channel = (string) $validated['channel'];

        if (! SalesNotificationCatalogue::has($event)) {
            return response()->json(['message' => 'Unknown notification event.'], 422);
        }

        // A channel is writable when the event declares it, plus WhatsApp for
        // every event (a stored preference whose transport is not shipped yet).
        $allowed = array_unique([
            ...SalesNotificationCatalogue::defaultChannels($event),
            SalesNotificationCatalogue::CHANNEL_WHATSAPP,
        ]);

        if (! in_array($channel, $allowed, true)) {
            return response()->json([
                'message' => "Channel '{$channel}' is not available for this notification event.",
            ], 422);
        }

        $this->notifications->setPreference(
            (int) $request->user()->id,
            $event,
            $channel,
            (bool) $validated['enabled'],
        );

        return response()->json([
            'message' => 'Preference saved.',
            'data' => [
                'event' => $event,
                'channel' => $channel,
                'enabled' => (bool) $validated['enabled'],
            ],
        ]);
    }

    /**
     * Issue #907 — "mute all from this document". Muting writes one additive
     * row scoped to the user + event + document; it suppresses every channel
     * for that document only and never affects the event-wide preference.
     */
    public function updateDocument(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event' => ['required', 'string', Rule::in(array_keys(SalesNotificationCatalogue::preferenceEvents()))],
            'document_type' => ['required', 'string', Rule::in(SalesNotificationCatalogue::DOCUMENT_TYPES)],
            'document_id' => ['required', 'integer', 'min:1'],
            'muted' => ['required', 'boolean'],
        ]);

        $this->notifications->setDocumentMute(
            (int) $request->user()->id,
            (string) $validated['event'],
            (string) $validated['document_type'],
            (string) $validated['document_id'],
            (bool) $validated['muted'],
        );

        return response()->json([
            'message' => $validated['muted'] ? 'Document muted.' : 'Document un-muted.',
            'data' => [
                'event' => $validated['event'],
                'document_type' => $validated['document_type'],
                'document_id' => (int) $validated['document_id'],
                'muted' => (bool) $validated['muted'],
            ],
        ]);
    }
}
