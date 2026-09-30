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
 * AI provider settings + BYOK (Settings → General → AI) and the
 * provider-aware chat service.
 *
 * Verifies through the real router that:
 *  - the workspace defaults to local Ollama,
 *  - switching to Cerebras persists the provider/model/key and the chat
 *    service then calls the Cerebras OpenAI-compatible endpoint with the
 *    tenant's own key (BYOK), reasoning-model aware,
 *  - switching back posts to the local Ollama endpoint again,
 *  - only settings.view / settings.write holders (Owner/Admin) can read or
 *    change provider settings.
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

    private function fakeProviders(): void
    {
        Http::fake([
            'localhost:11434/*' => Http::response(['models' => [['name' => 'llama3.1:8b']]]),
            'api.cerebras.ai/*' => Http::response([
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'PONG', 'reasoning' => 'internal'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['total_tokens' => 42],
            ]),
        ]);
    }

    /** @test */
    public function owner_reads_provider_settings_and_cerebras_key_status(): void
    {
        $this->fakeProviders();

        $response = $this->actingAs($this->owner)
            ->getJson('/api/v1/ai/settings')
            ->assertOk();

        $response->assertJsonPath('data.provider', 'ollama');
        $response->assertJsonPath('data.cerebras.model', 'qwen-3.8-27b');
        // The workspace default key (env) is present; no tenant key stored yet.
        $this->assertTrue($response->json('data.cerebras.using_default_key'));
        $this->assertFalse($response->json('data.cerebras.has_custom_key'));
        $this->assertNull($response->json('data.cerebras.key_hint'));
    }

    /** @test */
    public function switching_to_cerebras_persists_and_chat_uses_byok(): void
    {
        $this->fakeProviders();

        $this->actingAs($this->owner)
            ->putJson('/api/v1/ai/settings', [
                'provider' => 'cerebras',
                'cerebras_model' => 'qwen-3.8-27b',
                'cerebras_api_key' => 'csk-tenant-own-key',
            ])
            ->assertOk()
            ->assertJsonPath('data.provider', 'cerebras')
            ->assertJsonPath('data.model', 'qwen-3.8-27b');

        // Fresh service resolves the tenant selection.
        $service = app(OllamaService::class);
        $this->assertSame('cerebras', $service->provider());
        $this->assertSame('qwen-3.8-27b', $service->model());
        $this->assertSame('csk-tenant-own-key', $service->cerebrasKey());

        $result = $service->chat([['role' => 'user', 'content' => 'ping']]);
        $this->assertSame('PONG', $result['content']);
        $this->assertSame('internal', $result['reasoning']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.cerebras.ai/v1/chat/completions')
                && $request->hasHeader('Authorization', 'Bearer csk-tenant-own-key')
                && $request['model'] === 'qwen-3.8-27b';
        });

        // Settings now expose the tenant key as a masked hint only.
        $this->actingAs($this->owner)
            ->getJson('/api/v1/ai/settings')
            ->assertOk()
            ->assertJsonPath('data.cerebras.has_custom_key', true)
            ->assertJsonPath('data.cerebras.using_default_key', false);
        $this->assertStringStartsWith('csk-ten', (string) $this->actingAs($this->owner)->getJson('/api/v1/ai/settings')->json('data.cerebras.key_hint'));
    }

    /** @test */
    public function switching_back_to_ollama_uses_the_local_endpoint_again(): void
    {
        Http::fake([
            'localhost:11434/api/tags' => Http::response(['models' => [['name' => 'llama3.1:8b']]]),
            'localhost:11434/v1/chat/completions' => Http::response([
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'LOCAL'], 'finish_reason' => 'stop']],
                'usage' => ['total_tokens' => 7],
            ]),
        ]);

        $this->actingAs($this->owner)
            ->putJson('/api/v1/ai/settings', ['provider' => 'ollama', 'ollama_model' => 'llama3.1:8b'])
            ->assertOk()
            ->assertJsonPath('data.provider', 'ollama');

        $service = app(OllamaService::class);
        $this->assertSame('ollama', $service->provider());
        $this->assertSame('llama3.1:8b', $service->model());

        $result = $service->chat([['role' => 'user', 'content' => 'ping']]);
        $this->assertSame('LOCAL', $result['content']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'localhost:11434/v1/chat/completions')
            && ! $request->hasHeader('Authorization'));
    }

    /** @test */
    public function provider_settings_require_settings_permissions(): void
    {
        $this->fakeProviders();

        $this->actingAs($this->sales)->getJson('/api/v1/ai/settings')->assertForbidden();
        $this->actingAs($this->sales)
            ->putJson('/api/v1/ai/settings', ['provider' => 'cerebras'])
            ->assertForbidden();
    }
}
