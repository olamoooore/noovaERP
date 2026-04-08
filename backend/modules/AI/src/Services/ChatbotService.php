<?php

namespace Modules\AI\Services;

use Modules\AI\Models\AIConversation;
use Modules\AI\Models\AIMessage;
use Modules\AI\Prompts\SystemPrompt;
use Modules\AI\Prompts\FunctionDefinitions;
use Modules\AI\Services\SubModuleManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Chatbot Service
 * 
 * Main service for AI chatbot conversations
 */
class ChatbotService
{
    protected OllamaService $ollama;
    protected FunctionRouter $router;
    protected SubModuleManager $moduleManager;
    protected CommonQuestionsService $commonQuestions;
    protected bool $fallbackMode = false;
    
    public function __construct(
        OllamaService $ollama,
        FunctionRouter $router,
        SubModuleManager $moduleManager,
        CommonQuestionsService $commonQuestions
    ) {
        $this->ollama = $ollama;
        $this->router = $router;
        $this->moduleManager = $moduleManager;
        $this->commonQuestions = $commonQuestions;
    }
    
    /**
     * Check if system should use fallback mode
     */
    public function shouldUseFallbackMode(): bool
    {
        return $this->fallbackMode;
    }
    
    /**
     * Enable fallback mode
     */
    public function enableFallbackMode(): void
    {
        $this->fallbackMode = true;
    }
    
    /**
     * Process user message and generate response
     * 
     * @param AIConversation $conversation Conversation context
     * @param string $userMessage User's message
     * @param bool $isQuestionId If true, treat message as question ID from button press
     * @return string Assistant's response
     * @throws \Exception
     */
    public function processMessage(
        AIConversation $conversation,
        string $userMessage,
        bool $isQuestionId = false
    ): string {
        DB::beginTransaction();
        
        try {
            // If this is a question ID from button press, handle it directly
            if ($isQuestionId) {
                $result = $this->commonQuestions->handleQuestion(
                    $userMessage,
                    $conversation->customer_id
                );
                
                if ($result['success']) {
                    // Save the user's selection (for context)
                    $this->saveMessage($conversation, 'user', "[Selected: {$userMessage}]");
                    
                    // Save the response
                    $this->saveMessage($conversation, 'assistant', $result['message']);
                    DB::commit();
                    return $result['message'];
                } else {
                    DB::commit();
                    return $result['message'] ?? 'Sorry, I could not process that request.';
                }
            }
            
            // Save user message
            $this->saveMessage($conversation, 'user', $userMessage);
            
            // Try to use AI model
            try {
                // Get conversation history
                $messages = $this->getConversationHistory($conversation);
                
                // Add current message
                $messages[] = [
                    'role' => 'user',
                    'content' => $userMessage,
                ];
                
                // Get functions filtered by active module
                $functions = $this->getModuleFunctions($conversation);
                
                // Call Ollama
                $response = $this->ollama->chat($messages, $functions);
                
                // Handle function call
                if ($response['function_call']) {
                    $assistantMessage = $this->handleFunctionCall(
                        $conversation,
                        $messages,
                        $response
                    );
                } else {
                    // Check if AI mentioned a function call in text (3B model fallback)
                    $extractedCall = $this->extractFunctionCallFromText($response['content']);
                    if ($extractedCall) {
                        // Create proper function call structure
                        $response['function_call'] = [
                            'id' => 'call_' . uniqid(),
                            'type' => 'function',
                            'function' => $extractedCall,
                        ];
                        $assistantMessage = $this->handleFunctionCall(
                            $conversation,
                            $messages,
                            $response
                        );
                    } else {
                        $assistantMessage = $response['content'];
                    }
                }
                
                // Clean up response (remove JSON artifacts)
                $assistantMessage = $this->cleanResponse($assistantMessage);
                
                // Save response
                $this->saveMessage(
                    $conversation,
                    'assistant',
                    $assistantMessage,
                    ['tokens' => $response['tokens']]
                );
                
                DB::commit();
                return $assistantMessage;
                
            } catch (\Exception $ollamaError) {
                // Ollama failed - enable fallback mode
                Log::warning('Ollama unavailable, enabling fallback mode', [
                    'error' => $ollamaError->getMessage(),
                ]);
                
                $this->enableFallbackMode();
                
                // Return fallback message
                $fallbackMessage = "I'm having trouble understanding complex questions right now. " .
                                 "Please select one of the options below to help you:";
                
                $this->saveMessage($conversation, 'assistant', $fallbackMessage);
                DB::commit();
                return $fallbackMessage;
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Message processing failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    
    /**
     * Check if question is simple and doesn't need ERP data
     */
    protected function isSimpleQuestion(string $message): bool
    {
        $message = strtolower($message);
        
        // "How to" questions about policies (don't need data)
        if (preg_match('/how (to|do|can|does)/i', $message)) {
            // These are policy questions, not data queries
            $policyKeywords = ['earn', 'get', 'redeem', 'use', 'work', 'qualify', 'become', 'join'];
            foreach ($policyKeywords as $keyword) {
                if (str_contains($message, $keyword)) {
                    return true;
                }
            }
        }
        
        // Math questions
        if (preg_match('/^\d+[\+\-\*\/]\d+\s*=?\s*\??\s*$/', $message)) {
            return true;
        }
        
        // Questions about the AI itself
        $aiQuestions = [
            'what model', 'which model', 'what ai', 'which ai',
            'who are you', 'what are you', 'your name', 'your model',
            'llama', 'gpt', 'chatgpt', 'openai',
        ];
        foreach ($aiQuestions as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }
        
        // Greetings (only if short and at word boundary)
        $greetings = ['hello', 'hi', 'hey', 'good morning', 'good afternoon', 'good evening', '你好', 'hai', 'testing', 'test'];
        foreach ($greetings as $greeting) {
            // Use word boundary to avoid matching "hi" in "anything", "this", etc.
            if (preg_match('/\b' . preg_quote($greeting, '/') . '\b/i', $message) && strlen($message) < 30) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Handle simple questions without calling AI
     */
    protected function handleSimpleQuestion(string $message): string
    {
        $message = strtolower(trim($message));
        
        // "How to" questions about policies
        if (preg_match('/how (to|do|can)/i', $message)) {
            if (str_contains($message, 'earn') || str_contains($message, 'get')) {
                if (str_contains($message, 'point') || str_contains($message, 'reward')) {
                    return "You earn loyalty points by making purchases. The more you spend, the more points you earn! Each purchase adds points to your account based on the amount spent.";
                }
            }
            if (str_contains($message, 'redeem') || str_contains($message, 'use')) {
                if (str_contains($message, 'point') || str_contains($message, 'reward')) {
                    return "You can redeem your loyalty points for discounts on future purchases. The points can be converted to discount amounts when you checkout.";
                }
            }
        }
        
        // Questions about AI model
        $aiPatterns = ['model', 'ai', 'llama', 'gpt', 'who are you', 'what are you'];
        foreach ($aiPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return "I'm powered by " . config('ai.ollama.model', 'Llama') . ", running locally on your system. How can I help with your orders or loyalty points?";
            }
        }
        
        // Math questions
        if (preg_match('/^(\d+)([\+\-\*\/])(\d+)\s*=?\s*\??\s*$/', $message, $matches)) {
            $num1 = (int)$matches[1];
            $operator = $matches[2];
            $num2 = (int)$matches[3];
            
            $result = match($operator) {
                '+' => $num1 + $num2,
                '-' => $num1 - $num2,
                '*' => $num1 * $num2,
                '/' => $num2 != 0 ? $num1 / $num2 : 'undefined',
                default => 'error',
            };
            
            return "{$result}. How can I help you with your orders or loyalty points?";
        }
        
        // Greetings
        if (str_contains($message, 'hello') || str_contains($message, 'hi') || str_contains($message, 'hey')) {
            return "Hello! How can I assist you today? I can help with orders, invoices, loyalty points, and products.";
        }
        
        if (str_contains($message, 'testing') || str_contains($message, 'test')) {
            return "Hello! I'm ready to help. What would you like to test? I can check orders, loyalty points, search products, or get invoice details.";
        }
        
        if (str_contains($message, '你好') || str_contains($message, 'hai')) {
            return "您好！有什么可以帮您的？我可以帮您查询订单、发票、积分和产品。";
        }
        
        return "Hello! How can I help you today?";
    }
    
    /**
     * Extract function call from text (fallback for 3B model)
     * 
     * When the 3B model outputs function calls as text instead of proper tool_calls,
     * this method extracts them and converts to proper format.
     */
    protected function extractFunctionCallFromText(string $text): ?array
    {
        // Pattern: create_ticket(subject="...", description="...", priority="...", category="...")
        // Also handles: create_ticket(subject='...', ...)
        
        // List of known functions
        $knownFunctions = [
            'create_ticket',
            'search_customers',
            'check_order_status',
            'list_customer_orders',
            'get_loyalty_points',
            'get_loyalty_rewards',
            'search_products',
            'get_invoice',
        ];
        
        foreach ($knownFunctions as $functionName) {
            // Look for function_name(...)
            if (preg_match('/' . preg_quote($functionName, '/') . '\s*\((.*?)\)/s', $text, $matches)) {
                $argsString = $matches[1];
                
                // Parse arguments
                $arguments = $this->parseArgumentsString($argsString);
                
                if (!empty($arguments)) {
                    return [
                        'name' => $functionName,
                        'arguments' => json_encode($arguments),
                    ];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Parse arguments string into array
     * 
     * Parses: subject="...", description="...", priority="medium"
     * Into: ['subject' => '...', 'description' => '...', 'priority' => 'medium']
     */
    protected function parseArgumentsString(string $argsString): array
    {
        $arguments = [];
        
        // Pattern: key="value" or key='value'
        preg_match_all('/(\w+)\s*=\s*["\']([^"\']*)["\']/', $argsString, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $key = $match[1];
            $value = $match[2];
            $arguments[$key] = $value;
        }
        
        return $arguments;
    }
    
    /**
     * Clean AI response from JSON artifacts
     */
    protected function cleanResponse(string $response): string
    {
        // Remove JSON function calls (multiple patterns to catch all variations)
        
        // Pattern 1: Normal JSON
        $response = preg_replace('/\{["\']name["\']\s*:\s*["\'][^"\']+["\']\s*,\s*["\']parameters["\']\s*:\s*\{[^}]*\}\s*\}/i', '', $response);
        
        // Pattern 2: Escaped JSON
        $response = preg_replace('/\{\\"name\\":\\"[^"]+\\",\\"parameters\\"[^}]*\}/', '', $response);
        
        // Pattern 3: Mixed escaped/unescaped
        $response = preg_replace('/\{["\']name["\'][^}]*parameters["\'][^}]*\}/', '', $response);
        
        // Pattern 4: Any JSON-like structure with "name" and "parameters"
        $response = preg_replace('/\{[^}]*name[^}]*parameters[^}]*\}/', '', $response);
        
        // Pattern 5: Incomplete JSON (just {"name":"function_name")
        $response = preg_replace('/\{["\']name["\']\s*:\s*["\'][^"\']+["\']?\s*$/i', '', $response);
        $response = preg_replace('/\{["\']name["\']\s*:\s*["\'][^"\']+["\']\s*$/i', '', $response);
        
        // Remove "Since the prompt..." explanations (but not "Since the user...")
        $response = preg_replace('/Since the prompt[^.]+\.\s*/i', '', $response);
        $response = preg_replace('/Since I (don\'t have|cannot|can\'t)[^.]+\.\s*/i', '', $response);
        $response = preg_replace('/Here\'s (a|the) JSON response:?\s*/i', '', $response);
        $response = preg_replace('/Here are the function calls:?\s*/i', '', $response);  // Also remove "Here are the function calls"
        $response = preg_replace('/Let\'s use the `[^`]+` function\.\s*/i', '', $response);
        $response = preg_replace('/I\'ll use the `[^`]+` (function|tool)\.\s*/i', '', $response);
        $response = preg_replace('/I will (use|call) the `[^`]+` (function|tool)\.\s*/i', '', $response);
        
        // Remove "Since the user's question..." thinking out loud
        $response = preg_replace('/Since the user\'s question[^.]+\.\s*/i', '', $response);
        $response = preg_replace('/Since the user does not[^.]+\.\s*/i', '', $response);
        $response = preg_replace('/Since the user is asking[^.]+\.\s*/i', '', $response);
        $response = preg_replace('/Since the user[^.]+we should[^.]+\.\s*/i', '', $response);
        $response = preg_replace('/Since the user\'s request[^.]+\.\s*/i', '', $response);
        
        // Remove "To be safe..." explanations
        $response = preg_replace('/To be safe[^.]+\.\s*/i', '', $response);
        $response = preg_replace('/However, To be safe[^.]+\.\s*/i', '', $response);
        
        // Remove "we should use..." explanations
        $response = preg_replace('/we should use the `[^`]+` function\.\s*/i', '', $response);
        $response = preg_replace('/I will use the `[^`]+` function\.\s*/i', '', $response);
        $response = preg_replace('/I will (call|provide a call to) `[^`]+`[^.]*\.\s*/i', '', $response);
        
        // Remove "I couldn't understand..." followed by function call explanation
        $response = preg_replace('/I couldn\'t understand[^.]+\. Could you please rephrase[^?]+\? However, I can suggest[^:]+:\s*/i', '', $response);
        $response = preg_replace('/I couldn\'t understand[^.]+\. It seems like[^.]+\. To answer your request[^,]+, I will call[^:]+:\s*/i', '', $response);
        
        // Remove "Here is the JSON response:" and similar
        $response = preg_replace('/Here is the JSON response:\s*/i', '', $response);
        $response = preg_replace('/To answer your request[^,]+, I will call `[^`]+` function:\s*/i', '', $response);
        
        // Remove tool/function call mentions
        $response = preg_replace('/Using the [a-z_]+ (function|tool)[^.]*\.\s*/i', '', $response);
        
        // Clean up extra whitespace and leftover brackets
        $response = preg_replace('/\s+/', ' ', $response);
        $response = preg_replace('/\s*\}\s*$/', '', $response); // Remove trailing }
        $response = preg_replace('/^\s*\{\s*/', '', $response); // Remove leading {
        $response = trim($response);
        
        // If response is empty or just JSON, return a friendly message
        if (empty($response) || preg_match('/^\{.*\}$/', $response) || preg_match('/^[\{\}\[\]]+$/', $response)) {
            return "I apologize, but I encountered an issue processing your request. How else can I help you today?";
        }
        
        return $response;
    }
    
    /**
     * Handle function call
     */
    protected function handleFunctionCall(
        AIConversation $conversation,
        array $messages,
        array $response
    ): string {
        try {
            $functionCall = $response['function_call'];
            $functionName = $functionCall['function']['name'];
            $arguments = json_decode($functionCall['function']['arguments'], true);
            
            // Execute function (may fail)
            try {
                $functionResult = $this->router->execute(
                    $functionName, 
                    $arguments,
                    $conversation->customer_id,
                    $conversation->id
                );
            } catch (\Exception $e) {
                Log::error('Function execution failed', [
                    'function' => $functionName,
                    'error' => $e->getMessage(),
                ]);
                
                // Return mock success result to continue conversation
                $functionResult = [
                    'success' => false,
                    'error' => 'Function temporarily unavailable',
                    'note' => 'This feature is not yet available in the system',
                ];
            }
            
            // Commit current transaction before saving
            DB::commit();
            
            // Start new transaction for saving
            DB::beginTransaction();
            
            // Save function call
            $this->saveMessage($conversation, 'assistant', null, [
                'function_call' => $functionCall,
                'function_response' => $functionResult,
            ]);
            
            // Add to messages
            $messages[] = [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [$functionCall],
            ];
            
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $functionCall['id'] ?? 'call_1',
                'content' => json_encode($functionResult),
            ];
            
            // Get final response
            $finalResponse = $this->ollama->chat($messages);
            return $this->cleanResponse($finalResponse['content']);
            
        } catch (\Exception $e) {
            Log::error('Function call handler failed', [
                'function' => $functionName ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            
            // Return a friendly error message
            return "I apologize, but I encountered an issue processing your request. How else can I help you today?";
        }
    }
    
    /**
     * Get functions filtered by active module
     */
    protected function getModuleFunctions(AIConversation $conversation): array
    {
        $activeModule = $conversation->active_module ?? 'general';
        
        // If general module, return all functions
        if ($activeModule === 'general') {
            return FunctionDefinitions::getAll();
        }
        
        // Get module-specific functions
        $module = $this->moduleManager->getModule($activeModule);
        if (!$module) {
            return FunctionDefinitions::getAll();
        }
        
        $moduleFunctions = $module->getFunctions();
        $allFunctions = FunctionDefinitions::getAll();
        
        // Filter functions by module and re-index array
        $filtered = array_filter($allFunctions, function($function) use ($moduleFunctions) {
            return in_array($function['name'], $moduleFunctions);
        });
        
        // Re-index array to ensure it's a proper JSON array, not object
        return array_values($filtered);
    }
    
    /**
     * Get conversation history
     */
    protected function getConversationHistory(AIConversation $conversation): array
    {
        // Get module-specific system prompt
        $systemPrompt = $this->getModuleSystemPrompt($conversation);
        
        $messages = [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
        ];
        
        $maxHistory = config('ai.conversation.max_history', 10);
        
        $history = $conversation->messages()
            ->whereNotNull('content')
            ->orderBy('created_at', 'desc')
            ->take($maxHistory)
            ->get()
            ->reverse();
        
        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg->role,
                'content' => $msg->content,
            ];
        }
        
        return $messages;
    }
    
    /**
     * Get module-specific system prompt
     */
    protected function getModuleSystemPrompt(AIConversation $conversation): string
    {
        $activeModule = $conversation->active_module ?? 'general';
        
        // Get base system prompt
        $basePrompt = SystemPrompt::get($conversation);
        
        // If general module, return base prompt
        if ($activeModule === 'general') {
            return $basePrompt;
        }
        
        // Get module-specific context
        $module = $this->moduleManager->getModule($activeModule);
        if (!$module) {
            return $basePrompt;
        }
        
        $moduleContext = $module->getSystemPrompt();
        
        // Combine base prompt with module context
        return $basePrompt . "\n\n" . $moduleContext;
    }
    
    /**
     * Save message
     */
    protected function saveMessage(
        AIConversation $conversation,
        string $role,
        ?string $content,
        array $extra = []
    ): void {
        AIMessage::create([
            'conversation_id' => $conversation->id,
            'role' => $role,
            'content' => $content,
            'function_call' => $extra['function_call'] ?? null,
            'function_response' => $extra['function_response'] ?? null,
            'tokens_used' => $extra['tokens'] ?? 0,
            'cost' => 0,
        ]);
    }
    
    /**
     * Get or create conversation
     */
    public function getOrCreateConversation(
        int $customerId,
        string $channel = 'web',
        ?string $externalId = null
    ): AIConversation {
        $conversation = AIConversation::where('customer_id', $customerId)
            ->where('channel', $channel)
            ->where('status', 'active')
            ->when($externalId, fn($q) => $q->where('external_id', $externalId))
            ->first();
        
        if (!$conversation) {
            $conversation = AIConversation::create([
                'customer_id' => $customerId,
                'channel' => $channel,
                'external_id' => $externalId,
                'status' => 'active',
                'language' => 'en',
                'started_at' => now(),
                'active_module' => 'general', // Default module
            ]);
        }
        
        return $conversation;
    }

    /**
     * Check if this is the first message in conversation
     *
     * @param AIConversation $conversation
     * @return bool
     */
    public function isFirstMessage(AIConversation $conversation): bool
    {
        // Check if there are any USER messages (not assistant messages)
        return AIMessage::where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->count() === 0;
    }

    /**
     * Get quick options for module selection
     *
     * @return array
     */
    public function getQuickOptions(): array
    {
        $modules = $this->moduleManager->getAllModules();
        
        $options = [];
        foreach ($modules as $module) {
            // Skip general module (it's the default)
            if ($module->getName() === 'general') {
                continue;
            }
            
            $options[] = [
                'id' => $module->getName(),
                'icon' => $module->getIcon(),
                'label' => $module->getDisplayName(),
                'description' => $module->getDescription(),
            ];
        }
        
        return $options;
    }

    /**
     * Get greeting message with quick options
     *
     * @return string
     */
    public function getGreetingMessage(): string
    {
        return "👋 Hi! How can I help you today?";
    }
    
    /**
     * Get common questions for current module
     * 
     * @param AIConversation $conversation
     * @return array
     */
    public function getCommonQuestions(AIConversation $conversation): array
    {
        $activeModule = $conversation->active_module ?? 'general';
        return $this->commonQuestions->getCommonQuestions($activeModule);
    }
}
