<?php

namespace Modules\AI\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI chat service — local Ollama only.
 *
 * The class name is kept so every consumer (ChatbotService, ProcessAIMessage,
 * TaxAdvisoryService, AIStatusController) keeps working unchanged. Requests
 * go to the local Ollama server (OpenAI-compatible /v1); the tenant model
 * resolves from tenant settings first (Settings → General → AI), then from
 * config/ai.php / environment.
 *
 * All AI traffic runs on the local inference server.
 */
class OllamaService
{
    /**
     * Resolve the model configuration at CALL time (never in the constructor):
     * Laravel instantiates controllers while gathering route middleware,
     * before the tenancy middleware has switched the search_path — reading
     * settings there would silently fall back to public-schema defaults.
     *
     * @return array{provider: string, base_url: string, model: string, temperature: float, top_p: float, max_tokens: int, timeout: int}
     */
    private function resolve(): array
    {
        return [
            'provider' => 'ollama',
            'base_url' => (string) config('ai.ollama.base_url'),
            'model' => (string) ($this->setting('ai.ollama.model') ?: config('ai.ollama.model')),
            'temperature' => (float) config('ai.ollama.temperature'),
            'top_p' => (float) config('ai.ollama.top_p', 0.9),
            'max_tokens' => (int) config('ai.ollama.max_tokens'),
            'timeout' => (int) config('ai.ollama.timeout'),
        ];
    }

    /**
     * Tenant-scoped setting read that never explodes outside a tenant context
     * (central commands, migrations, queues before tenancy boots).
     */
    private function setting(string $key, mixed $default = null): mixed
    {
        try {
            return Setting::getValue($key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /** Active inference engine — always the local Ollama server. */
    public function provider(): string
    {
        return 'ollama';
    }

    /** Active model id. */
    public function model(): string
    {
        return $this->resolve()['model'];
    }

    /**
     * Send chat request to the local Ollama server.
     *
     * @param  array  $messages  Array of message objects
     * @param  array  $functions  Optional function definitions
     * @return array Response with content, function_call, tokens, cost
     *
     * @throws \Exception
     */
    public function chat(array $messages, array $functions = []): array
    {
        set_time_limit(180);

        $cfg = $this->resolve();

        $payload = [
            'model' => $cfg['model'],
            'messages' => $messages,
            'temperature' => $cfg['temperature'],
            'top_p' => $cfg['top_p'],
            'max_tokens' => $cfg['max_tokens'],
        ];

        if (! empty($functions)) {
            $payload['tools'] = $this->formatFunctions($functions);
        }

        try {
            $response = Http::timeout($cfg['timeout'])->connectTimeout(30)
                ->post($cfg['base_url'].'/chat/completions', $payload);

            if (! $response->successful()) {
                Log::error('Ollama request failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);
                throw new \Exception('Ollama API failed: '.$response->body());
            }

            $data = $response->json();

            // Some reasoning-tuned models return a `reasoning` field; the
            // visible answer is still `content`.
            $message = $data['choices'][0]['message'] ?? [];

            return [
                'content' => $message['content'] ?? null,
                'reasoning' => $message['reasoning'] ?? null,
                'function_call' => $message['tool_calls'][0] ?? null,
                'tokens' => $data['usage']['total_tokens'] ?? 0,
                'cost' => 0,
            ];

        } catch (\Exception $e) {
            Log::error('Ollama error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Format functions to OpenAI-compatible tool format.
     */
    protected function formatFunctions(array $functions): array
    {
        return array_map(fn ($func) => [
            'type' => 'function',
            'function' => $func,
        ], $functions);
    }

    /**
     * Check if the local Ollama server is reachable.
     */
    public function healthCheck(): bool
    {
        $cfg = $this->resolve();

        try {
            $baseUrl = str_replace('/v1', '', $cfg['base_url']);

            return Http::timeout(5)->get($baseUrl.'/api/tags')->successful();
        } catch (\Exception $e) {
            Log::warning('AI health check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Get the models available on the local Ollama server.
     */
    public function listModels(): array
    {
        $cfg = $this->resolve();

        try {
            $baseUrl = str_replace('/v1', '', $cfg['base_url']);
            $response = Http::timeout(5)->get($baseUrl.'/api/tags');

            if (! $response->successful()) {
                return [];
            }

            return array_map(fn ($model) => $model['name'], $response->json('models') ?? []);
        } catch (\Exception $e) {
            Log::error('Failed to list Ollama models', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
