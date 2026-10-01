<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Database\Seeders\ModuleFeatureSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\PageVersion;
use Modules\WebsiteBuilder\Models\PublishLog;
use Modules\WebsiteBuilder\Models\Website;
use Tests\TenantTestCase;

/**
 * SF-032 — P5 publish/rollback TRUTHFULNESS (Appendix-F P5 line:
 * "publish/rollback truthfulness").
 *
 * Before this slice the public renderers served a page's CURRENT version —
 * so any unpublished draft edit changed what visitors saw immediately —
 * and "rollback" only re-copied static files while the database (the actual
 * source of public content) was never touched: the endpoint lied.
 *
 * Proves over real HTTP through the router:
 *   1. publish marks exactly one version per page and records the snapshot
 *      map on the log (what rollback needs to be honest);
 *   2. a draft edit after publish does NOT reach visitors (old behaviour:
 *      it did — instant silent publication);
 *   3. re-publish advances the live version;
 *   4. rollback restores the snapshot content FORWARD (new current+published
 *      version, same restore-forward semantics as the builder's per-page
 *      restore), synchronously, and visitors really see it;
 *   5. rollback to an unknown version 404s; to a pre-snapshot legacy log
 *      it REFUSES loudly instead of pretending (422);
 *   6. never-published pages are not publicly reachable (page-status gate).
 */
class SF032PublishRollbackTruthTest extends TenantTestCase
{
    private string $tenantId;

    private Website $website;

    private User $owner;

    private Page $shop;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET search_path TO public');
        $this->seed(RolePermissionSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF032 Tenant', 'email' => 'sf032-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);
        $this->grant('website-builder');

        $this->owner = $this->makeTenantUser($this->tenantId, 'owner', 'Owner');
        $this->bindTenantContext($this->tenantId);

        $this->website = Website::create([
            'name' => 'SF032 Shop',
            'slug' => 'sf032-shop-'.Str::lower(Str::random(6)),
            'status' => 'draft',
            'created_by' => $this->owner->id,
        ]);

        // The builder auto-creates a Home page; add a real shop page with
        // two distinguishable versions' worth of content markers.
        $this->shop = Page::create([
            'website_id' => $this->website->id,
            'title' => 'Shop',
            'slug' => 'shop',
            'is_home' => false,
            'status' => 'draft',
            'sort_order' => 2,
        ]);
        $this->shop->createVersion(
            ['html' => '<p>SF032-V1-MARKER</p>', 'css' => '', 'header_js' => '', 'body_js' => ''],
            (int) $this->owner->id
        );
    }

    // ─────────────────────────────────────────────────────────────────────

    public function test_public_never_renders_an_unpublished_page_or_site(): void
    {
        $this->actingAs($this->owner)->get('/w/'.$this->website->slug.'/shop')->assertStatus(404);
    }

    public function test_publish_records_snapshot_and_serves_the_published_version(): void
    {
        $res = $this->actingAs($this->owner)->postJson("/api/v1/website-builder/websites/{$this->website->id}/publish");
        $res->assertStatus(200)
            ->assertJsonPath('status', 'published')
            ->assertJsonPath('version', 1);

        $log = PublishLog::where('website_id', $this->website->id)->where('version', 1)->firstOrFail();
        $v1 = $this->shop->versions()->where('version', 1)->firstOrFail();
        $this->assertSame($v1->id, (int) ($log->page_versions[$this->shop->id] ?? 0), 'snapshot must record the live page version');
        $this->assertNull($log->rollback_of);

        $html = $this->get('/w/'.$this->website->slug.'/shop')->assertStatus(200)->getContent();
        $this->assertStringContainsString('SF032-V1-MARKER', $html);

        // Exactly one published version per page after a real publish.
        $this->assertSame(1, PageVersion::where('page_id', $this->shop->id)->where('is_published', true)->count());
    }

    public function test_draft_edit_after_publish_never_reaches_visitors_until_republish(): void
    {
        $this->actingAs($this->owner)->postJson("/api/v1/website-builder/websites/{$this->website->id}/publish")->assertStatus(200);

        // The editor saves through the real route: new CURRENT version.
        $this->actingAs($this->owner)->putJson("/api/v1/website-builder/pages/{$this->shop->id}", [
            'content' => ['html' => '<p>SF032-V2-DRAFT-MARKER</p>', 'css' => '', 'header_js' => '', 'body_js' => ''],
        ])->assertStatus(200);

        $this->assertSame(2, (int) $this->shop->fresh()->currentVersion?->version, 'editor sees the draft as current');

        // Visitors still see V1 — the truthfulness fix.
        $html = $this->get('/w/'.$this->website->slug.'/shop')->assertStatus(200)->getContent();
        $this->assertStringContainsString('SF032-V1-MARKER', $html);
        $this->assertStringNotContainsString('V2-DRAFT', $html, 'an unpublished draft must never go live');

        $json = $this->getJson('/api/v1/website-public/sites/'.$this->website->slug.'/pages/shop')
            ->assertStatus(200)->json('content.html');
        $this->assertStringNotContainsString('V2-DRAFT', (string) $json, 'public JSON path must honour the same gate');

        // Re-publish promotes the draft.
        $this->actingAs($this->owner)->postJson("/api/v1/website-builder/websites/{$this->website->id}/publish")
            ->assertStatus(200)->assertJsonPath('version', 2);
        $html = $this->get('/w/'.$this->website->slug.'/shop')->assertStatus(200)->getContent();
        $this->assertStringContainsString('V2-DRAFT', $html);
        $this->assertStringNotContainsString('SF032-V1-MARKER', $html);
        $this->assertSame(1, PageVersion::where('page_id', $this->shop->id)->where('is_published', true)->count(), 'still exactly one published version');
    }

    public function test_rollback_really_changes_what_visitors_see(): void
    {
        // v1 live
        $this->actingAs($this->owner)->postJson("/api/v1/website-builder/websites/{$this->website->id}/publish")->assertStatus(200);
        // v2 published
        $this->shop->createVersion(['html' => '<p>SF032-V2-LIVE</p>', 'css' => '', 'header_js' => '', 'body_js' => ''], (int) $this->owner->id);
        $this->actingAs($this->owner)->postJson("/api/v1/website-builder/websites/{$this->website->id}/publish")->assertStatus(200);
        $this->assertStringNotContainsString('SF032-V1-MARKER', $this->get('/w/'.$this->website->slug.'/shop')->getContent());

        // Roll back to version 1 — synchronously, and the response lies no more.
        $res = $this->actingAs($this->owner)->postJson("/api/v1/website-builder/websites/{$this->website->id}/rollback/1");
        $res->assertStatus(200)
            ->assertJsonPath('status', 'published')
            ->assertJsonPath('version', 3)
            ->assertJsonPath('rollback_of', 1);

        $html = $this->get('/w/'.$this->website->slug.'/shop')->assertStatus(200)->getContent();
        $this->assertStringContainsString('SF032-V1-MARKER', $html, 'visitors MUST see the rolled-back content');
        $this->assertStringNotContainsString('SF032-V2-LIVE', $html);

        // Restore-forward semantics: history is append-only (3 page versions,
        // the v2 row survives, new v3 carries the v1 content).
        $this->assertSame(3, PageVersion::where('page_id', $this->shop->id)->count());
        $log3 = PublishLog::where('website_id', $this->website->id)->where('version', 3)->firstOrFail();
        $restored = PageVersion::find((int) $log3->page_versions[$this->shop->id]);
        $this->assertNotNull($restored);
        $this->assertSame(3, (int) $restored->version);
        $this->assertStringContainsString('SF032-V1-MARKER', $restored->content['html']);
        $this->assertNotNull(PageVersion::where('page_id', $this->shop->id)->where('version', 2)->first(), 'no history destroyed by rollback');
    }

    public function test_rollback_to_unknown_version_is_404_and_to_legacy_log_refuses_loudly(): void
    {
        $this->actingAs($this->owner)->postJson("/api/v1/website-builder/websites/{$this->website->id}/rollback/99")
            ->assertStatus(404);

        // A legacy success row without a snapshot map (written before this
        // change) must NOT be silently "restored" — refuse honestly.
        PublishLog::create([
            'website_id' => $this->website->id,
            'version' => 50,
            'status' => 'success',
            'published_by' => (int) $this->owner->id,
            'published_at' => now(),
        ]);
        $res = $this->actingAs($this->owner)->postJson("/api/v1/website-builder/websites/{$this->website->id}/rollback/50");
        $res->assertStatus(422)
            ->assertJsonPath('status', 'failed');
        $this->assertStringContainsString('predates version snapshots', (string) $res->json('message'));
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function grant(string $key): void
    {
        $item = CatalogItem::where('key', $key)->firstOrFail();
        TenantEntitlement::query()->updateOrCreate(
            ['tenant_id' => $this->tenantId, 'catalog_item_id' => $item->id],
            ['is_entitled' => true, 'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE, 'resolved_at' => now()]
        );
        app(FeatureGateService::class)->invalidate($this->tenantId);
    }

    private function makeTenantUser(string $tenantId, string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create();

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $user->assignRole($spatieRole);

        return $user;
    }
}
