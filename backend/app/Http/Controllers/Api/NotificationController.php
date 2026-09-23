<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Platform\Notifications\NotificationLog;
use App\Platform\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:200',
            'event' => 'nullable|string',
            'unread_only' => 'nullable|boolean',
        ]);

        $limit = (int) ($validated['limit'] ?? 50);
        $event = $validated['event'] ?? null;

        $query = NotificationLog::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($event) {
            $query->where('event', $event);
        }

        if (! empty($validated['unread_only'])) {
            $query->whereNull('read_at');
        }

        $logs = $query->get()->map(function (NotificationLog $log) {
            return [
                'id' => $log->id,
                'event' => $log->event,
                'channel' => $log->channel,
                'subject' => $log->subject,
                'body' => $log->body,
                'data' => $log->data,
                'status' => $log->status,
                'error' => $log->error,
                'sent_at' => optional($log->sent_at)->toIso8601String(),
                'read_at' => optional($log->read_at)->toIso8601String(),
                'created_at' => optional($log->created_at)->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $logs,
            'count' => $logs->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = NotificationLog::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['unread' => $count]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $log = NotificationLog::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $log) {
            return response()->json(['message' => 'Notification not found'], 404);
        }

        $log->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Marked as read',
            'data' => [
                'id' => $log->id,
                'read_at' => optional($log->read_at)->toIso8601String(),
            ],
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = NotificationLog::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read',
            'count' => $count,
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $log = NotificationLog::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $log) {
            return response()->json(['message' => 'Notification not found'], 404);
        }

        $log->delete();

        return response()->json(['message' => 'Notification deleted']);
    }
}
