<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Platform\Notifications\NotificationLog;
use App\Platform\Notifications\NotificationPreference;
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
            ->whereNotIn('event', $this->inAppMutedEvents((int) $request->user()->id))
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($event) {
            $query->where('event', $event);
        }

        if (! empty($validated['unread_only'])) {
            $query->whereNull('read_at');
        }

        // Issue #907: document-scoped mutes for this user, so each row can
        // offer the inverse action ("un-mute this document").
        $mutedDocumentKeys = $this->notificationService->mutedDocumentKeys((int) $request->user()->id);

        $logs = $query->get()->map(function (NotificationLog $log) use ($mutedDocumentKeys) {
            return [
                'id' => $log->id,
                'event' => $log->event,
                'channel' => $log->channel,
                'subject' => $log->subject,
                'body' => $log->body,
                'data' => $log->data,
                'status' => $log->status,
                'error' => $log->error,
                'document_muted' => $this->isDocumentMutedLog($log, $mutedDocumentKeys),
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
            ->whereNotIn('event', $this->inAppMutedEvents((int) $request->user()->id))
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
            ->whereNotIn('event', $this->inAppMutedEvents((int) $request->user()->id))
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

    /**
     * Events whose in-app channel the user has explicitly disabled. The mail
     * leg may still be delivered (the row records it), but the bell and the
     * notifications page hide it — the user muted the event for that channel.
     *
     * @return array<int, string>
     */
    private function inAppMutedEvents(int $userId): array
    {
        return NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('channel', 'in_app')
            ->where('mail_enabled', false)
            ->pluck('event')
            ->all();
    }

    /**
     * Does this log's document carry a document-scoped mute for the user?
     *
     * @param  array<int, string>  $mutedDocumentKeys
     */
    private function isDocumentMutedLog(NotificationLog $log, array $mutedDocumentKeys): bool
    {
        $data = $log->data ?? [];
        $documentType = $data['document_type'] ?? null;
        $documentId = $data['document_id'] ?? null;

        if (! is_string($documentType) || $documentType === '' || $documentId === null || $documentId === '') {
            return false;
        }

        return in_array(
            $log->event.'|'.$documentType.'|'.$documentId,
            $mutedDocumentKeys,
            true,
        );
    }
}
