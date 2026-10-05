<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Notifications\NotificationPreference;
use App\Platform\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sales\Notifications\SalesNotificationCatalogue;

/**
 * SalesNotificationPreferenceController — the user-facing mute surface for the
 * #903 sales notifications.
 *
 * It only exposes the events in SalesNotificationCatalogue (a user cannot
 * invent preference rows for arbitrary events) and only the channels the
 * catalogue declares for each event. The storage is the existing
 * platform_notification_preferences table via NotificationService::setPreference
 * — a mute is a row with `mail_enabled = false` for (user, event, channel).
 */
class SalesNotificationPreferenceController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $rows = NotificationPreference::query()
            ->where('user_id', $userId)
            ->get()
            ->keyBy(fn (NotificationPreference $preference) => $preference->event.'|'.$preference->channel);

        $events = [];

        foreach (SalesNotificationCatalogue::preferenceEvents() as $event => $meta) {
            $channels = [];

            foreach ($meta['channels'] as $channel) {
                $row = $rows->get($event.'|'.$channel);
                $channels[$channel] = $row !== null ? (bool) $row->mail_enabled : true;
            }

            $events[] = [
                'event' => $event,
                'label' => $meta['label'],
                'channels' => $channels,
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

        if (! in_array($channel, SalesNotificationCatalogue::defaultChannels($event), true)) {
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
}
