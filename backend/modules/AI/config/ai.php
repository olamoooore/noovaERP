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
        'timeout' => (int) env('LLM_TIMEOUT', 60),
        'top_p' => (float) env('LLM_TOP_P', 0.9), // Nucleus sampling
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
