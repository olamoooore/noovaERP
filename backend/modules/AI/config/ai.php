<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Provider Configuration
    |--------------------------------------------------------------------------
    */
    'provider' => env('AI_PROVIDER', 'ollama'),

    /*
    |--------------------------------------------------------------------------
    | Ollama Configuration (Local Deployment)
    |--------------------------------------------------------------------------
    */
    'ollama' => [
        'base_url' => env('OLLAMA_URL', 'http://localhost:11434/v1'),
        'model' => env('LLM_MODEL', 'llama3.1:8b'),
        'temperature' => (float) env('LLM_TEMPERATURE', 0.2), // Low temp for structured tasks (0.1-0.3)
        'max_tokens' => (int) env('LLM_MAX_TOKENS', 500),
        'timeout' => (int) env('LLM_TIMEOUT', 120),
        'top_p' => (float) env('LLM_TOP_P', 0.9), // Nucleus sampling
    ],

    /*
    |--------------------------------------------------------------------------
    | Cerebras Configuration (Cloud — BYOK: bring your own key)
    |--------------------------------------------------------------------------
    | The tenant can override the key/model under Settings → General → AI.
    | CEREBRAS_API_KEY acts as the workspace default when a tenant has not
    | supplied its own key.
    */
    'cerebras' => [
        'base_url' => env('CEREBRAS_URL', 'https://api.cerebras.ai/v1'),
        'model' => env('CEREBRAS_MODEL', 'qwen-3.8-27b'),
        'api_key' => env('CEREBRAS_API_KEY'),
        'temperature' => (float) env('CEREBRAS_TEMPERATURE', 0.2),
        // Reasoning models (qwen-3.8-27b) spend tokens on hidden reasoning
        // before the visible answer — keep a generous budget.
        'max_tokens' => (int) env('CEREBRAS_MAX_TOKENS', 2048),
        'timeout' => (int) env('CEREBRAS_TIMEOUT', 90),
        'top_p' => (float) env('CEREBRAS_TOP_P', 0.9),
    ],

    /** Fallback model pickers when the live /v1/models list is unavailable. */
    'known_models' => [
        'ollama' => [],
        'cerebras' => ['qwen-3.8-27b', 'gpt-oss-120b'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    */
    'features' => [
        'function_calling' => true,
        'streaming' => false,
        'multi_language' => true,
        'knowledge_base' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversation Settings
    |--------------------------------------------------------------------------
    */
    'conversation' => [
        'max_history' => 10,
        'auto_close_after' => 24, // hours
        'default_language' => 'en',
        'supported_languages' => ['en', 'zh', 'ms'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    */
    'channels' => [
        'web' => ['enabled' => true],
        'whatsapp' => ['enabled' => false],
    ],
];
