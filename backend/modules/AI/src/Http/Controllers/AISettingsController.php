<?php

namespace Modules\AI\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\AI\Services\AssistantConfig;
use Modules\AI\Services\OllamaService;

/**
 * AI provider settings (Settings → General → AI).
 *
 * Supports BYOK: tenants may keep the workspace-default Cerebras key or
 * supply their own. Reads require settings.view, writes settings.write
 * (Owner/Admin) — enforced on the routes.
 */
class AISettingsController extends Controller
{
    public function show(OllamaService $ai): JsonResponse
    {
        $tenantKey = (string) (Setting::getValue('ai.cerebras.api_key') ?: '');
        $settings = Setting::getValue('ai.cerebras.model');

        return response()->json([
            'data' => [
                'provider' => $ai->provider(),
                'ollama' => [
                    'model' => (string) (Setting::getValue('ai.ollama.model') ?: config('ai.ollama.model')),
                    'models' => $ai->provider() === 'ollama' ? $ai->listModels() : array_values(array_unique(array_merge(
                        (array) config('ai.known_models.ollama', []),
                        [],
                    ))),
                ],
                'cerebras' => [
                    'model' => (string) ($settings ?: config('ai.cerebras.model')),
                    'models' => $ai->provider() === 'cerebras'
                        ? $ai->listModels()
                        : (array) config('ai.known_models.cerebras', []),
                    'has_custom_key' => $tenantKey !== '',
                    'using_default_key' => $ai->usesDefaultCerebrasKey(),
                    'key_hint' => $tenantKey !== '' ? substr($tenantKey, 0, 7).'…'.substr($tenantKey, -4) : null,
                ],
                'assistant' => app(AssistantConfig::class)->toArray(),
                'health' => [
                    'ok' => $ai->healthCheck(),
                    'provider' => $ai->provider(),
                    'model' => $ai->model(),
                ],
            ],
        ]);
    }

    public function update(Request $request, OllamaService $ai): JsonResponse
    {
        $validated = $request->validate([
            'provider' => 'required|in:ollama,cerebras',
            'ollama_model' => 'nullable|string|max:120',
            'cerebras_model' => 'nullable|string|max:120',
            'cerebras_api_key' => 'nullable|string|max:255',
            'clear_cerebras_key' => 'nullable|boolean',
            'assistant_enabled' => 'nullable|boolean',
            'assistant_name' => 'nullable|string|max:60',
            'assistant_icon' => 'nullable|string|max:512',
            'assistant_welcome' => 'nullable|string|max:300',
        ]);

        Setting::setValue('ai.provider', $validated['provider']);

        if (! empty($validated['ollama_model'])) {
            Setting::setValue('ai.ollama.model', $validated['ollama_model']);
        }

        if (! empty($validated['cerebras_model'])) {
            Setting::setValue('ai.cerebras.model', $validated['cerebras_model']);
        }

        if ($request->boolean('clear_cerebras_key')) {
            Setting::setValue('ai.cerebras.api_key', null);
        } elseif (! empty($validated['cerebras_api_key'])) {
            Setting::setValue('ai.cerebras.api_key', trim($validated['cerebras_api_key']));
        }

        // Website assistant identity. `assistant_enabled: null` clears the
        // override and returns the assistant to auto (module-entitlement
        // driven); true/false pins it on/off explicitly.
        if ($request->has('assistant_enabled')) {
            $enabled = $request->input('assistant_enabled');
            Setting::setValue('ai.assistant.enabled', is_bool($enabled) ? $enabled : null);
        }
        if (array_key_exists('assistant_name', $validated)) {
            Setting::setValue('ai.assistant.name', trim((string) $validated['assistant_name']) ?: null);
        }
        if (array_key_exists('assistant_icon', $validated)) {
            Setting::setValue('ai.assistant.icon', trim((string) $validated['assistant_icon']) ?: null);
        }
        if (array_key_exists('assistant_welcome', $validated)) {
            Setting::setValue('ai.assistant.welcome', trim((string) $validated['assistant_welcome']) ?: null);
        }

        // Fresh instance so the response reflects the saved state.
        $fresh = app(OllamaService::class);

        return response()->json([
            'message' => 'AI settings saved',
            'data' => [
                'provider' => $fresh->provider(),
                'model' => $fresh->model(),
                'assistant' => app(AssistantConfig::class)->toArray(),
                'health' => $fresh->healthCheck(),
            ],
        ]);
    }

    /**
     * Test the given provider/model/key without saving.
     */
    public function test(Request $request, OllamaService $ai): JsonResponse
    {
        $validated = $request->validate([
            'provider' => 'required|in:ollama,cerebras',
            'model' => 'nullable|string|max:120',
            'cerebras_api_key' => 'nullable|string|max:255',
        ]);

        $provider = $validated['provider'];
        $key = trim((string) ($validated['cerebras_api_key'] ?? '')) ?: $ai->cerebrasKey();
        $model = $validated['model']
            ?: ($provider === 'cerebras'
                ? (Setting::getValue('ai.cerebras.model') ?: config('ai.cerebras.model'))
                : (Setting::getValue('ai.ollama.model') ?: config('ai.ollama.model')));

        if ($provider === 'ollama') {
            $baseUrl = config('ai.ollama.base_url');
            $root = str_replace('/v1', '', $baseUrl);
            $ok = \Illuminate\Support\Facades\Http::timeout(5)->get($root.'/api/tags')->successful();

            return response()->json(['data' => ['ok' => $ok, 'provider' => 'ollama', 'model' => $model]]);
        }

        if (! $key) {
            return response()->json(['data' => ['ok' => false, 'provider' => 'cerebras', 'model' => $model, 'error' => 'No API key available']], 422);
        }

        $response = \Illuminate\Support\Facades\Http::timeout(30)
            ->withToken($key)
            ->post(config('ai.cerebras.base_url').'/chat/completions', [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => 'Reply with exactly: PONG']],
                'max_tokens' => 400,
                'temperature' => 0.2,
            ]);

        $content = $response->successful() ? $response->json('choices.0.message.content') : null;

        return response()->json([
            'data' => [
                'ok' => $response->successful() && $content !== null,
                'provider' => 'cerebras',
                'model' => $model,
                'sample' => $content,
                'error' => $response->successful() ? null : substr($response->body(), 0, 300),
            ],
        ], $response->successful() ? 200 : 422);
    }

    /**
     * POST /ai/settings/assistant-icon — upload the website assistant's
     * avatar (jpeg/png/jpg/gif/webp, max 5 MB). Stores on the public disk
     * and persists the absolute URL, replacing any previous uploaded file.
     */
    public function uploadAssistantIcon(Request $request): JsonResponse
    {
        $request->validate([
            'icon' => ['required', 'file', 'mimes:jpeg,jpg,png,gif,webp', 'max:5120'],
        ]);

        $file = $request->file('icon');
        $extension = strtolower($file->getClientOriginalExtension() ?: 'png');
        $path = $file->storeAs('ai-assistant', Str::random(40).'.'.$extension, 'public');

        // Remove the previous locally-stored upload (external URLs and
        // emoji values are left untouched).
        $previousPath = $this->localStoragePath((string) (Setting::getValue('ai.assistant.icon') ?? ''));
        if ($previousPath && $previousPath !== $path && Storage::disk('public')->exists($previousPath)) {
            Storage::disk('public')->delete($previousPath);
        }

        $url = url(Storage::disk('public')->url($path));
        Setting::setValue('ai.assistant.icon', $url);

        return response()->json([
            'success' => true,
            'icon_url' => $url,
            'assistant' => app(AssistantConfig::class)->toArray(),
        ]);
    }

    /**
     * Map a stored /storage/... URL back to its path on the public disk.
     */
    private function localStoragePath(string $url): ?string
    {
        if ($url === '' || ! str_contains($url, '/storage/')) {
            return null;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $offset = strpos($path, '/storage/');

        if ($offset === false) {
            return null;
        }

        $relative = ltrim(substr($path, $offset + strlen('/storage/')), '/');

        return $relative !== '' ? $relative : null;
    }
}
