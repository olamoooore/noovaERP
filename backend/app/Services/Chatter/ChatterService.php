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
        // Normalize subject_id: cast to int for queries against bigint columns
        // (activity_log.subject_id, chatter_*.subject_id). The route param
        // arrives as a string from the URL; PostgreSQL refuses to compare
        // varchar to bigint without an explicit cast.
        $normalizedId = (int) $subjectId;

        $tenantId = $this->currentTenantId();

        $logs = ActivityLogEntry::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $normalizedId)
            // Scope to this tenant — subject ids are per-tenant sequences, so
            // without this two tenants' documents would share history.
            // (All pre-existing rows were backfilled when tenant_id was added.)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
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
            ->where('subject_id', (string) $normalizedId)
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
            ->where('subject_id', (string) $normalizedId)
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
            ->where('subject_id', (string) $normalizedId)
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
        $normalizedId = (int) $subjectId;
        $message = ChatterMessage::create([
            'tenant_id' => $this->currentTenantId(),
            'subject_type' => $subjectType,
            'subject_id' => (string) $normalizedId,
            'author_id' => Auth::id(),
            // Issue #79: chatter bodies are plain text — strip markup so the
            // API can never return stored HTML/script to any consumer.
            'body' => $this->sanitizeBody($body),
        ]);

        $this->logEvent($subjectType, $normalizedId, 'message', 'Message posted', [
            'message_id' => $message->id,
        ]);

        return $message->fresh(['author']);
    }

    public function addActivity(string $subjectType, int|string $subjectId, array $payload): ChatterActivity
    {
        $normalizedId = (int) $subjectId;
        $activity = ChatterActivity::create([
            'tenant_id' => $this->currentTenantId(),
            'subject_type' => $subjectType,
            'subject_id' => (string) $normalizedId,
            'assigned_to_id' => $payload['assigned_to_id'] ?? null,
            'created_by_id' => Auth::id(),
            'activity_type' => $payload['activity_type'],
            // Issue #79: plain-text fields must not persist markup.
            'summary' => $this->sanitizeBody($payload['summary']),
            'note' => isset($payload['note']) ? $this->sanitizeBody($payload['note']) : null,
            'due_date' => $payload['due_date'] ?? null,
            'status' => 'planned',
        ]);

        $this->logEvent($subjectType, $normalizedId, 'activity_scheduled', 'Activity scheduled', [
            'activity_id' => $activity->id,
            'activity_type' => $activity->activity_type,
        ]);

        return $activity->fresh(['assignee', 'createdBy']);
    }

    public function markActivityDone(string $subjectType, int|string $subjectId, int $activityId): ChatterActivity
    {
        $normalizedId = (int) $subjectId;
        $activity = ChatterActivity::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', (string) $normalizedId)
            ->where('id', $activityId)
            ->firstOrFail();

        $activity->update([
            'status' => 'done',
            'completed_at' => now(),
        ]);

        $this->logEvent($subjectType, $normalizedId, 'activity_done', 'Activity marked done', [
            'activity_id' => $activity->id,
        ]);

        return $activity->fresh(['assignee', 'createdBy']);
    }

    public function uploadAttachment(string $subjectType, int|string $subjectId, UploadedFile $file): ChatterAttachment
    {
        // Default to 'public' disk so attachments are reachable via the
        // /storage/... URL. The 'local' disk has no public url() method
        // by design, which previously made every chatter attachment 404.
        $disk = config('chatter.disk', 'public');
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
            'subject_id' => (string) (int) $subjectId,
            'uploaded_by_id' => Auth::id(),
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'storage_disk' => $disk,
            'storage_path' => $path,
            'checksum' => hash_file('sha256', $file->getRealPath()),
        ]);

        $this->logEvent($subjectType, (int) $subjectId, 'attachment_uploaded', "Uploaded {$attachment->filename}", [
            'attachment_id' => $attachment->id,
            'filename' => $attachment->filename,
            'size' => (int) $attachment->size,
        ]);

        return $attachment->fresh(['uploadedBy']);
    }

    /**
     * Register an already-stored file as a chatter attachment.
     *
     * Used by flows that store the file themselves first (e.g. a customer's
     * payment-proof upload through the public acceptance portal, where there is
     * no authenticated user). `$uploadedById` may be null for customer actors.
     */
    public function registerAttachment(
        string $subjectType,
        int|string $subjectId,
        string $filename,
        string $mimeType,
        int $size,
        string $storageDisk,
        string $storagePath,
        ?int $uploadedById = null,
    ): ChatterAttachment {
        $attachment = ChatterAttachment::create([
            'tenant_id' => $this->currentTenantId(),
            'subject_type' => $subjectType,
            'subject_id' => (string) (int) $subjectId,
            'uploaded_by_id' => $uploadedById,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size' => $size,
            'storage_disk' => $storageDisk,
            'storage_path' => $storagePath,
            'checksum' => null,
        ]);

        $this->logEvent($subjectType, (int) $subjectId, 'attachment_uploaded', "Uploaded {$filename}", [
            'attachment_id' => $attachment->id,
            'filename' => $filename,
            'size' => $size,
        ]);

        return $attachment->fresh(['uploadedBy']);
    }

    /**
     * Issue #78: stream an attachment through the authenticated API instead of
     * exposing the underlying storage path/URL structure.
     */
    public function downloadAttachment(string $subjectType, int|string $subjectId, int $attachmentId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $attachment = ChatterAttachment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', (string) (int) $subjectId)
            ->where('id', $attachmentId)
            ->firstOrFail();

        $disk = $attachment->storage_disk ?: 'public';

        abort_unless(Storage::disk($disk)->exists($attachment->storage_path), 404, 'Attachment file not found.');

        return Storage::disk($disk)->download($attachment->storage_path, $attachment->filename);
    }

    public function deleteAttachment(string $subjectType, int|string $subjectId, int $attachmentId): void
    {
        $attachment = ChatterAttachment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', (string) (int) $subjectId)
            ->where('id', $attachmentId)
            ->firstOrFail();

        Storage::disk($attachment->storage_disk ?: 'local')->delete($attachment->storage_path);
        $attachment->delete();

        $this->logEvent($subjectType, (int) $subjectId, 'attachment_deleted', 'Attachment deleted', [
            'attachment_id' => $attachmentId,
        ]);
    }

    /**
     * Issue #79: chatter content is plain text. Strip any HTML/script markup
     * and control characters so stored XSS can never be returned by the API,
     * regardless of how a consumer renders it.
     */
    private function sanitizeBody(string $body): string
    {
        // strip_tags() keeps the CONTENT of <script>/<style> blocks, so remove
        // those blocks entirely first (their inner JS/CSS is not text content).
        $clean = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $body) ?? $body;
        $clean = strip_tags($clean);
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean) ?? $clean;

        return trim($clean);
    }

    /**
     * Record a lifecycle event in the chatter log.
     *
     * `$actorId`/`$actorType` let callers attribute the event to the user who
     * actually performed it (e.g. an approval reviewer) instead of relying on
     * the ambient authenticated user — which is often absent for token-authed
     * API calls and would otherwise surface the event as "System".
     */
    public function logEvent(
        string $subjectType,
        int|string $subjectId,
        string $event,
        string $description,
        array $properties = [],
        int|string|null $actorId = null,
        ?string $actorType = null,
    ): void {
        try {
            $causerType = $actorType ?? (Auth::check() ? get_class(Auth::user()) : null);
            $causerId = $actorId ?? Auth::id();

            if ($causerType === null || $causerId === null) {
                $causerType = null;
                $causerId = null;
            }

            ActivityLogEntry::create([
                'log_name' => 'chatter',
                'tenant_id' => $this->currentTenantId(),
                'description' => $description,
                'subject_type' => $subjectType,
                'subject_id' => (int) $subjectId,
                'causer_type' => $causerType,
                'causer_id' => $causerId,
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
