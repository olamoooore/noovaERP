<?php

declare(strict_types=1);

namespace App\Services\Chatter;

use App\Features\ActivityLogging\Models\ActivityLogEntry;
use App\Models\ChatterActivity;
use App\Models\ChatterAttachment;
use App\Models\ChatterMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ChatterService
{
    public function bundle(string $subjectType, int|string $subjectId): array
    {
        $logs = ActivityLogEntry::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->with('causer')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function (ActivityLogEntry $entry) {
                return [
                    'id' => $entry->id,
                    'event' => $entry->event ?? 'updated',
                    'description' => $entry->description ?? '',
                    'properties' => $entry->properties,
                    'created_at' => optional($entry->created_at)->toIso8601String(),
                    'actor' => $this->formatActor($entry->causer),
                ];
            })
            ->all();

        $messages = ChatterMessage::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->with('author')
            ->orderBy('created_at')
            ->get()
            ->map(function (ChatterMessage $msg) {
                return [
                    'id' => $msg->id,
                    'body' => $msg->body,
                    'mentions' => $msg->mentions ?? [],
                    'created_at' => optional($msg->created_at)->toIso8601String(),
                    'actor' => $this->formatActor($msg->author),
                ];
            })
            ->all();

        $activities = ChatterActivity::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->with(['assignee', 'createdBy'])
            ->orderByRaw("CASE WHEN status = 'planned' THEN 0 ELSE 1 END")
            ->orderBy('due_date')
            ->get()
            ->map(function (ChatterActivity $activity) {
                return [
                    'id' => $activity->id,
                    'activity_type' => $activity->activity_type,
                    'summary' => $activity->summary,
                    'note' => $activity->note,
                    'due_date' => optional($activity->due_date)->toIso8601String(),
                    'status' => $activity->status,
                    'completed_at' => optional($activity->completed_at)->toIso8601String(),
                    'created_at' => optional($activity->created_at)->toIso8601String(),
                    'assigned_to' => $this->formatActor($activity->assignee),
                    'actor' => $this->formatActor($activity->createdBy),
                ];
            })
            ->all();

        $attachments = ChatterAttachment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->with('uploadedBy')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ChatterAttachment $att) {
                return [
                    'id' => $att->id,
                    'filename' => $att->filename,
                    'mime_type' => $att->mime_type,
                    'size' => (int) $att->size,
                    'url' => $att->url,
                    'uploaded_at' => optional($att->created_at)->toIso8601String(),
                    'uploaded_by' => $this->formatActor($att->uploadedBy),
                ];
            })
            ->all();

        return [
            'logs' => $logs,
            'messages' => $messages,
            'activities' => $activities,
            'attachments' => $attachments,
        ];
    }

    public function addMessage(string $subjectType, int|string $subjectId, string $body): ChatterMessage
    {
        $message = ChatterMessage::create([
            'tenant_id' => $this->currentTenantId(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'author_id' => Auth::id(),
            'body' => $body,
        ]);

        $this->logEvent($subjectType, $subjectId, 'message', 'Message posted', [
            'message_id' => $message->id,
        ]);

        return $message->fresh(['author']);
    }

    public function addActivity(string $subjectType, int|string $subjectId, array $payload): ChatterActivity
    {
        $activity = ChatterActivity::create([
            'tenant_id' => $this->currentTenantId(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'assigned_to_id' => $payload['assigned_to_id'] ?? null,
            'created_by_id' => Auth::id(),
            'activity_type' => $payload['activity_type'],
            'summary' => $payload['summary'],
            'note' => $payload['note'] ?? null,
            'due_date' => $payload['due_date'] ?? null,
            'status' => 'planned',
        ]);

        $this->logEvent($subjectType, $subjectId, 'activity_scheduled', 'Activity scheduled', [
            'activity_id' => $activity->id,
            'activity_type' => $activity->activity_type,
        ]);

        return $activity->fresh(['assignee', 'createdBy']);
    }

    public function markActivityDone(string $subjectType, int|string $subjectId, int $activityId): ChatterActivity
    {
        $activity = ChatterActivity::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('id', $activityId)
            ->firstOrFail();

        $activity->update([
            'status' => 'done',
            'completed_at' => now(),
        ]);

        $this->logEvent($subjectType, $subjectId, 'activity_done', 'Activity marked done', [
            'activity_id' => $activity->id,
        ]);

        return $activity->fresh(['assignee', 'createdBy']);
    }

    public function uploadAttachment(string $subjectType, int|string $subjectId, UploadedFile $file): ChatterAttachment
    {
        $disk = config('chatter.disk', 'local');
        $path = sprintf(
            'chatter/%s/%s/%s_%s.%s',
            str_replace('\\', '_', $subjectType),
            $subjectId,
            now()->format('Ymd_His'),
            substr(uniqid('', true), -6),
            $file->getClientOriginalExtension()
        );

        Storage::disk($disk)->putFileAs(
            dirname($path),
            $file,
            basename($path)
        );

        $attachment = ChatterAttachment::create([
            'tenant_id' => $this->currentTenantId(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'uploaded_by_id' => Auth::id(),
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'storage_disk' => $disk,
            'storage_path' => $path,
            'checksum' => hash_file('sha256', $file->getRealPath()),
        ]);

        $this->logEvent($subjectType, $subjectId, 'attachment_uploaded', 'Attachment uploaded', [
            'attachment_id' => $attachment->id,
            'filename' => $attachment->filename,
        ]);

        return $attachment->fresh(['uploadedBy']);
    }

    public function deleteAttachment(string $subjectType, int|string $subjectId, int $attachmentId): void
    {
        $attachment = ChatterAttachment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('id', $attachmentId)
            ->firstOrFail();

        Storage::disk($attachment->storage_disk ?: 'local')->delete($attachment->storage_path);
        $attachment->delete();

        $this->logEvent($subjectType, $subjectId, 'attachment_deleted', 'Attachment deleted', [
            'attachment_id' => $attachmentId,
        ]);
    }

    public function logEvent(string $subjectType, int|string $subjectId, string $event, string $description, array $properties = []): void
    {
        try {
            ActivityLogEntry::create([
                'log_name' => 'chatter',
                'description' => $description,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'causer_type' => Auth::check() ? get_class(Auth::user()) : null,
                'causer_id' => Auth::id(),
                'event' => $event,
                'properties' => $properties,
            ]);
        } catch (\Throwable $e) {
            // never break user-visible flows because chatter logging failed
            report($e);
        }
    }

    private function formatActor(?Model $actor): ?array
    {
        if (! $actor) {
            return null;
        }

        return [
            'id' => $actor->getKey(),
            'name' => $actor->name ?? null,
            'email' => $actor->email ?? null,
            'avatar' => $actor->avatar ?? null,
        ];
    }

    private function currentTenantId(): ?string
    {
        try {
            return tenant('id');
        } catch (\Throwable) {
            return null;
        }
    }
}
