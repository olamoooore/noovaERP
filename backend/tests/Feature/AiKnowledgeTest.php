<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\AI\Data\PlatformKnowledge;
use Modules\AI\Data\StarterKnowledge;
use Modules\AI\Functions\ProductFunctions;
use Modules\AI\Models\AIConversation;
use Modules\AI\Models\AIKnowledgeBase;
use Modules\AI\Services\AssistantContextService;
use Modules\AI\Services\KnowledgeIngestionService;
use Modules\AI\Services\KnowledgeRetriever;
use Tests\TenantTestCase;

/**
 * AI knowledge base (RAG): ingestion, retrieval (Postgres FTS + keyword
 * fallback), the assistant context builder, the knowledge API and the
 * assistant icon upload.
 */
class AiKnowledgeTest extends TenantTestCase
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
            'name' => 'AI Knowledge Tenant',
            'email' => 'ai-knowledge@example.com',
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

    /** @test */
    public function knowledge_crud_roundtrip_with_permissions(): void
    {
        $this->actingAs($this->owner)
            ->getJson('/api/v1/ai/knowledge')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $created = $this->actingAs($this->owner)
            ->postJson('/api/v1/ai/knowledge', [
                'category' => 'shipping',
                'question' => 'Do you deliver on weekends?',
                'answer' => 'Weekend delivery is available on request via the 1:1 query page.',
                'keywords' => ['weekend delivery'],
                'priority' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('document.category', 'shipping');

        $id = (int) $created->json('document.id');

        $this->actingAs($this->owner)
            ->getJson('/api/v1/ai/knowledge')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('documents.0.question', 'Do you deliver on weekends?');

        $this->actingAs($this->owner)
            ->putJson("/api/v1/ai/knowledge/{$id}", [
                'category' => 'shipping',
                'question' => 'Do you deliver on weekends?',
                'answer' => 'Weekend delivery is available on request; a small surcharge applies.',
            ])
            ->assertOk()
            ->assertJsonPath('document.answer', 'Weekend delivery is available on request; a small surcharge applies.');

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/ai/knowledge/{$id}")
            ->assertOk();

        $this->assertSame(0, AIKnowledgeBase::count());

        // Sales holds neither settings.view nor settings.write.
        $this->actingAs($this->sales)->getJson('/api/v1/ai/knowledge')->assertForbidden();
        $this->actingAs($this->sales)
            ->postJson('/api/v1/ai/knowledge', [
                'category' => 'faq',
                'question' => 'x',
                'answer' => 'y',
            ])
            ->assertForbidden();
    }

    /** @test */
    public function import_defaults_is_idempotent_and_seeds_the_starter_pack(): void
    {
        $expected = count(StarterKnowledge::all()) + count(PlatformKnowledge::all());

        $this->actingAs($this->owner)
            ->postJson('/api/v1/ai/knowledge/import-defaults')
            ->assertOk()
            ->assertJsonPath('imported', $expected)
            ->assertJsonPath('total', $expected);

        $this->actingAs($this->owner)
            ->postJson('/api/v1/ai/knowledge/import-defaults')
            ->assertOk()
            ->assertJsonPath('total', $expected);

        $this->assertSame($expected, AIKnowledgeBase::count());
    }

    /** @test */
    public function retriever_ranks_full_text_hits_and_falls_back_to_keywords(): void
    {
        /** @var KnowledgeIngestionService $ingestion */
        $ingestion = app(KnowledgeIngestionService::class);
        $ingestion->ingest([
            [
                'category' => 'shipping',
                'priority' => 10,
                'question' => 'How much does delivery cost?',
                'answer' => 'Local delivery costs approximately $10–$15 depending on weight.',
            ],
            [
                'category' => 'membership',
                'priority' => 5,
                'question' => 'How do membership tiers work?',
                'answer' => 'Bronze, Silver, Gold and Platinum based on yearly spend.',
            ],
        ], 'test');

        /** @var KnowledgeRetriever $retriever */
        $retriever = app(KnowledgeRetriever::class);

        $hits = $retriever->retrieve('How much does delivery cost?');
        $this->assertNotEmpty($hits);
        $this->assertSame('How much does delivery cost?', $hits->first()->question);

        // Keyword-only entry the FTS vector cannot see → ILIKE fallback.
        $ingestion->ingest([
            [
                'category' => 'faq',
                'question' => 'What about quaint customs?',
                'answer' => 'Handled case by case.',
                'keywords' => ['zzzterm'],
            ],
        ], 'test');

        $fallback = $retriever->retrieve('zzzterm');
        $this->assertNotEmpty($fallback, 'Keyword fallback must match keywords JSON');
        $this->assertSame('What about quaint customs?', $fallback->first()->question);

        $this->assertTrue($retriever->retrieve('   ')->isEmpty());
    }

    /** @test */
    public function assistant_context_grounds_storefront_and_staff_conversations(): void
    {
        app(KnowledgeIngestionService::class)->ingest([
            [
                'category' => 'shipping',
                'priority' => 10,
                'question' => 'How much does delivery cost?',
                'answer' => 'Local delivery costs approximately $10–$15 depending on weight.',
            ],
        ], 'test');

        Product::factory()->create(['name' => 'Pikachu Plush Toy', 'is_active' => true]);

        /** @var AssistantContextService $context */
        $context = app(AssistantContextService::class);

        $storefront = AIConversation::create([
            'channel' => 'storefront',
            'external_id' => 'visitor-rag',
            'status' => 'active',
        ]);

        $policy = $context->build($storefront, 'How much does delivery cost?');
        $this->assertStringContainsString('OFFICIAL BUSINESS KNOWLEDGE', $policy);
        $this->assertStringContainsString('Local delivery costs approximately', $policy);

        $catalog = $context->build($storefront, 'do you have a pikachu plush?');
        $this->assertStringContainsString('CATALOG MATCHES', $catalog);
        $this->assertStringContainsString('Pikachu Plush Toy', $catalog);
        // Shoppers get storefront product links (navigable in the shop widget).
        $this->assertStringContainsString('](/product/', $catalog);

        // Staff conversations get knowledge but never the shopper catalog block.
        $staff = AIConversation::create(['channel' => 'web', 'status' => 'active']);
        $staffContext = $context->build($staff, 'How much does delivery cost?');
        $this->assertStringContainsString('OFFICIAL BUSINESS KNOWLEDGE', $staffContext);
        $this->assertStringNotContainsString('CATALOG MATCHES', $staffContext);
    }

    /** @test */
    public function assistant_icon_upload_stores_and_surfaces_on_storefront_config(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->owner)
            ->post('/api/v1/ai/settings/assistant-icon', [
                'icon' => UploadedFile::fake()->image('bot.png', 128, 128),
            ])
            ->assertOk();

        $iconUrl = (string) $response->json('icon_url');
        $this->assertNotSame('', $iconUrl);
        $this->assertStringContainsString('/storage/ai-assistant/', $iconUrl);

        $path = substr($iconUrl, strpos($iconUrl, '/storage/') + strlen('/storage/'));
        Storage::disk('public')->assertExists($path);

        // Public storefront config reflects the uploaded image.
        $this->getJson('/api/v1/storefront/assistant')
            ->assertOk()
            ->assertJsonPath('assistant.icon', $iconUrl);

        $this->actingAs($this->sales)
            ->post('/api/v1/ai/settings/assistant-icon', [
                'icon' => UploadedFile::fake()->image('bot.png'),
            ])
            ->assertForbidden();
    }

    /** @test */
    public function platform_knowledge_answers_howto_questions_with_page_links(): void
    {
        app(KnowledgeIngestionService::class)->ingestPlatformKnowledge();

        $hits = app(KnowledgeRetriever::class)->retrieve('how do I create an invoice?', 5);

        $this->assertNotEmpty($hits);
        $withLinks = $hits->first(fn ($hit) => $hit->category === 'platform' && str_contains((string) $hit->answer, '/invoices/create'));
        $this->assertNotNull($withLinks, 'Platform how-to knowledge must include the create-invoice page link');
    }

    /** @test */
    public function product_search_results_carry_channel_appropriate_links(): void
    {
        Product::factory()->create(['name' => 'Linkable Widget', 'is_active' => true]);

        $staff = ProductFunctions::search(['query' => 'Linkable Widget']);
        $this->assertNotEmpty($staff['products']);
        $this->assertStringStartsWith('/products/', $staff['products'][0]['url']);

        $shopper = ProductFunctions::search(['query' => 'Linkable Widget', '__storefront' => true]);
        $this->assertNotEmpty($shopper['products']);
        $this->assertStringStartsWith('/product/', $shopper['products'][0]['url']);
    }
}
