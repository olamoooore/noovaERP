<?php

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\AI\Models\AIConversation;
use Modules\AI\Models\AIMessage;
use Modules\AI\Services\OllamaService;
use Modules\AI\Services\FunctionRouter;
use Modules\AI\Services\SubModuleManager;
use Modules\AI\Prompts\SystemPrompt;
use Modules\AI\Prompts\FunctionDefinitions;
use Stancl\Tenancy\Facades\Tenancy;

class ProcessAIMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries = 1;

    protected int $conversationId;
    protected int $pendingMessageId;
    protected string $tenantId;

    public function __construct(int $conversationId, int $pendingMessageId, string $tenantId)
    {
        $this->conversationId = $conversationId;
        $this->pendingMessageId = $pendingMessageId;
        $this->tenantId = $tenantId;
    }

    public function handle(OllamaService $ollama, FunctionRouter $router, SubModuleManager $moduleManager): void
    {
        try {
            // Initialize tenancy context using the platform runtime helper
            // instead of raw SET search_path — ensures all bootstrappers
            // (cache, filesystem, events) also switch, not just the DB schema.
            Tenancy::initialize($this->tenantId);

            $conversation = AIConversation::findOrFail($this->conversationId);
            $pendingMessage = AIMessage::findOrFail($this->pendingMessageId);

            // Build system prompt using the same method as ChatbotService
            $activeModule = $conversation->active_module ?? 'general';
            $basePrompt = SystemPrompt::get($conversation);
            
            if ($activeModule !== 'general') {
                $module = $moduleManager->getModule($activeModule);
                if ($module) {
                    $basePrompt .= "\n\n" . $module->getSystemPrompt();
                }
            }

            // Build message history
            $messages = [['role' => 'system', 'content' => $basePrompt]];
            
            $maxHistory = config('ai.conversation.max_history', 10);
            $history = $conversation->messages()
                ->whereNotNull('content')
                ->where('content', '!=', '')
                ->orderBy('created_at', 'desc')
                ->take($maxHistory)
                ->get()
                ->reverse();

            foreach ($history as $msg) {
                $messages[] = ['role' => $msg->role, 'content' => $msg->content];
            }

            // Get functions using the same logic as ChatbotService
            if ($activeModule === 'general') {
                $functions = FunctionDefinitions::getAll();
            } else {
                $module = $moduleManager->getModule($activeModule);
                if ($module) {
                    $moduleFunctions = $module->getFunctions();
                    $allFunctions = FunctionDefinitions::getAll();
                    $functions = array_values(array_filter($allFunctions, function($f) use ($moduleFunctions) {
                        return in_array($f['name'], $moduleFunctions);
                    }));
                } else {
                    $functions = FunctionDefinitions::getAll();
                }
            }

            // Call Ollama
            $response = $ollama->chat($messages, $functions);

            $assistantMessage = '';

            // Handle function call
            if ($response['function_call']) {
                $functionCall = $response['function_call'];
                $functionName = $functionCall['function']['name'] ?? null;
                $arguments = json_decode($functionCall['function']['arguments'] ?? '{}', true) ?: [];

                if ($functionName) {
                    $result = $router->execute($functionName, $arguments, $conversation->customer_id, $this->conversationId);

                    // Tell AI about the function result
                    $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => [$functionCall]];
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $functionCall['id'] ?? 'call_1',
                        'content' => json_encode($result),
                    ];

                    $followUp = $ollama->chat($messages, $functions);
                    $assistantMessage = $followUp['content'] ?? 'I found some results but had trouble formatting them.';
                }
            } else {
                $assistantMessage = $response['content'] ?? 'I could not generate a response.';
            }

            // Clean up response
            $assistantMessage = $this->cleanResponse($assistantMessage);

            // Update the pending message with the actual response
            $pendingMessage->update([
                'content' => $assistantMessage,
                'tokens_used' => $response['tokens'] ?? 0,
            ]);

            Log::info('ProcessAIMessage completed', [
                'conversation_id' => $this->conversationId,
                'message_id' => $this->pendingMessageId,
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessAIMessage failed', [
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage(),
            ]);

            // Update pending message with error fallback
            try {
                // Ensure tenancy is active for the error update
                if (!Tenancy::getTenant()) {
                    Tenancy::initialize($this->tenantId);
                }

                $pendingMessage = AIMessage::find($this->pendingMessageId);
                if ($pendingMessage) {
                    $pendingMessage->update([
                        'content' => "I'm having trouble processing your request right now. Please try again or select one of the quick options below.",
                    ]);
                }
            } catch (\Exception $inner) {
                Log::error('Failed to update pending message with error', ['error' => $inner->getMessage()]);
            }
        }
    }

    protected function cleanResponse(string $text): string
    {
        $text = preg_replace('/```json\s*\{[^}]*\}\s*```/', '', $text);
        $text = preg_replace('/\{"(name|arguments)":[^}]*\}/', '', $text);
        return trim($text) ?: 'How can I help you?';
    }
}
