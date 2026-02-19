<?php

namespace Modules\AI\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\AI\Services\ChatbotService;
use Modules\AI\Models\AIConversation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

/**
 * Chatbot Controller
 * 
 * Handles AI chatbot API endpoints
 */
class ChatbotController extends Controller
{
    protected ChatbotService $chatbotService;
    
    public function __construct(ChatbotService $chatbotService)
    {
        $this->chatbotService = $chatbotService;
    }
    
    /**
     * Send message to chatbot
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|integer|exists:customers,id',
            'message' => 'required|string|max:1000',
            'channel' => 'nullable|string|in:web,whatsapp,telegram',
            'conversation_id' => 'nullable|integer|exists:ai_conversations,id',
            'is_button_press' => 'nullable|boolean',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }
        
        try {
            $customerId = $request->input('customer_id');
            $message = $request->input('message');
            $channel = $request->input('channel', 'web');
            $conversationId = $request->input('conversation_id');
            $isButtonPress = $request->input('is_button_press', false);
            
            // Get or create conversation
            if ($conversationId) {
                $conversation = AIConversation::findOrFail($conversationId);
            } else {
                $conversation = $this->chatbotService->getOrCreateConversation(
                    $customerId,
                    $channel
                );
            }
            
            // Check if this is the first message BEFORE processing
            $isFirstMessage = $this->chatbotService->isFirstMessage($conversation);
            
            // Process message (pass isButtonPress flag)
            $response = $this->chatbotService->processMessage(
                $conversation, 
                $message,
                $isButtonPress
            );
            
            // Reload conversation to get updated active_module
            $conversation->refresh();
            
            // Log for debugging
            Log::info('ChatbotController - After processing message', [
                'conversation_id' => $conversation->id,
                'active_module' => $conversation->active_module,
                'message' => $message,
                'fallback_mode' => $this->chatbotService->shouldUseFallbackMode(),
            ]);
            
            // Prepare response data
            $responseData = [
                'success' => true,
                'conversation_id' => $conversation->id,
                'message' => $response,
                'timestamp' => now()->toIso8601String(),
                'fallback_mode' => $this->chatbotService->shouldUseFallbackMode(),
            ];
            
            // Add quick options or common questions based on mode
            $activeModule = $conversation->active_module ?? 'general';
            
            // If in fallback mode, always show common questions
            if ($this->chatbotService->shouldUseFallbackMode()) {
                $responseData['common_questions'] = $this->chatbotService->getCommonQuestions($conversation);
                $responseData['show_buttons'] = true;
            } elseif ($activeModule === 'general') {
                // In normal mode with general module, show module selection
                $responseData['quick_options'] = $this->chatbotService->getQuickOptions();
            } else {
                // In specific module, always show common questions for that module
                $responseData['common_questions'] = $this->chatbotService->getCommonQuestions($conversation);
                $responseData['show_buttons'] = true;
            }
            
            Log::info('ChatbotController - Response data', [
                'has_quick_options' => isset($responseData['quick_options']),
                'has_common_questions' => isset($responseData['common_questions']),
                'fallback_mode' => $responseData['fallback_mode'],
            ]);
            
            return response()->json($responseData);
            
        } catch (\Exception $e) {
            Log::error('Chatbot message failed', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to process message. Please try again.',
            ], 500);
        }
    }
    
    /**
     * Get conversation history
     * 
     * @param int $conversationId
     * @return JsonResponse
     */
    public function getConversation(int $conversationId): JsonResponse
    {
        try {
            $conversation = AIConversation::with(['messages' => function ($query) {
                $query->whereNotNull('content')
                      ->orderBy('created_at', 'asc');
            }, 'customer'])
            ->findOrFail($conversationId);
            
            $responseData = [
                'success' => true,
                'conversation' => [
                    'id' => $conversation->id,
                    'customer' => [
                        'id' => $conversation->customer->id,
                        'name' => $conversation->customer->name,
                    ],
                    'channel' => $conversation->channel,
                    'status' => $conversation->status,
                    'language' => $conversation->language,
                    'active_module' => $conversation->active_module ?? 'general',
                    'started_at' => $conversation->started_at,
                    'messages' => $conversation->messages->map(function ($msg) {
                        return [
                            'id' => $msg->id,
                            'role' => $msg->role,
                            'content' => $msg->content,
                            'created_at' => $msg->created_at,
                        ];
                    }),
                ],
            ];
            
            // Add quick options if active_module is general
            if (($conversation->active_module ?? 'general') === 'general') {
                $responseData['quick_options'] = $this->chatbotService->getQuickOptions();
            }
            
            return response()->json($responseData);
            
        } catch (\Exception $e) {
            Log::error('Get conversation failed', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Conversation not found',
            ], 404);
        }
    }
    
    /**
     * Get customer's conversations
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getConversations(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|integer|exists:customers,id',
            'status' => 'nullable|string|in:active,closed,transferred',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }
        
        try {
            $customerId = $request->input('customer_id');
            $status = $request->input('status');
            $limit = $request->input('limit', 10);
            
            $query = AIConversation::where('customer_id', $customerId)
                ->with(['messages' => function ($q) {
                    $q->whereNotNull('content')
                      ->orderBy('created_at', 'desc')
                      ->take(1);
                }]);
            
            if ($status) {
                $query->where('status', $status);
            }
            
            $conversations = $query->orderBy('started_at', 'desc')
                ->take($limit)
                ->get()
                ->map(function ($conv) {
                    return [
                        'id' => $conv->id,
                        'channel' => $conv->channel,
                        'status' => $conv->status,
                        'language' => $conv->language,
                        'started_at' => $conv->started_at,
                        'last_message' => $conv->messages->first()?->content,
                        'message_count' => $conv->messages()->count(),
                    ];
                });
            
            return response()->json([
                'success' => true,
                'conversations' => $conversations,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Get conversations failed', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve conversations',
            ], 500);
        }
    }
    
    /**
     * Close conversation
     * 
     * @param int $conversationId
     * @return JsonResponse
     */
    public function closeConversation(int $conversationId): JsonResponse
    {
        try {
            $conversation = AIConversation::findOrFail($conversationId);
            $conversation->close();
            
            return response()->json([
                'success' => true,
                'message' => 'Conversation closed successfully',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Close conversation failed', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to close conversation',
            ], 500);
        }
    }
    
    /**
     * Delete conversation
     * 
     * @param int $conversationId
     * @return JsonResponse
     */
    public function deleteConversation(int $conversationId): JsonResponse
    {
        try {
            $conversation = AIConversation::findOrFail($conversationId);
            
            // Delete all messages first
            $conversation->messages()->delete();
            
            // Delete conversation
            $conversation->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Conversation deleted successfully',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Delete conversation failed', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to delete conversation',
            ], 500);
        }
    }
}
