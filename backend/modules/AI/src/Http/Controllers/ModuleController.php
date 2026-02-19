<?php

namespace Modules\AI\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Models\AIConversation;
use Modules\AI\Services\SubModuleManager;
use Modules\AI\Services\CommonQuestionsService;

/**
 * Module Controller
 * 
 * Handles module selection and switching for AI conversations
 */
class ModuleController extends Controller
{
    /**
     * @var SubModuleManager
     */
    private SubModuleManager $moduleManager;

    /**
     * @var CommonQuestionsService
     */
    private CommonQuestionsService $commonQuestionsService;

    /**
     * Constructor
     *
     * @param SubModuleManager $moduleManager
     * @param CommonQuestionsService $commonQuestionsService
     */
    public function __construct(
        SubModuleManager $moduleManager,
        CommonQuestionsService $commonQuestionsService
    ) {
        $this->moduleManager = $moduleManager;
        $this->commonQuestionsService = $commonQuestionsService;
    }

    /**
     * Get all available modules
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $modules = $this->moduleManager->getModulesForApi();

        return response()->json([
            'success' => true,
            'data' => array_values($modules),
        ]);
    }

    /**
     * Select module for a conversation
     *
     * @param Request $request
     * @param int $conversationId
     * @return JsonResponse
     */
    public function selectModule(Request $request, int $conversationId): JsonResponse
    {
        try {
            $request->validate([
                'module' => 'required|string|max:50',
            ]);

            $moduleName = $request->input('module');

            // Check if module exists
            if (!$this->moduleManager->hasModule($moduleName)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid module name',
                ], 400);
            }

            // Find conversation
            $conversation = AIConversation::find($conversationId);

            if (!$conversation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conversation not found',
                ], 404);
            }

            // Update conversation module
            DB::transaction(function () use ($conversation, $moduleName) {
                $conversation->update(['active_module' => $moduleName]);

                // Track module switch (non-critical)
                if (Schema::hasTable('ai_conversation_modules')) {
                    DB::table('ai_conversation_modules')->insert([
                        'conversation_id' => $conversation->id,
                        'module_name' => $moduleName,
                        'switched_at' => now(),
                    ]);
                } else {
                    Log::warning('AI module switch tracking table missing (ai_conversation_modules)', [
                        'conversation_id' => $conversation->id,
                        'module' => $moduleName,
                    ]);
                }
            });

            $module = $this->moduleManager->getModule($moduleName);

            // Generate confirmation message
            $confirmationMessage = $this->getModuleConfirmationMessage($module);

            // Get common questions for this module
            $commonQuestions = $this->commonQuestionsService->getCommonQuestions($moduleName);

            return response()->json([
                'success' => true,
                'message' => $confirmationMessage,
                'common_questions' => $commonQuestions,
                'data' => [
                    'conversation_id' => $conversation->id,
                    'module' => $module->toArray(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Module selection failed', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to select module. Please try again.',
            ], 500);
        }
    }
    
    /**
     * Generate confirmation message for module selection
     *
     * @param mixed $module
     * @return string
     */
    private function getModuleConfirmationMessage($module): string
    {
        $displayName = $module->getDisplayName();
        
        $messages = [
            'orders' => "Great! I can help you with orders and shipping. What would you like to know?",
            'loyalty' => "Perfect! I can help you with loyalty points and rewards. How can I assist you?",
            'products' => "Awesome! I can help you find products and check availability. What are you looking for?",
            'inventory' => "Great! I can help you with inventory and stock levels. What would you like to check?",
            'invoices' => "Sure! I can help you with invoices and payments. What do you need?",
            'account' => "Of course! I can help you with your account information. What would you like to know?",
            'general' => "I'm here to help! What would you like to know?",
        ];
        
        return $messages[$module->getName()] ?? "I'm now focused on {$displayName}. How can I help you?";
    }

    /**
     * Get current module for a conversation
     *
     * @param int $conversationId
     * @return JsonResponse
     */
    public function getCurrentModule(int $conversationId): JsonResponse
    {
        $conversation = AIConversation::find($conversationId);

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        }

        $module = $this->moduleManager->getModule($conversation->active_module);

        return response()->json([
            'success' => true,
            'data' => [
                'conversation_id' => $conversation->id,
                'module' => $module ? $module->toArray() : null,
            ],
        ]);
    }
}
