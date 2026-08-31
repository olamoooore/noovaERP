<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Chatter\ChatterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChatterController extends Controller
{
    public function __construct(private readonly ChatterService $chatter) {}

    public function show(Request $request, string $subjectType, int|string $subjectId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);

        $bundle = $this->chatter->bundle($subjectType, $subjectId);

        return response()->json(['data' => $bundle]);
    }

    public function addMessage(Request $request, string $subjectType, int|string $subjectId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);
        $data = $request->validate([
            'body' => 'required|string|min:1|max:10000',
        ]);

        $message = $this->chatter->addMessage($subjectType, $subjectId, $data['body']);

        return response()->json(['data' => [
            'id' => $message->id,
            'body' => $message->body,
            'mentions' => $message->mentions ?? [],
            'created_at' => optional($message->created_at)->toIso8601String(),
            'actor' => $message->author ? [
                'id' => $message->author->id,
                'name' => $message->author->name,
                'email' => $message->author->email,
                'avatar' => $message->author->avatar ?? null,
            ] : null,
        ]], 201);
    }

    public function addActivity(Request $request, string $subjectType, int|string $subjectId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);
        $data = $request->validate([
            'activity_type' => ['required', Rule::in(['follow_up', 'call', 'meeting', 'todo', 'reminder'])],
            'summary' => 'required|string|min:1|max:255',
            'note' => 'nullable|string|max:10000',
            'due_date' => 'nullable|date',
            'assigned_to_id' => 'nullable|integer|exists:users,id',
        ]);

        $activity = $this->chatter->addActivity($subjectType, $subjectId, $data);

        return response()->json(['data' => $this->serializeActivity($activity)], 201);
    }

    public function markActivityDone(Request $request, string $subjectType, int|string $subjectId, int $activityId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);

        $activity = $this->chatter->markActivityDone($subjectType, $subjectId, $activityId);

        return response()->json(['data' => $this->serializeActivity($activity)]);
    }

    public function uploadAttachment(Request $request, string $subjectType, int|string $subjectId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);
        $request->validate([
            'file' => 'required|file|max:25600', // 25 MB
        ]);

        $attachment = $this->chatter->uploadAttachment($subjectType, $subjectId, $request->file('file'));

        return response()->json(['data' => [
            'id' => $attachment->id,
            'filename' => $attachment->filename,
            'mime_type' => $attachment->mime_type,
            'size' => (int) $attachment->size,
            'url' => $attachment->url,
            'uploaded_at' => optional($attachment->created_at)->toIso8601String(),
            'uploaded_by' => $attachment->uploadedBy ? [
                'id' => $attachment->uploadedBy->id,
                'name' => $attachment->uploadedBy->name,
                'email' => $attachment->uploadedBy->email,
            ] : null,
        ]], 201);
    }

    public function deleteAttachment(Request $request, string $subjectType, int|string $subjectId, int $attachmentId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);

        $this->chatter->deleteAttachment($subjectType, $subjectId, $attachmentId);

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function validateSubject(string $subjectType, int|string $subjectId): void
    {
        if (! class_exists($subjectType)) {
            abort(422, 'Invalid subject type.');
        }
        if (! is_numeric($subjectId)) {
            abort(422, 'Invalid subject id.');
        }
    }

    private function serializeActivity($activity): array
    {
        return [
            'id' => $activity->id,
            'activity_type' => $activity->activity_type,
            'summary' => $activity->summary,
            'note' => $activity->note,
            'due_date' => optional($activity->due_date)->toIso8601String(),
            'status' => $activity->status,
            'completed_at' => optional($activity->completed_at)->toIso8601String(),
            'created_at' => optional($activity->created_at)->toIso8601String(),
            'assigned_to' => $activity->assignee ? [
                'id' => $activity->assignee->id,
                'name' => $activity->assignee->name,
                'email' => $activity->assignee->email,
                'avatar' => $activity->assignee->avatar ?? null,
            ] : null,
            'actor' => $activity->createdBy ? [
                'id' => $activity->createdBy->id,
                'name' => $activity->createdBy->name,
                'email' => $activity->createdBy->email,
            ] : null,
        ];
    }
}
