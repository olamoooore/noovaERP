<?php

namespace Modules\AI\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Modules\AI\Models\AIConversation;
use Modules\AI\Services\AssistantConfig;
use Modules\AI\Services\ChatbotService;

/**
 * Public (guest) storefront AI assistant.
 *
 * Mounted on the shop/website pages for tenants with the assistant
 * enabled (see AssistantConfig). Conversations are guest-scoped through
 * a client-generated visitor_id stored on `ai_conversations.external_id`
 * — every poll/send is bound to it so guests can't address each other's
 * conversations. Replies run through the same ChatbotService →
 * ProcessAIMessage → OllamaService pipeline as the staff chatbot, so the
 * tenant's provider/model/BYOK selection applies automatically.
 */
class StorefrontAssistantController extends Controller
{
    public function __construct(
        private readonly AssistantConfig $assistantConfig,
        private readonly ChatbotService $chatbotService,
    ) {}

    /**
     * GET /storefront/assistant — bot identity + availability (never 403s;
     * the widget hides itself when disabled).
     */
    public function config(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'assistant' => $this->assistantConfig->toArray(),
        ]);
    }

    /**
     * POST /storefront/chat — send a guest message.
     */
    public function send(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'message' => 'required|string|max:1000',
            'visitor_id' => 'required|string|max:64',
            'conversation_id' => 'nullable|integer',
        ])->validate();

        $config = $this->assistantConfig->toArray();
        if (! $config['enabled']) {
            return response()->json([
                'success' => false,
                'error' => 'The AI assistant is not enabled for this shop.',
            ], 403);
        }

        $visitorId = (string) $validated['visitor_id'];
        $conversation = $this->resolveConversation($visitorId, $validated['conversation_id'] ?? null);

        if ($conversation === null) {
            $conversation = AIConversation::create([
                'customer_id' => null,
                'channel' => 'storefront',
                'external_id' => $visitorId,
                'status' => 'active',
            ]);
        }

        $response = $this->chatbotService->processMessage(
            $conversation,
            (string) $validated['message']
        );

        $payload = [
            'success' => true,
            'conversation_id' => $conversation->id,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($response === '__PROCESSING__') {
            $payload['processing'] = true;
            $payload['message'] = null;

            return response()->json($payload);
        }

        $payload['processing'] = false;
        $payload['message'] = $response;

        return response()->json($payload);
    }

    /**
     * GET /storefront/chat/{id}/poll — fetch the queued reply.
     */
    public function poll(Request $request, int $conversationId): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'visitor_id' => 'required|string|max:64',
        ])->validate();

        $conversation = AIConversation::where('id', $conversationId)
            ->where('channel', 'storefront')
            ->where('external_id', (string) $validated['visitor_id'])
            ->first();

        if (! $conversation) {
            return response()->json([
                'success' => false,
                'error' => 'Conversation not found.',
            ], 404);
        }

        $lastMessage = $conversation->messages()
            ->where('role', 'assistant')
            ->whereNotNull('content')
            ->orderBy('id', 'desc')
            ->first();

        if (! $lastMessage) {
            return response()->json([
                'success' => true,
                'processing' => true,
                'message' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'processing' => false,
            'message' => $lastMessage->content,
            'timestamp' => $lastMessage->created_at->toIso8601String(),
            'quick_options' => $this->shopperQuickOptions($conversation),
        ]);
    }

    /**
     * Reuse the active conversation for this visitor, or fall back to the
     * requested id only when it belongs to the same visitor (IDOR guard).
     */
    private function resolveConversation(string $visitorId, mixed $requestedId): ?AIConversation
    {
        if ($requestedId) {
            $conversation = AIConversation::find((int) $requestedId);
            if ($conversation
                && $conversation->channel === 'storefront'
                && $conversation->external_id === $visitorId) {
                return $conversation;
            }
        }

        return AIConversation::where('channel', 'storefront')
            ->where('external_id', $visitorId)
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }

    /**
     * Module quick-options minus back-office entries a shopper should
     * never see (account, invoices, finance/accounting modules).
     */
    private function shopperQuickOptions(AIConversation $conversation): array
    {
        return array_values(array_filter(
            $this->chatbotService->getQuickOptions(),
            fn (array $option) => ! preg_match(
                '/account|invoice|financial|journal|tax|payable|receivable|closing/i',
                (string) ($option['id'] ?? '')
            )
        ));
    }
}
