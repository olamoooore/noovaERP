<?php

namespace Modules\Communication\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Services\CommunicationEngine;

class CommunicationController extends Controller
{
    public function __construct(
        protected CommunicationEngine $engine,
    ) {}

    /**
     * Issue #77 (CRITICAL): resolve the current user's role for internal-note
     * visibility. Returns null when the user has no recognised role (treat
     * as non-staff = fail-closed).
     */
    private function resolveUserRole(?\App\Models\User $user): ?string
    {
        if (! $user) {
            return null;
        }

        // Try Spatie roles first. NOTE: getRoleNames() returns a Collection —
        // `! empty($collection)` is always false for objects, so an empty role
        // list previously indexed [0] and crashed with 'Undefined array key 0'.
        if (method_exists($user, 'getRoleNames')) {
            $names = $user->getRoleNames();
            if ($names->isNotEmpty()) {
                return strtolower((string) $names->first());
            }
        }

        // Fall back to user_company_access row.
        $access = \Illuminate\Support\Facades\DB::table('user_company_access')
            ->where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->first();
        if ($access && isset($access->role)) {
            return strtolower((string) $access->role);
        }

        return null;
    }

    // ════════════════════════════════════════════════════════
    //  MESSAGE THREAD (Chatter)
    // ════════════════════════════════════════════════════════

    /**
     * GET /api/communication/messages
     *
     * Get message thread for a record.
     *
     * Issue #77 (CRITICAL): pass current user context so internal notes are
     * filtered. The engine's getThread() enforces role-based visibility.
     */
    public function getMessages(Request $request): JsonResponse
    {
        $request->validate([
            'model' => 'required|string',
            'record_id' => 'required|integer',
            'message_type' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $user = $request->user();
        $userRole = $this->resolveUserRole($user);

        $messages = $this->engine->getThread(
            model: $request->model,
            recordId: $request->record_id,
            tenantId: $request->header('X-Tenant-ID'),
            limit: $request->input('limit', 50),
            messageType: $request->message_type,
            currentUserId: $user?->id,
            userRole: $userRole,
        );

        return response()->json([
            'success' => true,
            'data' => $messages,
        ]);
    }

    /**
     * POST /api/communication/messages
     *
     * Post a comment or internal note on a record.
     */
    public function postMessage(Request $request): JsonResponse
    {
        $request->validate([
            'model' => 'required|string',
            'record_id' => 'required|integer',
            'body' => 'required|string',
            'is_internal_note' => 'nullable|boolean',
            'parent_id' => 'nullable|integer|exists:communication_messages,id',
        ]);

        $message = $this->engine->postComment(
            model: $request->model,
            recordId: $request->record_id,
            body: $request->body,
            authorId: $request->user()->id,
            isInternalNote: $request->boolean('is_internal_note', false),
            tenantId: $request->header('X-Tenant-ID'),
            parentId: $request->parent_id,
        );

        return response()->json([
            'success' => true,
            'data' => $message,
        ], 201);
    }

    // ════════════════════════════════════════════════════════
    //  SUBSCRIBERS (Followers)
    // ════════════════════════════════════════════════════════

    /**
     * GET /api/communication/subscribers
     */
    /**
     * Issue #88: attach a file to a message.
     */
    public function attachFile(Request $request, int $messageId): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:25600|mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,gif,txt,csv,zip',
            'is_inline' => 'nullable|boolean',
        ]);

        // Hostile re-review (C-1): message lookups must be tenant-scoped —
        // an unscoped findOrFail lets any tenant address another's message.
        $message = \Modules\Communication\Models\CommunicationMessage::query()
            ->where(function ($query) {
                $query->where('tenant_id', tenant()->id)->orWhereNull('tenant_id');
            })
            ->findOrFail($messageId);

        // Issue #88/#77: stamp the attachment with the ACTIVE tenant context
        // (the header is only a fallback) so upload and download agree on the
        // owning tenant.
        $tenantId = tenant()->id ?? $request->header('X-Tenant-ID');

        $attachment = $this->engine->attachToMessage(
            (int) $message->id,
            $request->file('file'),
            $request->user()?->id,
            $tenantId,
            (bool) $request->boolean('is_inline'),
        );

        return response()->json(['success' => true, 'data' => $attachment], 201);
    }

    /**
     * Issue #88: download an attachment (authenticated; no public paths).
     */
    public function downloadAttachment(Request $request, int $attachmentId): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        // Hostile re-review (#88/#77): the lookup was unscoped — any tenant's
        // user could download any attachment by id. Scope to the active tenant
        // and enforce the internal-note visibility rule for the parent message.
        $attachment = \Modules\Communication\Models\CommunicationAttachment::query()
            ->where(function ($query) {
                $query->where('tenant_id', tenant()->id)->orWhereNull('tenant_id');
            })
            ->findOrFail($attachmentId);

        $message = $attachment->message;

        if ($message && $message->message_type === CommunicationMessage::TYPE_INTERNAL_NOTE) {
            $user = $request->user();
            $role = $this->resolveUserRole($user);
            $isStaff = $role !== null && in_array($role, CommunicationEngine::STAFF_ROLES, true);
            $isAuthor = $user && (int) $message->author_id === (int) $user->id;

            if (! $isStaff && ! $isAuthor) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorised to download this attachment.',
                ], 403);
            }
        }

        $disk = \Illuminate\Support\Facades\Storage::disk($attachment->storage_disk);

        if (! $disk->exists($attachment->storage_path)) {
            return response()->json(['success' => false, 'message' => 'Attachment file is missing from storage.'], 404);
        }

        return $disk->download($attachment->storage_path, $attachment->filename);
    }

    /**
     * Issue #86: mark one message read for the current user.
     */
    public function markMessageRead(Request $request, int $messageId): JsonResponse
    {
        // Hostile re-review (C-1): message lookups must be tenant-scoped —
        // an unscoped findOrFail lets any tenant address another's message.
        $message = \Modules\Communication\Models\CommunicationMessage::query()
            ->where(function ($query) {
                $query->where('tenant_id', tenant()->id)->orWhereNull('tenant_id');
            })
            ->findOrFail($messageId);

        $state = $this->engine->markAsRead(
            (int) $message->id,
            (int) $request->user()->id,
            $request->header('X-Tenant-ID'),
        );

        return response()->json([
            'success' => true,
            'data' => ['message_id' => $message->id, 'read_at' => $state->read_at],
        ]);
    }

    /**
     * Issue #86: mark every message on a record read for the current user.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model' => 'required|string',
            'record_id' => 'required|integer',
        ]);

        $count = $this->engine->markAllAsRead(
            $validated['model'],
            (int) $validated['record_id'],
            (int) $request->user()->id,
            $request->header('X-Tenant-ID'),
        );

        return response()->json(['success' => true, 'data' => ['marked_read' => $count]]);
    }

    /**
     * Issue #86: unread counter, optionally scoped to a record.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model' => 'nullable|string',
            'record_id' => 'nullable|integer',
        ]);

        $count = $this->engine->unreadCount(
            (int) $request->user()->id,
            $request->header('X-Tenant-ID'),
            $validated['model'] ?? null,
            isset($validated['record_id']) ? (int) $validated['record_id'] : null,
            $this->resolveUserRole($request->user()),
        );

        return response()->json(['success' => true, 'data' => ['unread_count' => $count]]);
    }

    public function getSubscribers(Request $request): JsonResponse
    {
        $request->validate([
            'model' => 'required|string',
            'record_id' => 'required|integer',
        ]);

        $subscribers = $this->engine->getSubscribers(
            model: $request->model,
            recordId: $request->record_id,
            tenantId: $request->header('X-Tenant-ID'),
        );

        return response()->json([
            'success' => true,
            'data' => $subscribers->load(['user', 'customer']),
        ]);
    }

    /**
     * POST /api/communication/subscribers
     */
    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'model' => 'required|string',
            'record_id' => 'required|integer',
            'user_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'email' => 'nullable|email',
            'channels' => 'nullable|array',
            'subtypes' => 'nullable|array',
        ]);

        $subscriber = $this->engine->subscribe(
            model: $request->model,
            recordId: $request->record_id,
            userId: $request->user_id,
            customerId: $request->customer_id,
            email: $request->email,
            channels: $request->channels,
            subtypes: $request->subtypes,
            tenantId: $request->header('X-Tenant-ID'),
        );

        return response()->json([
            'success' => true,
            'data' => $subscriber,
        ], 201);
    }

    /**
     * DELETE /api/communication/subscribers
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $request->validate([
            'model' => 'required|string',
            'record_id' => 'required|integer',
            'user_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
        ]);

        $this->engine->unsubscribe(
            model: $request->model,
            recordId: $request->record_id,
            userId: $request->user_id,
            customerId: $request->customer_id,
            tenantId: $request->header('X-Tenant-ID'),
        );

        return response()->json(['success' => true]);
    }

    // ════════════════════════════════════════════════════════
    //  SEND MESSAGE
    // ════════════════════════════════════════════════════════

    /**
     * POST /api/communication/send
     *
     * Send a direct message to specific recipients.
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'recipient_user_ids' => 'nullable|array',
            'recipient_customer_ids' => 'nullable|array',
            'recipient_emails' => 'nullable|array',
            'channels' => 'nullable|array',
            'template_key' => 'nullable|string',
            'template_variables' => 'nullable|array',
            'model' => 'nullable|string',
            'record_id' => 'nullable|integer',
        ]);

        $message = $this->engine->sendDirect(
            subject: $request->subject,
            body: $request->body,
            recipientUserIds: $request->input('recipient_user_ids', []),
            recipientCustomerIds: $request->input('recipient_customer_ids', []),
            recipientEmails: $request->input('recipient_emails', []),
            channels: $request->input('channels', ['email', 'in_app']),
            model: $request->model,
            recordId: $request->record_id,
            authorId: $request->user()->id,
            tenantId: $request->header('X-Tenant-ID'),
            templateKey: $request->template_key,
            templateVariables: $request->input('template_variables', []),
        );

        return response()->json([
            'success' => true,
            'data' => $message,
        ], 201);
    }

    // ════════════════════════════════════════════════════════
    //  NOTIFY (Event-style)
    // ════════════════════════════════════════════════════════

    /**
     * POST /api/communication/notify
     *
     * Trigger a notification to all subscribers of a record.
     */
    public function notify(Request $request): JsonResponse
    {
        $request->validate([
            'model' => 'required|string',
            'record_id' => 'required|integer',
            'subtype' => 'required|string',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'template_key' => 'nullable|string',
            'template_variables' => 'nullable|array',
        ]);

        $message = $this->engine->notify(
            model: $request->model,
            recordId: $request->record_id,
            subtype: $request->subtype,
            subject: $request->subject,
            body: $request->body,
            authorId: $request->user()->id,
            tenantId: $request->header('X-Tenant-ID'),
            templateKey: $request->template_key,
            templateVariables: $request->input('template_variables', []),
        );

        return response()->json([
            'success' => true,
            'data' => $message,
        ], 201);
    }

    // ════════════════════════════════════════════════════════
    //  OUTBOX STATUS
    // ════════════════════════════════════════════════════════

    /**
     * GET /api/communication/outbox/{messageId}
     */
    public function getOutboxStatus(int $messageId): JsonResponse
    {
        // Hostile re-review (#76 B2): unscoped outbox lookup.
        $message = CommunicationMessage::with('outboxItems')
            ->where(function ($query) {
                $query->where('tenant_id', tenant()->id)->orWhereNull('tenant_id');
            })
            ->findOrFail($messageId);

        return response()->json([
            'success' => true,
            'data' => [
                'message_id' => $message->id,
                'status' => $message->status,
                'outbox_items' => $message->outboxItems->map(fn ($item) => [
                    'id' => $item->id,
                    'channel' => $item->channel,
                    'status' => $item->status,
                    'recipient_email' => $item->recipient_email,
                    'sent_at' => $item->sent_at,
                    'delivered_at' => $item->delivered_at,
                    'failed_at' => $item->failed_at,
                    'error_message' => $item->error_message,
                    'retry_count' => $item->retry_count,
                ]),
            ],
        ]);
    }
}
