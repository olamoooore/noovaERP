<?php

namespace Tests\Feature;

use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\AI\Services\OllamaService;
use Tests\TenantTestCase;

/**
 * AI settings (Settings → General → AI) and the local chat service.
 *
 * Every AI request goes to the local Ollama server, and the settings payload
 * exposes only local model fields.
 *
 * Verifies through the real router that:
 *  - the settings payload is Ollama-only (no `provider`/`cerebras` keys),
 *  - saving a model persists it and the chat service calls the local endpoint
 *    without any Authorization header,
 *  - only settings.view / settings.write holders (Owner/Admin) can read or
 *    change AI settings.
 */
class AiProviderSettingsTest extends TenantTestCase
{
    private string $tenantId;

    private User $owner;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'AI Provider Tenant',
            'email' => 'ai-provider@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);
        $this->mockFeatureGate(true);

        $this->owner = User::factory()->create();
        $this->sales = User::factory()->create();

        foreach ([[$this->owner, 'owner'], [$this->sales, 'sales']] as [$user, $role]) {
            DB::table('user_company_access')->insert([
                'user_id' => $user->id,
                'tenant_id' => $this->tenantId,
                'role' => $role,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')->andReturnUsing(fn (string $tenantId, string $key) => [
            'key' => $key,
            'is_entitled' => $isEntitled,
            'source' => $isEntitled ? 'tier_included' : null,
            'name' => strtoupper($key),
        ]);
        $mock->shouldReceive('all')->andReturn([]);
        $mock->shouldReceive('invalidate')->andReturnNull();

        app()->instance(FeatureGateService::class, $mock);
    }

    private function fakeOllama(): void
    {
        Http::fake([
            'localhost:11434/api/tags' => Http::response(['models' => [['name' => 'llama3.1:8b']]]),
            'localhost:11434/v1/chat/completions' => Http::response([
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'LOCAL', 'reasoning' => 'internal'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['total_tokens' => 7],
            ]),
        ]);
    }

    /** @test */
    public function owner_reads_ollama_only_settings(): void
    {
        $this->fakeOllama();

        $response = $this->actingAs($this->owner)
            ->getJson('/api/v1/ai/settings')
            ->assertOk();

        $response->assertJsonPath('data.ollama.model', config('ai.ollama.model'));
        $this->assertArrayHasKey('models', $response->json('data.ollama'));
        $this->assertArrayHasKey('health', $response->json('data'));

        // No provider switch and no key material in the payload.
        $this->assertArrayNotHasKey('cerebras', $response->json('data'));
        $this->assertArrayNotHasKey('provider', $response->json('data'));
        $this->assertArrayNotHasKey('provider', $response->json('data.health'));
    }

    /** @test */
    public function saving_a_model_keeps_all_chat_traffic_on_local_ollama(): void
    {
        $this->fakeOllama();

        $this->actingAs($this->owner)
            ->putJson('/api/v1/ai/settings', [
                'ollama_model' => 'llama3.1:8b',
            ])
            ->assertOk()
            ->assertJsonPath('data.model', 'llama3.1:8b');

        $service = app(OllamaService::class);
        $this->assertSame('ollama', $service->provider());
        $this->assertSame('llama3.1:8b', $service->model());

        $result = $service->chat([['role' => 'user', 'content' => 'ping']]);
        $this->assertSame('LOCAL', $result['content']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'localhost:11434/v1/chat/completions')
            && ! $request->hasHeader('Authorization'));

        // Legacy payload fields are inert: they cannot switch the provider.
        $this->actingAs($this->owner)
            ->putJson('/api/v1/ai/settings', [
                'provider' => 'cerebras',
                'cerebras_model' => 'qwen-3.8-27b',
                'cerebras_api_key' => 'csk-should-be-ignored',
            ])
            ->assertOk();

        $this->assertSame('ollama', app(OllamaService::class)->provider());
        $this->assertDatabaseMissing('settings', ['key' => 'ai.cerebras.api_key']);
    }

    /** @test */
    public function ai_settings_require_settings_permissions(): void
    {
        $this->fakeOllama();

        $this->actingAs($this->sales)->getJson('/api/v1/ai/settings')->assertForbidden();
        $this->actingAs($this->sales)
            ->putJson('/api/v1/ai/settings', ['ollama_model' => 'llama3.1:8b'])
            ->assertForbidden();
    }
}
