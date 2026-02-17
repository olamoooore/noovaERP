<?php

namespace Modules\Communication\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Models\CommunicationSubscriber;
use Modules\Communication\Models\CommunicationOutbox;
use Modules\Communication\Services\CommunicationEngine;

class CommunicationController extends Controller
{
    public function __construct(
        protected CommunicationEngine $engine,
    ) {}

    // ════════════════════════════════════════════════════════
    //  MESSAGE THREAD (Chatter)
    // ════════════════════════════════════════════════════════

    /**
     * GET /api/communication/messages
     *
     * Get message thread for a record.
     */
    public function getMessages(Request $request): JsonResponse
    {
        $request->validate([
            'model' => 'required|string',
            'record_id' => 'required|integer',
            'message_type' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $messages = $this->engine->getThread(
            model: $request->model,
            recordId: $request->record_id,
            tenantId: $request->header('X-Tenant-ID'),
            limit: $request->input('limit', 50),
            messageType: $request->message_type,
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
        $message = CommunicationMessage::with('outboxItems')->findOrFail($messageId);

        return response()->json([
            'success' => true,
            'data' => [
                'message_id' => $message->id,
                'status' => $message->status,
                'outbox_items' => $message->outboxItems->map(fn($item) => [
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
