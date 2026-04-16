<?php

namespace Modules\AI\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Modules\AI\Services\OllamaService;

/**
 * AI Status Controller
 * 
 * Handles AI service status and health checks
 */
class AIStatusController extends Controller
{
    protected OllamaService $ollamaService;
    
    public function __construct(OllamaService $ollamaService)
    {
        $this->ollamaService = $ollamaService;
    }
    
    /**
     * Check AI service health
     * 
     * @return JsonResponse
     */
    public function health(): JsonResponse
    {
        $isHealthy = $this->ollamaService->healthCheck();
        
        return response()->json([
            'success' => true,
            'status' => $isHealthy ? 'healthy' : 'unhealthy',
            'service' => 'ollama',
            'timestamp' => now()->toIso8601String(),
        ], $isHealthy ? 200 : 503);
    }
    
    /**
     * Get AI service information
     * 
     * @return JsonResponse
     */
    public function info(): JsonResponse
    {
        $cached = Cache::remember('ai_service_info', 60, function () {
            $models = $this->ollamaService->listModels();
            $isHealthy = $this->ollamaService->healthCheck();

            return [
                'service' => [
                    'provider' => config('ai.provider'),
                    'status' => $isHealthy ? 'running' : 'stopped',
                    'base_url' => config('ai.ollama.base_url'),
                    'current_model' => config('ai.ollama.model'),
                    'available_models' => $models,
                ],
                'features' => config('ai.features'),
                'conversation' => config('ai.conversation'),
            ];
        });

        return response()->json(array_merge(['success' => true], $cached));
    }
}
