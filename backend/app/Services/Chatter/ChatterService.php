<?php

declare(strict_types=1);

namespace App\Services\Chatter;

use App\Features\ActivityLogging\Models\ActivityLogEntry;
use App\Models\ChatterActivity;
use App\Models\ChatterAttachment;
use App\Models\ChatterMessage;
use App\Models\DocumentFollower;
use App\Models\EsignRequest;
use App\Models\InvoiceEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Models\DeliveryOrder;

class ChatterService
{
    /**
     * Cached answer to "does the activity_log table the connection actually
     * resolves have a tenant_id column?". Null = not resolved yet.
     */
    private ?bool $activityLogTenantId = null;

    /**
     * Whether activity rows can (and therefore must) carry a tenant scope.
     *
     * `activity_log` lives in the shared `public` schema and has a `tenant_id`
     * column, but `Schema::hasColumn()` resolves against `current_schema()` —
     * which under a tenant request is the tenant schema, where the table does
     * not exist. It returned false, so BOTH the write path and the read path
     * silently dropped the tenant scope: every tenant wrote NULL-tenant rows
     * into one shared feed and every tenant read the other tenants' history.
     *
     * Resolve the table Postgres would actually use (first match walking the
     * search_path) and inspect that exact table.
     */
    private function activityLogHasTenantId(): bool
    {
        if ($this->activityLogTenantId !== null) {
            return $this->activityLogTenantId;
        }

        try {
            $row = DB::selectOne(
                "SELECT EXISTS (
                    SELECT 1
                    FROM information_schema.columns c
                    WHERE c.table_name = 'activity_log'
                      AND c.column_name = 'tenant_id'
                      AND c.table_schema = (
                          SELECT t.table_schema
                          FROM information_schema.tables t
                          WHERE t.table_name = 'activity_log'
                            AND t.table_schema = ANY (current_schemas(false))
                          ORDER BY array_position(current_schemas(false), t.table_schema)
                          LIMIT 1
                      )
                ) AS present"
            );

            return $this->activityLogTenantId = (bool) ($row->present ?? false);
        } catch (\Throwable $e) {
            return $this->activityLogTenantId = false;
        }
    }

    public function bundle(string $subjectType, int|string $subjectId): array
    {
        // Normalize subject_id: cast to int for queries against bigint columns
        // (activity_log.subject_id, chatter_*.subject_id). The route param
        // arrives as a string from the URL; PostgreSQL refuses to compare
        // varchar to bigint without an explicit cast.
        $normalizedId = (int) $subjectId;

        $tenantId = $this->currentTenantId();
        $activityLogHasTenantId = $this->activityLogHasTenantId();

        $logs = ActivityLogEntry::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $normalizedId)
            // Scope to this tenant — subject ids are per-tenant sequences, so
            // without this two tenants' documents would share history.
            // (All pre-existing rows were backfilled when tenant_id was added.)
            ->when(
                $tenantId !== null && $activityLogHasTenantId,
                fn ($q) => $q->where('tenant_id', $tenantId)
            )
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
                    'uploaded_by_name' => $att->uploaded_by_name,
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

    /**
     * Issue #637: postpone an open activity to a new due date.
     *
     * The status stays `planned`; only the due date moves, so the activity
     * stays in the open buckets of "My Activities" — just under a later one.
     */
    public function snoozeActivity(string $subjectType, int|string $subjectId, int $activityId, string $dueDate): ChatterActivity
    {
        $normalizedId = (int) $subjectId;
        $activity = ChatterActivity::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', (string) $normalizedId)
            ->where('id', $activityId)
            ->firstOrFail();

        $activity->update([
            'due_date' => $dueDate,
        ]);

        $this->logEvent($subjectType, $normalizedId, 'activity_snoozed', 'Activity snoozed', [
            'activity_id' => $activity->id,
            'due_date' => optional($activity->due_date)->toIso8601String(),
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
     * `$checksum` is the sha256 of the stored bytes (issue #631); callers that
     * know it should pass it so an overwritten file is detectable.
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
        ?string $uploadedByName = null,
        ?string $evidenceType = null,
        ?int $evidenceId = null,
        ?string $checksum = null,
    ): ChatterAttachment {
        $attachment = ChatterAttachment::create([
            'tenant_id' => $this->currentTenantId(),
            'subject_type' => $subjectType,
            'subject_id' => (string) (int) $subjectId,
            'uploaded_by_id' => $uploadedById,
            // Customer-portal uploads have no user id; without this the feed
            // attributed them to "System".
            'uploaded_by_name' => $uploadedByName,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size' => $size,
            'storage_disk' => $storageDisk,
            'storage_path' => $storagePath,
            // Issue #631: evidence mirrors must record the bytes' checksum so
            // an overwritten/forged file can be detected from the row alone.
            'checksum' => $checksum,
            // Issue #630: evidence services name the record this file proves,
            // so the chatter delete path can refuse to destroy it.
            'evidence_type' => $evidenceType,
            'evidence_id' => $evidenceId,
        ]);

        // Public-portal uploads have no authenticated user, so logEvent cannot
        // attribute them and the feed said "System". Name the uploader in the
        // entry itself when the caller supplies one.
        $this->logEvent(
            $subjectType,
            (int) $subjectId,
            'attachment_uploaded',
            'Uploaded '.$filename.($uploadedByName ? " by {$uploadedByName}" : ''),
            [
                'attachment_id' => $attachment->id,
                'filename' => $filename,
                'size' => $size,
                'uploaded_by_name' => $uploadedByName,
            ],
        );

        return $attachment->fresh(['uploadedBy']);
    }

    /**
     * Resolve an attachment that belongs to the given chatter subject.
     *
     * Shared by the download and delete paths (and by the controller's
     * deletion guard) so subject scoping cannot drift between them — the
     * subject_id is always normalized to int-cast string, mirroring upload.
     */
    public function findAttachment(string $subjectType, int|string $subjectId, int $attachmentId): ChatterAttachment
    {
        return ChatterAttachment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', (string) (int) $subjectId)
            ->where('id', $attachmentId)
            ->firstOrFail();
    }

    /**
     * Issue #629 (CH2 / #630): is this chatter attachment the same stored file
     * as an evidence artifact? Evidence services register their files as
     * chatter attachments pointing at the SAME disk path as the evidence row
     * (invoice evidence, e-sign signed copies, signed delivery orders), so the
     * path is the link. A true result means deleting through chatter would
     * destroy evidence and must be refused.
     *
     * Issue #630 extends this: an attachment registered by an evidence service
     * now carries `evidence_type` / `evidence_id` directly (schema-based
     * contract). The path checks below remain as the safety net for rows
     * registered before those columns existed.
     */
    public function isEvidenceLinked(ChatterAttachment $attachment): bool
    {
        return $this->evidenceLinkDescription($attachment) !== null;
    }

    /**
     * Describe the evidence a chatter attachment belongs to, or null when it
     * is an ordinary attachment.
     *
     * Used both for the delete guard (`isEvidenceLinked`) and for the 403
     * message ("This file is evidence for …"), so the two cannot drift.
     */
    public function evidenceLinkDescription(ChatterAttachment $attachment): ?string
    {
        $type = $attachment->evidence_type;
        if (is_string($type) && $type !== '' && $attachment->evidence_id !== null) {
            return $this->evidenceTypeLabel($type).' #'.$attachment->evidence_id;
        }

        return $this->storedPathEvidenceDescription((string) $attachment->storage_path);
    }

    /**
     * Issue #630: does any OTHER record still reference this stored file?
     *
     * Chatter attachment rows can share one file (e.g. an evidence file
     * mirrored into a second feed entry), so deleting one row must not remove
     * the bytes while another row — or an evidence record the schema contract
     * does not cover — still points at it.
     */
    public function isFileReferencedElsewhere(ChatterAttachment $attachment): bool
    {
        $path = (string) $attachment->storage_path;
        if ($path === '') {
            return false;
        }

        $disk = (string) ($attachment->storage_disk ?: 'public');

        $referencedByAnotherAttachment = ChatterAttachment::query()
            ->whereKeyNot($attachment->getKey())
            ->where('storage_path', $path)
            ->where(function ($query) use ($disk) {
                $query->where('storage_disk', $disk)->orWhereNull('storage_disk');
            })
            ->exists();

        if ($referencedByAnotherAttachment) {
            return true;
        }

        // Evidence records own the file even when this attachment row does not
        // carry the schema reference (legacy rows).
        return $this->storedPathEvidenceDescription($path) !== null;
    }

    private function evidenceTypeLabel(string $type): string
    {
        return match ($type) {
            InvoiceEvidence::class => 'invoice evidence',
            EsignRequest::class => 'an electronic signature request',
            DeliveryOrder::class => 'a signed delivery order',
            default => 'a document',
        };
    }

    /**
     * Safety net (#629): find the evidence record that owns this exact stored
     * path. Soft-deleted rows are included — revoked evidence is still an
     * audit artifact whose file must not disappear through chatter.
     */
    private function storedPathEvidenceDescription(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        // Invoice evidence (uploads, customer portal acceptance, e-sign).
        $invoiceEvidence = InvoiceEvidence::query()->withTrashed()->where('file_path', $path)->first();
        if ($invoiceEvidence) {
            return 'invoice evidence #'.$invoiceEvidence->id;
        }

        // Built-in e-signature signed copies (invoice / delivery order /
        // quotation) recorded on the e-sign request.
        $esign = EsignRequest::query()->withTrashed()->where('signed_document_path', $path)->first();
        if ($esign) {
            return 'an electronic signature request #'.$esign->id;
        }

        // Signed delivery orders stored on the order itself (the public
        // acceptance portal path, which creates no e-sign request).
        $deliveryOrder = DeliveryOrder::query()->where('signed_document_path', $path)->first();
        if ($deliveryOrder) {
            return 'a signed delivery order #'.$deliveryOrder->id;
        }

        return null;
    }

    /**
     * Issue #78: stream an attachment through the authenticated API instead of
     * exposing the underlying storage path/URL structure.
     */
    public function downloadAttachment(string $subjectType, int|string $subjectId, int $attachmentId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $attachment = $this->findAttachment($subjectType, $subjectId, $attachmentId);

        $disk = $attachment->storage_disk ?: 'public';

        abort_unless(Storage::disk($disk)->exists($attachment->storage_path), 404, 'Attachment file not found.');

        return Storage::disk($disk)->download($attachment->storage_path, $attachment->filename);
    }

    public function deleteAttachment(string $subjectType, int|string $subjectId, int $attachmentId): void
    {
        $attachment = $this->findAttachment($subjectType, $subjectId, $attachmentId);

        // Issue #630: never destroy the stored file while it is evidence or is
        // referenced by another record. The controller refuses evidence-linked
        // deletes with a 403; this is the last line of defence for direct
        // service calls.
        if (! $this->isEvidenceLinked($attachment) && ! $this->isFileReferencedElsewhere($attachment)) {
            Storage::disk($attachment->storage_disk ?: 'local')->delete($attachment->storage_path);
        }

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
        $causerType = $actorType ?? (Auth::check() ? get_class(Auth::user()) : null);
        $causerId = $actorId ?? Auth::id();

        if ($causerType === null || $causerId === null) {
            $causerType = null;
            $causerId = null;
        }

        try {
            $attributes = [
                'log_name' => 'chatter',
                'description' => $description,
                'subject_type' => $subjectType,
                'subject_id' => (int) $subjectId,
                'causer_type' => $causerType,
                'causer_id' => $causerId,
                'event' => $event,
                'properties' => $properties,
            ];

            // The shared activity_log in `public` requires the explicit tenant
            // discriminator. Some older tenant schemas keep an isolated
            // activity_log that has no such column — activityLogHasTenantId()
            // inspects the table the connection actually resolves.
            if ($this->activityLogHasTenantId()) {
                $attributes['tenant_id'] = $this->currentTenantId();
            }

            ActivityLogEntry::create($attributes);
        } catch (\Throwable $e) {
            // never break user-visible flows because chatter logging failed
            report($e);
        }

        // Issue #639: creation and stage-change chatter events also drive the
        // document-follower rules (auto-follow + subscription-aware fan-out).
        $this->applyFollowerRules($subjectType, (int) $subjectId, $event, $causerId, $properties);
    }

    /**
     * Issue #639 (CHATTER-EPIC #628): document-follower side effects that ride
     * on the canonical lifecycle events.
     *
     *  - `created`      → the actor (creator) and the resolved salesperson
     *                     auto-follow the document;
     *  - stage changes  → the actor auto-follows (`auto:stage_change`) and
     *                     followers subscribed to stage changes are notified.
     *
     * Best-effort by design: the chatter row above is already written and the
     * calling document transition must not fail because a follower row or a
     * notification could not be written. Failures are reported, never hidden.
     *
     * @param  array<string, mixed>  $properties
     */
    private function applyFollowerRules(
        string $subjectType,
        int $subjectId,
        string $event,
        int|string|null $causerId,
        array $properties,
    ): void {
        $isCreated = $event === DocumentFollowerService::CREATED_EVENT;
        $isStageChange = in_array($event, DocumentFollowerService::STAGE_EVENTS, true);

        if (! $isCreated && ! $isStageChange) {
            return;
        }

        try {
            $actorId = is_numeric($causerId) ? (int) $causerId : null;
            $service = app(DocumentFollowerService::class);

            if ($isCreated) {
                $document = class_exists($subjectType) && is_subclass_of($subjectType, Model::class)
                    ? $subjectType::find($subjectId)
                    : null;

                if ($document !== null) {
                    $service->autoFollowOnCreate($document, $actorId);
                }

                return;
            }

            $service->autoFollowActor($subjectType, $subjectId, $actorId, DocumentFollower::SOURCE_STAGE_CHANGE);
            $service->notifyFollowers(
                DocumentFollowerService::EVENT_STAGE_CHANGE,
                $subjectType,
                $subjectId,
                $actorId,
                [
                    'from_state' => $properties['from_state'] ?? null,
                    'to_state' => $properties['to_state'] ?? null,
                ],
            );
        } catch (\Throwable $e) {
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
