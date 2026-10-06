<?php

namespace Tests\Feature;

use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\AI\Models\AIConversation;
use Modules\AI\Models\AIMessage;
use Tests\TenantTestCase;

/**
 * Website AI assistant (Settings → General → AI → assistant identity,
 * /ai/stats dashboard metrics, and the public storefront guest chat).
 *
 * Verifies through the real router that:
 *  - assistant identity (name/icon/welcome) round-trips through the
 *    settings API and the public /storefront/assistant config,
 *  - availability is auto (AI + storefront entitlements) unless pinned,
 *    and a pinned-off assistant 403s guest chat while config stays readable,
 *  - /ai/stats reports tenant-wide conversation/message aggregates,
 *  - /ai/conversations lists the whole workspace when no customer_id is
 *    given, with message_count excluding null-content placeholders,
 *  - guests chat through visitor_id-scoped conversations (IDOR-guarded
 *    poll), replies run through the queued LLM pipeline with the tenant's
 *    provider selection, and shoppers never see finance quick-options.
 */
class AiAssistantStorefrontTest extends TenantTestCase
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
            'name' => 'AI Assistant Tenant',
            'email' => 'ai-assistant@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);
        $this->mockFeatureGate(true);

        // bindTenantContext fakes tenancy without running bootstrappers, so
        // the queue payload generator (registered by QueueTenancyBootstrapper's
        // constructor during a real initialize()) is missing — dispatched jobs
        // would carry no tenant_id and the sync queue would end tenancy around
        // job execution without restoring it (breaking every follow-up request
        // in the test). Constructing it here matches production dispatch after
        // middleware-initialized tenancy.
        $this->app->make(\Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class);

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
        // Most-specific pattern first: later generic patterns must not
        // shadow the chat endpoint.
        Http::fake([
            'localhost:11434/v1/chat/completions' => Http::response([
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'ORDERS-REPLY-OK'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['total_tokens' => 11],
            ]),
            'localhost:11434/*' => Http::response(['models' => [['name' => 'llama3.1:8b']]]),
        ]);
    }

    /** @test */
    public function assistant_identity_round_trips_through_settings_and_public_config(): void
    {
        $this->fakeOllama();

        // Auto mode out of the box: entitled to AI + storefront.
        $this->getJson('/api/v1/storefront/assistant')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('assistant.enabled', true)
            ->assertJsonPath('assistant.mode', 'auto')
            ->assertJsonPath('assistant.auto_eligible', true)
            ->assertJsonPath('assistant.name', 'NoovaERP AI Assistant');

        $this->actingAs($this->owner)
            ->putJson('/api/v1/ai/settings', [
                'provider' => 'ollama',
                'assistant_name' => 'Shop Helper',
                'assistant_icon' => 'https://cdn.example.com/bot.png',
                'assistant_welcome' => 'Welcome to NoovaERP Store!',
            ])
            ->assertOk()
            ->assertJsonPath('data.assistant.name', 'Shop Helper')
            ->assertJsonPath('data.assistant.icon', 'https://cdn.example.com/bot.png')
            ->assertJsonPath('data.assistant.welcome', 'Welcome to NoovaERP Store!')
            ->assertJsonPath('data.assistant.enabled', true);

        // Public config reflects the merchant's identity.
        $this->getJson('/api/v1/storefront/assistant')
            ->assertOk()
            ->assertJsonPath('assistant.name', 'Shop Helper')
            ->assertJsonPath('assistant.icon', 'https://cdn.example.com/bot.png')
            ->assertJsonPath('assistant.welcome', 'Welcome to NoovaERP Store!')
            ->assertJsonPath('assistant.enabled', true);
    }

    /** @test */
    public function pinned_off_assistant_keeps_config_readable_but_403s_guest_chat(): void
    {
        $this->fakeOllama();

        $this->actingAs($this->owner)
            ->putJson('/api/v1/ai/settings', [
                'provider' => 'ollama',
                'assistant_enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.assistant.enabled', false)
            ->assertJsonPath('data.assistant.mode', 'manual');

        // Config never 403s — the widget hides itself.
        $this->getJson('/api/v1/storefront/assistant')
            ->assertOk()
            ->assertJsonPath('assistant.enabled', false)
            ->assertJsonPath('assistant.mode', 'manual');

        // Guest chat is refused while pinned off.
        $this->postJson('/api/v1/storefront/chat', [
            'message' => 'Hello',
            'visitor_id' => 'visitor-disabled',
        ])->assertStatus(403)->assertJsonPath('success', false);

        // Clearing the override returns to auto (entitled → enabled).
        $this->actingAs($this->owner)
            ->putJson('/api/v1/ai/settings', [
                'provider' => 'ollama',
                'assistant_enabled' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.assistant.enabled', true)
            ->assertJsonPath('data.assistant.mode', 'auto');
    }

    /** @test */
    public function stats_report_tenant_wide_conversation_and_message_aggregates(): void
    {
        $activeA = AIConversation::create(['channel' => 'storefront', 'status' => 'active']);
        $activeB = AIConversation::create(['channel' => 'chat', 'status' => 'active']);
        $closed = AIConversation::create(['channel' => 'chat', 'status' => 'closed']);

        // activeA: two real messages today + one pending placeholder.
        AIMessage::create(['conversation_id' => $activeA->id, 'role' => 'user', 'content' => 'hi']);
        AIMessage::create(['conversation_id' => $activeA->id, 'role' => 'assistant', 'content' => 'hello']);
        AIMessage::create(['conversation_id' => $activeA->id, 'role' => 'assistant', 'content' => null]);

        // activeB: one message today + pending placeholder (excluded).
        AIMessage::create(['conversation_id' => $activeB->id, 'role' => 'user', 'content' => 'status?']);
        AIMessage::create(['conversation_id' => $activeB->id, 'role' => 'assistant', 'content' => null]);

        // closed: one message from yesterday (excluded) + one today.
        $yesterdayUser = AIMessage::create(['conversation_id' => $closed->id, 'role' => 'user', 'content' => 'old']);
        DB::table('ai_messages')->where('id', $yesterdayUser->id)
            ->update(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        AIMessage::create(['conversation_id' => $closed->id, 'role' => 'assistant', 'content' => 'fresh']);

        $this->actingAs($this->owner)
            ->getJson('/api/v1/ai/stats')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('stats.total_conversations', 3)
            ->assertJsonPath('stats.active_conversations', 2)
            ->assertJsonPath('stats.messages_today', 4);
    }

    /** @test */
    public function conversations_list_tenant_wide_with_non_empty_message_counts(): void
    {
        $storefront = AIConversation::create([
            'channel' => 'storefront',
            'external_id' => 'visitor-list',
            'status' => 'active',
        ]);
        $customer = \App\Models\Customer::factory()->create(['legal_entity_id' => null]);
        $staff = AIConversation::create(['customer_id' => $customer->id, 'channel' => 'chat', 'status' => 'active']);

        AIMessage::create(['conversation_id' => $storefront->id, 'role' => 'user', 'content' => 'question a']);
        $reply = AIMessage::create(['conversation_id' => $storefront->id, 'role' => 'assistant', 'content' => 'answer b']);
        // Deterministic last_message regardless of insert timestamp ties.
        DB::table('ai_messages')->where('id', $reply->id)
            ->update(['created_at' => now()->addMinute(), 'updated_at' => now()->addMinute()]);
        // Pending placeholder must not count towards message_count.
        AIMessage::create(['conversation_id' => $storefront->id, 'role' => 'assistant', 'content' => null]);

        AIMessage::create(['conversation_id' => $staff->id, 'role' => 'user', 'content' => 'staff q']);

        $response = $this->actingAs($this->owner)
            ->getJson('/api/v1/ai/conversations')
            ->assertOk()
            ->assertJsonPath('success', true);

        $conversations = collect($response->json('conversations'));
        $this->assertCount(2, $conversations, 'Tenant-wide listing must return every conversation');

        $sf = $conversations->firstWhere('id', $storefront->id);
        $this->assertNotNull($sf);
        $this->assertSame('storefront', $sf['channel']);
        $this->assertSame(2, $sf['message_count'], 'Null-content placeholders must not count');
        $this->assertSame('answer b', $sf['last_message']);

        $this->assertNotNull($conversations->firstWhere('id', $staff->id));
    }

    /** @test */
    public function guest_greeting_chat_is_visitor_scoped_and_idor_guarded(): void
    {
        $this->fakeOllama();

        $send = $this->postJson('/api/v1/storefront/chat', [
            'message' => 'Hello',
            'visitor_id' => 'visitor-a',
        ])->assertOk();

        $send->assertJsonPath('success', true)
            ->assertJsonPath('processing', false);

        $conversationId = (int) $send->json('conversation_id');
        $this->assertGreaterThan(0, $conversationId);
        $this->assertStringContainsString('How can I assist', (string) $send->json('message'));

        $conversation = AIConversation::find($conversationId);
        $this->assertNotNull($conversation);
        $this->assertSame('storefront', $conversation->channel);
        $this->assertSame('visitor-a', $conversation->external_id);
        $this->assertNull($conversation->customer_id, 'Guest conversations have no customer');

        // IDOR: another visitor cannot poll this conversation.
        $this->getJson("/api/v1/storefront/chat/{$conversationId}/poll?visitor_id=visitor-b")
            ->assertNotFound();

        // The owning visitor gets the reply + shopper-safe quick options.
        $poll = $this->getJson("/api/v1/storefront/chat/{$conversationId}/poll?visitor_id=visitor-a")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('processing', false);

        $this->assertStringContainsString('How can I assist', (string) $poll->json('message'));

        $quickOptions = $poll->json('quick_options');
        $this->assertIsArray($quickOptions);
        $this->assertNotEmpty($quickOptions, 'Module quick-options must be exposed to shoppers');
        foreach ($quickOptions as $option) {
            $this->assertDoesNotMatchRegularExpression(
                '/account|invoice|financial|journal|tax|payable|receivable|closing/i',
                (string) ($option['id'] ?? ''),
                'Finance/accounting modules must not surface in the shopper widget'
            );
        }
    }

    /** @test */
    public function guest_message_runs_the_llm_pipeline_and_poll_returns_the_reply(): void
    {
        $this->fakeOllama();

        $send = $this->postJson('/api/v1/storefront/chat', [
            'message' => 'What products do you sell?',
            'visitor_id' => 'visitor-b',
        ])->assertOk();

        $send->assertJsonPath('success', true)
            ->assertJsonPath('processing', true)
            ->assertJsonPath('message', null);

        $conversationId = (int) $send->json('conversation_id');
        $this->assertGreaterThan(0, $conversationId);

        // QUEUE_CONNECTION=sync: ProcessAIMessage already ran against the
        // tenant's provider (local Ollama endpoint by default) inside the
        // request — the poll just reads the completed reply.
        $poll = $this->getJson("/api/v1/storefront/chat/{$conversationId}/poll?visitor_id=visitor-b")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('processing', false);

        $this->assertSame('ORDERS-REPLY-OK', $poll->json('message'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'localhost:11434/v1/chat/completions'));
    }

    /** @test */
    public function guest_chat_payload_is_grounded_and_restricted_to_shopper_safe_tools(): void
    {
        $this->fakeOllama();

        $send = $this->postJson('/api/v1/storefront/chat', [
            'message' => 'How much does delivery cost?',
            'visitor_id' => 'visitor-rag',
        ])->assertOk();

        $conversationId = (int) $send->json('conversation_id');

        $poll = $this->getJson("/api/v1/storefront/chat/{$conversationId}/poll?visitor_id=visitor-rag")
            ->assertOk()
            ->assertJsonPath('processing', false);

        $this->assertSame('ORDERS-REPLY-OK', $poll->json('message'));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'localhost:11434/v1/chat/completions')) {
                return false;
            }

            $body = $request->data();
            $system = (string) ($body['messages'][0]['content'] ?? '');

            $toolNames = collect($body['tools'] ?? [])
                ->map(fn ($tool) => $tool['function']['name'] ?? null)
                ->filter()
                ->values()
                ->all();

            $shopperSafe = ['search_products', 'get_product_details', 'check_product_availability', 'list_product_categories'];

            return $system !== ''
                && $toolNames !== []
                && array_diff($toolNames, $shopperSafe) === [];
        });
    }

    /** @test */
    public function staff_can_open_a_storefront_guest_conversation(): void
    {
        $send = $this->postJson('/api/v1/storefront/chat', [
            'message' => 'Hello',
            'visitor_id' => 'visitor-history',
        ])->assertOk();

        $conversationId = (int) $send->json('conversation_id');

        // Previously 404: the controller dereferenced a null customer for
        // storefront conversations. Staff must be able to review them.
        $this->actingAs($this->owner)
            ->getJson("/api/v1/ai/conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('conversation.customer', null)
            ->assertJsonPath('conversation.channel', 'storefront');
    }

    /** @test */
    public function identity_questions_are_answered_instantly_without_the_llm(): void
    {
        // No Http::fake for the LLM — if the model were consulted the send
        // would attempt a real request and this test would fail loudly.
        Http::preventStrayRequests();
        $this->fakeOllama();

        $send = $this->postJson('/api/v1/storefront/chat', [
            'message' => 'what is your name?',
            'visitor_id' => 'visitor-identity',
        ])->assertOk();

        $this->assertSame(false, $send->json('processing'));
        $this->assertStringContainsString('NoovaERP AI Assistant', (string) $send->json('message'));

        // Provider question answered deterministically too.
        $provider = $this->postJson('/api/v1/storefront/chat', [
            'message' => 'which model are you?',
            'visitor_id' => 'visitor-identity',
        ])->assertOk();

        $this->assertSame(false, $provider->json('processing'));
        $this->assertStringContainsString('NoovaERP AI Assistant', (string) $provider->json('message'));
        $this->assertStringContainsString('locally', (string) $provider->json('message'));
    }
}
