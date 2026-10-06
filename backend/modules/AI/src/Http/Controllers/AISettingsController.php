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
 * AI settings (Settings → General → AI).
 *
 * Local Ollama only. Reads require settings.view, writes settings.write
 * (Owner/Admin) — enforced on the routes.
 */
class AISettingsController extends Controller
{
    public function show(OllamaService $ai): JsonResponse
    {
        return response()->json([
            'data' => [
                'ollama' => [
                    'model' => (string) (Setting::getValue('ai.ollama.model') ?: config('ai.ollama.model')),
                    'models' => $ai->listModels(),
                ],
                'assistant' => app(AssistantConfig::class)->toArray(),
                'health' => [
                    'ok' => $ai->healthCheck(),
                    'model' => $ai->model(),
                ],
            ],
        ]);
    }

    public function update(Request $request, OllamaService $ai): JsonResponse
    {
        $validated = $request->validate([
            'ollama_model' => 'nullable|string|max:120',
            'assistant_enabled' => 'nullable|boolean',
            'assistant_name' => 'nullable|string|max:60',
            'assistant_icon' => 'nullable|string|max:512',
            'assistant_welcome' => 'nullable|string|max:300',
        ]);

        if (! empty($validated['ollama_model'])) {
            Setting::setValue('ai.ollama.model', $validated['ollama_model']);
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
                'model' => $fresh->model(),
                'assistant' => app(AssistantConfig::class)->toArray(),
                'health' => [
                    'ok' => $fresh->healthCheck(),
                    'model' => $fresh->model(),
                ],
            ],
        ]);
    }

    /**
     * Test the local model/server without saving.
     */
    public function test(Request $request, OllamaService $ai): JsonResponse
    {
        $validated = $request->validate([
            'model' => 'nullable|string|max:120',
        ]);

        $model = $validated['model']
            ?: (Setting::getValue('ai.ollama.model') ?: config('ai.ollama.model'));

        $root = str_replace('/v1', '', (string) config('ai.ollama.base_url'));
        $ok = \Illuminate\Support\Facades\Http::timeout(5)->get($root.'/api/tags')->successful();

        return response()->json([
            'data' => [
                'ok' => $ok,
                'provider' => 'ollama',
                'model' => $model,
                'error' => $ok ? null : 'Ollama is not reachable',
            ],
        ]);
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
