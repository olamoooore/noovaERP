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
use Modules\AI\Prompts\FunctionDefinitions;
use Modules\AI\Prompts\SystemPrompt;
use Modules\AI\Services\FunctionRouter;
use Modules\AI\Services\OllamaService;
use Modules\AI\Services\SubModuleManager;
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
            // Skip when tenancy is already initialized (the queue bootstrapper
            // restores it from the job payload for tenant-aware dispatches).
            // If the extracted dispatch-time schema is not a tenant schema
            // (e.g. `public`), continue on the current search_path instead of
            // failing the whole job.
            if (! tenancy()->initialized) {
                try {
                    Tenancy::initialize($this->tenantId);
                } catch (\Throwable $e) {
                    Log::warning('ProcessAIMessage: tenancy initialization skipped', [
                        'tenant_id' => $this->tenantId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $conversation = AIConversation::findOrFail($this->conversationId);
            $pendingMessage = AIMessage::findOrFail($this->pendingMessageId);

            // Build system prompt using the same method as ChatbotService
            $activeModule = $conversation->active_module ?? 'general';
            $basePrompt = SystemPrompt::get($conversation);

            if ($activeModule !== 'general') {
                $module = $moduleManager->getModule($activeModule);
                if ($module) {
                    $basePrompt .= "\n\n".$module->getSystemPrompt();
                }
            }

            // Retrieval-augmented context: tenant knowledge for every
            // conversation, plus live catalog + shopper account data for
            // storefront conversations.
            try {
                $userMessage = (string) ($conversation->messages()
                    ->where('role', 'user')
                    ->whereNotNull('content')
                    ->latest('id')
                    ->value('content') ?? '');

                $basePrompt .= app(\Modules\AI\Services\AssistantContextService::class)
                    ->build($conversation, $userMessage);
            } catch (\Throwable $e) {
                Log::warning('ProcessAIMessage: retrieval context build failed', [
                    'conversation_id' => $this->conversationId,
                    'error' => $e->getMessage(),
                ]);
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
            $isStorefront = $conversation->channel === 'storefront';

            if ($activeModule === 'general') {
                $functions = $isStorefront ? FunctionDefinitions::shopperSafe() : FunctionDefinitions::getAll();
            } else {
                $module = $moduleManager->getModule($activeModule);
                if ($module) {
                    $moduleFunctions = $module->getFunctions();
                    $allFunctions = FunctionDefinitions::getAll();
                    $functions = array_values(array_filter($allFunctions, function ($f) use ($moduleFunctions) {
                        return in_array($f['name'], $moduleFunctions);
                    }));
                } else {
                    $functions = FunctionDefinitions::getAll();
                }
            }

            // Public storefront guests may only ever execute the
            // shopper-safe subset — never customer, invoice, accounting
            // or inventory functions.
            if ($isStorefront) {
                $safeNames = array_column(FunctionDefinitions::shopperSafe(), 'name');
                $functions = array_values(array_filter(
                    $functions,
                    fn (array $f) => in_array($f['name'] ?? '', $safeNames, true)
                ));
            }

            // Call the provider and resolve tool calls in a LOOP: models often
            // need several rounds (e.g. list → fetch detail → answer). The old
            // single-round path used the follow-up's empty content as the final
            // answer, which surfaced as the "How can I help you?" greeting.
            $response = $ollama->chat($messages, $functions);

            $assistantMessage = '';
            $executedTools = [];
            $maxToolRounds = 5;

            for ($round = 0; $round < $maxToolRounds; $round++) {
                $assistantMessage = (string) ($response['content'] ?? '');

                if (empty($response['function_call'])) {
                    break;
                }

                $functionCall = $response['function_call'];
                $functionName = $functionCall['function']['name'] ?? null;
                $arguments = json_decode($functionCall['function']['arguments'] ?? '{}', true) ?: [];

                if (! $functionName) {
                    break;
                }

                $result = $router->execute(
                    $functionName,
                    $arguments,
                    $conversation->customer_id,
                    $this->conversationId,
                    array_column($functions, 'name'),
                    $conversation->channel,
                );

                $executedTools[] = [
                    'name' => $functionName,
                    'arguments' => $arguments,
                    'success' => $result['success'] ?? null,
                ];

                // Tell AI about the function result
                $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => [$functionCall]];
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $functionCall['id'] ?? 'call_1',
                    'content' => json_encode($result),
                ];

                $response = $ollama->chat($messages, $functions);
            }

            // Clean up response
            $assistantMessage = $this->cleanResponse($assistantMessage, $executedTools);

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
                if (! tenancy()->initialized) {
                    try {
                        Tenancy::initialize($this->tenantId);
                    } catch (\Throwable) {
                        // Keep the current search_path — the fallback message
                        // still needs a chance to be written.
                    }
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

    protected function cleanResponse(string $text, array $executedTools = []): string
    {
        $text = preg_replace('/```json\s*\{[^}]*\}\s*```/', '', $text);
        $text = preg_replace('/\{"(name|arguments)":[^}]*\}/', '', $text);

        $text = trim($text);

        if ($text !== '') {
            return $text;
        }

        // Never answer with the greeting when we actually ran lookups: tell
        // the user the lookup ran but could not be summarised.
        if ($executedTools !== []) {
            $names = implode(', ', array_unique(array_column($executedTools, 'name')));

            return "I ran the lookup ({$names}) but couldn't put it into words. Try a more specific question, e.g. \"top 5 invoices by paid amount\".";
        }

        return 'How can I help you?';
    }
}
