<?php

namespace Modules\AI\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI chat service.
 *
 * Historically Ollama-only (the class name is kept so every consumer —
 * ChatbotService, ProcessAIMessage, TaxAdvisoryService, AIStatusController —
 * keeps working unchanged). It is now provider-aware:
 *
 *   - ollama   → local inference server (OpenAI-compatible /v1)
 *   - cerebras → Cerebras cloud (OpenAI-compatible, BYOK)
 *
 * The active provider/model/key resolve from tenant settings first
 * (Settings → General → AI), then from config/ai.php / environment.
 * Both providers speak the same OpenAI chat-completions shape, so callers
 * are oblivious to which one is active.
 */
class OllamaService
{
    /**
     * Resolve the active provider configuration at CALL time (never in the
     * constructor): Laravel instantiates controllers while gathering route
     * middleware, before the tenancy middleware has switched the search_path —
     * reading settings there would silently fall back to public-schema
     * defaults (confirmed: /ai/info reported ollama while the tenant was on
     * cerebras).
     *
     * @return array{provider: string, base_url: string, model: string, temperature: float, top_p: float, max_tokens: int, timeout: int}
     */
    private function resolve(): array
    {
        $provider = (string) ($this->setting('ai.provider') ?: config('ai.provider', 'ollama'));
        $provider = in_array($provider, ['ollama', 'cerebras'], true) ? $provider : 'ollama';

        if ($provider === 'cerebras') {
            return [
                'provider' => $provider,
                'base_url' => (string) config('ai.cerebras.base_url'),
                'model' => (string) ($this->setting('ai.cerebras.model') ?: config('ai.cerebras.model')),
                'temperature' => (float) config('ai.cerebras.temperature'),
                'top_p' => (float) config('ai.cerebras.top_p', 0.9),
                'max_tokens' => (int) config('ai.cerebras.max_tokens'),
                'timeout' => (int) config('ai.cerebras.timeout'),
            ];
        }

        return [
            'provider' => $provider,
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

    /** Active provider name (`ollama` | `cerebras`). */
    public function provider(): string
    {
        return $this->resolve()['provider'];
    }

    /** Active model id for the selected provider. */
    public function model(): string
    {
        return $this->resolve()['model'];
    }

    /** The Cerebras key that will be used: tenant BYOK first, default second. */
    public function cerebrasKey(): ?string
    {
        $tenantKey = (string) ($this->setting('ai.cerebras.api_key') ?: '');

        return $tenantKey !== '' ? $tenantKey : (config('ai.cerebras.api_key') ?: null);
    }

    public function usesDefaultCerebrasKey(): bool
    {
        return ((string) ($this->setting('ai.cerebras.api_key') ?: '')) === ''
            && (bool) config('ai.cerebras.api_key');
    }

    /**
     * Send chat request to the active provider.
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

        $request = Http::timeout($cfg['timeout'])->connectTimeout(30);

        if ($cfg['provider'] === 'cerebras') {
            $key = $this->cerebrasKey();
            if (! $key) {
                throw new \Exception('Cerebras API key is not configured. Add your key under Settings → General → AI.');
            }
            $request = $request->withToken($key);
        }

        try {
            $response = $request->post($cfg['base_url'].'/chat/completions', $payload);

            if (! $response->successful()) {
                Log::error('AI provider request failed', [
                    'provider' => $cfg['provider'],
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);
                throw new \Exception(ucfirst($cfg['provider']).' API failed: '.$response->body());
            }

            $data = $response->json();

            // Reasoning models (qwen-3.8-27b) may return a `reasoning` field;
            // the visible answer is still `content`.
            $message = $data['choices'][0]['message'] ?? [];

            return [
                'content' => $message['content'] ?? null,
                'reasoning' => $message['reasoning'] ?? null,
                'function_call' => $message['tool_calls'][0] ?? null,
                'tokens' => $data['usage']['total_tokens'] ?? 0,
                'cost' => 0,
            ];

        } catch (\Exception $e) {
            Log::error('AI provider error', ['provider' => $this->provider(), 'error' => $e->getMessage()]);
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
     * Check if the active provider is reachable.
     */
    public function healthCheck(): bool
    {
        $cfg = $this->resolve();

        try {
            if ($cfg['provider'] === 'cerebras') {
                $key = $this->cerebrasKey();
                if (! $key) {
                    return false;
                }

                return Http::timeout(10)->withToken($key)->get($cfg['base_url'].'/models')->successful();
            }

            $baseUrl = str_replace('/v1', '', $cfg['base_url']);

            return Http::timeout(5)->get($baseUrl.'/api/tags')->successful();
        } catch (\Exception $e) {
            Log::warning('AI health check failed', ['provider' => $cfg['provider'], 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Get available models for the active provider.
     */
    public function listModels(): array
    {
        $cfg = $this->resolve();

        try {
            if ($cfg['provider'] === 'cerebras') {
                $key = $this->cerebrasKey();
                if (! $key) {
                    return config('ai.known_models.cerebras', []);
                }

                $response = Http::timeout(10)->withToken($key)->get($cfg['base_url'].'/models');
                if (! $response->successful()) {
                    return config('ai.known_models.cerebras', []);
                }

                return collect($response->json('data') ?? [])->pluck('id')->filter()->values()->all();
            }

            $baseUrl = str_replace('/v1', '', $cfg['base_url']);
            $response = Http::timeout(5)->get($baseUrl.'/api/tags');

            if (! $response->successful()) {
                return [];
            }

            return array_map(fn ($model) => $model['name'], $response->json('models') ?? []);
        } catch (\Exception $e) {
            Log::error('Failed to list models', ['provider' => $cfg['provider'] ?? null, 'error' => $e->getMessage()]);

            return (array) config('ai.known_models.'.$this->provider(), []);
        }
    }
}
