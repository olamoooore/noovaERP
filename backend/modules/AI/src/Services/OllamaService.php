<?php

namespace Modules\AI\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ollama Service
 * 
 * Handles communication with local Ollama LLM inference engine
 */
class OllamaService
{
    protected string $baseUrl;
    protected string $model;
    protected float $temperature;
    protected float $topP;
    protected int $maxTokens;
    protected int $timeout;
    
    public function __construct()
    {
        $this->baseUrl = config('ai.ollama.base_url');
        $this->model = config('ai.ollama.model');
        $this->temperature = config('ai.ollama.temperature');
        $this->topP = config('ai.ollama.top_p', 0.9);
        $this->maxTokens = config('ai.ollama.max_tokens');
        $this->timeout = config('ai.ollama.timeout');
    }
    
    /**
     * Send chat request to Ollama
     * 
     * @param array $messages Array of message objects
     * @param array $functions Optional function definitions
     * @return array Response with content, function_call, tokens, cost
     * @throws \Exception
     */
    public function chat(array $messages, array $functions = []): array
    {

        set_time_limit(180); // add this line here

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'max_tokens' => $this->maxTokens,
        ];
        
        if (!empty($functions)) {
            $payload['tools'] = $this->formatFunctions($functions);
        }

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout(30)
                ->withToken(env('GROQ_API_KEY'))
                ->post($this->baseUrl . '/chat/completions', $payload);
            
            if (!$response->successful()) {
                Log::error('Ollama API failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('Ollama API failed: ' . $response->body());
            }
            
            $data = $response->json();
            
            return [
                'content' => $data['choices'][0]['message']['content'] ?? null,
                'function_call' => $data['choices'][0]['message']['tool_calls'][0] ?? null,
                'tokens' => $data['usage']['total_tokens'] ?? 0,
                'cost' => 0,
            ];
            
        } catch (\Exception $e) {
            Log::error('Ollama error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
    
    /**
     * Format functions to Ollama tool format
     */
    protected function formatFunctions(array $functions): array
    {
        return array_map(fn($func) => [
            'type' => 'function',
            'function' => $func,
        ], $functions);
    }
    
    /**
     * Check if Ollama service is running
     */
    public function healthCheck(): bool
    {
        try {
            $baseUrl = str_replace('/v1', '', $this->baseUrl);
            $response = Http::timeout(5)->get($baseUrl . '/api/tags');
            return $response->successful();
        } catch (\Exception $e) {
            Log::warning('Ollama health check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Get available models
     */
    public function listModels(): array
    {
        try {
            $baseUrl = str_replace('/v1', '', $this->baseUrl);
            $response = Http::timeout(5)->get($baseUrl . '/api/tags');
            
            if (!$response->successful()) {
                return [];
            }
            
            $data = $response->json();
            return array_map(fn($model) => $model['name'], $data['models'] ?? []);
        } catch (\Exception $e) {
            Log::error('Failed to list models', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
