<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Models\CommunicationTemplate;
use Modules\Communication\Services\TemplateRenderer;
use Modules\Storefront\Database\Seeders\StorefrontTemplateSeeder;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\AccountService;
use Modules\Storefront\Services\CartService;
use Modules\Storefront\Shortcodes\StorefrontShortcodeRegistry;
use Tests\TenantTestCase;

/**
 * SF-019 — FR-MSG-03: default tenant-branded templates shipped by the
 * module, per-tenant overrides, minimal versioning — plus the Communication
 * template-scope fix (system defaults were invisible under tenancy, which
 * is exactly where lifecycle mail renders) and the forgot/reset islands the
 * reset links depend on.
 */
class SF019LifecycleTemplatesTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withCredentials();
        Queue::fake();
        DB::statement('SET search_path TO public');

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF019 Shop', 'email' => 'sf019-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId, ['name' => 'SF019 Shop']);

        $item = CatalogItem::where('key', 'storefront')->firstOrFail();
        TenantEntitlement::query()->create([
            'tenant_id' => $this->tenantId, 'catalog_item_id' => $item->id,
            'is_entitled' => true, 'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE, 'resolved_at' => now(),
        ]);
        app(FeatureGateService::class)->invalidate($this->tenantId);

        StorefrontSettings::current();

        if (! User::query()->exists()) {
            $u = User::factory()->create();
            DB::table('public.users')->updateOrInsert(
                ['email' => $u->email],
                ['name' => $u->name, 'password' => $u->password, 'created_at' => now(), 'updated_at' => now()]
            );
            $centralId = DB::table('public.users')->where('email', $u->email)->value('id');
            DB::table('user_company_access')->updateOrInsert(
                ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
                ['role' => 'owner', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
            );
            $u->assignRole('Owner');
        }
    }

    // ─── scope fix: system defaults visible under tenancy; overrides win ──

    public function test_system_template_is_visible_under_tenant_context_and_override_wins(): void
    {
        CommunicationTemplate::withoutGlobalScopes()->create([
            'key' => 'sf019.probe', 'language' => 'en', 'tenant_id' => null,
            'name' => 'SF019 probe (system)', 'module' => 'storefront', 'event' => 'sf019.probe',
            'subject' => 'SYSTEM subject', 'body_html' => 'system body',
            'channels' => ['email'], 'is_active' => true, 'is_system' => true,
        ]);

        $renderer = app(TemplateRenderer::class);

        // Under THIS tenant's context the system row must resolve (pre-fix:
        // the global scope AND-ed it away and render() returned null).
        $out = $renderer->render('sf019.probe', [], 'en', $this->tenantId);
        $this->assertNotNull($out, 'P-05-adjacent scope bug regression: system fallback must be visible under tenancy');
        $this->assertSame('SYSTEM subject', $out['subject']);

        // Tenant override (same key) takes precedence.
        CommunicationTemplate::withoutGlobalScopes()->create([
            'key' => 'sf019.probe', 'language' => 'en', 'tenant_id' => $this->tenantId,
            'name' => 'SF019 probe (tenant)', 'module' => 'storefront', 'event' => 'sf019.probe',
            'subject' => 'TENANT subject', 'body_html' => 'tenant body',
            'channels' => ['email'], 'is_active' => true, 'is_system' => false,
        ]);
        \Illuminate\Support\Facades\Cache::forget("comm_tpl:{$this->tenantId}:sf019.probe:en");

        $out2 = $renderer->render('sf019.probe', [], 'en', $this->tenantId);
        $this->assertSame('TENANT subject', $out2['subject']);

        // A DIFFERENT tenant must not see this tenant's rows (isolation kept).
        $other = (string) Str::uuid();
        $this->bindTenantContext($other);
        $out3 = $renderer->render('sf019.probe', [], 'en', $other);
        $this->assertSame('SYSTEM subject', $out3['subject'], 'cross-tenant override leak');
    }

    public function test_seed_command_is_idempotent_stamps_version_and_clears_cache(): void
    {
        $this->artisan('storefront:templates:seed')->assertSuccessful();
        $first = CommunicationTemplate::withoutGlobalScopes()->where('module', 'storefront')->get();
        $this->assertCount(12, $first);
        $this->assertTrue($first->every(fn ($t) => (int) $t->template_version === StorefrontTemplateSeeder::VERSION));
        $this->assertTrue($first->every(fn ($t) => $t->tenant_id === null && $t->is_system));

        $this->artisan('storefront:templates:seed')->assertSuccessful();
        $this->assertSame(12, CommunicationTemplate::withoutGlobalScopes()->where('module', 'storefront')->count(),
            're-seed must not duplicate rows');
    }

    // ─── end-to-end rendered lifecycle mail ─────────────────────────────

    public function test_checkout_email_uses_seeded_template_and_fills_every_variable(): void
    {
        $this->artisan('storefront:templates:seed')->assertSuccessful();

        $ctx = $this->checkout();

        $msg = CommunicationMessage::query()->where('subtype', 'storefront.order.placed')->sole();
        $this->assertSame("Your order {$ctx['order_number']} is confirmed", $msg->subject);
        $this->assertStringNotContainsString('{{', $msg->body, 'every placeholder must resolve');
        $this->assertStringContainsString($ctx['order_number'], $msg->body);
        $this->assertStringContainsString('sfconf=', $msg->body, 'confirmation link present');
        $this->assertStringContainsString('SF019 Shop', $msg->body, 'tenant branding (company.name) resolved');
        $this->assertStringNotContainsString('{{', $msg->body_plain);
    }

    public function test_tenant_override_changes_checkout_subject_without_touching_system_row(): void
    {
        $this->artisan('storefront:templates:seed')->assertSuccessful();

        CommunicationTemplate::withoutGlobalScopes()->create([
            'key' => 'storefront.order.placed', 'language' => 'en', 'tenant_id' => $this->tenantId,
            'name' => 'Order placed (tenant custom)', 'module' => 'storefront', 'event' => 'storefront.order.placed',
            'subject' => 'Sukidah! {{order.order_number}} received', 'body_html' => 'custom body {{order.total}}',
            'channels' => ['email'], 'is_active' => true, 'is_system' => false, 'template_version' => 1,
        ]);
        \Illuminate\Support\Facades\Cache::forget("comm_tpl:{$this->tenantId}:storefront.order.placed:en");

        $ctx = $this->checkout();

        $msg = CommunicationMessage::query()->where('subtype', 'storefront.order.placed')->sole();
        $this->assertSame("Sukidah! {$ctx['order_number']} received", $msg->subject);
        // system row untouched
        $this->assertSame('Your order {{order.order_number}} is confirmed',
            CommunicationTemplate::withoutGlobalScopes()
                ->where('key', 'storefront.order.placed')->whereNull('tenant_id')->value('subject'));
    }

    public function test_verification_email_carries_templated_link_and_token(): void
    {
        $this->artisan('storefront:templates:seed')->assertSuccessful();

        ['account' => $account, 'verification_token' => $token] = app(AccountService::class)
            ->register('tmpl-'.Str::random(6).'@example.test', 'Passw0rd!');

        $msg = CommunicationMessage::query()->where('subtype', 'storefront.customer.email_verification')->sole();
        $this->assertStringStartsWith('Confirm your email for', (string) $msg->subject);
        $this->assertStringContainsString('sfverify='.$token, (string) $msg->body);
        $this->assertStringContainsString('sfemail=', (string) $msg->body);
        $this->assertStringNotContainsString('{{', (string) $msg->body);
    }

    // ─── registry + runtime contract ────────────────────────────────────

    public function test_forgot_and_reset_islands_registered_and_runtime_prefills_links(): void
    {
        $registry = app(StorefrontShortcodeRegistry::class);
        $this->assertNotNull($registry->get('storefront.forgot_password'));
        $this->assertNotNull($registry->get('storefront.reset_password'));

        $js = file_get_contents(public_path('storefront/assets/v1/storefront.js'));
        $this->assertStringContainsString("'sfreset'", $js);
        $this->assertStringContainsString("'sfverify'", $js);
        $this->assertStringContainsString('/auth/forgot-password', $js);
        $this->assertStringContainsString('/auth/reset-password', $js);
        $this->assertStringNotContainsString('innerHTML', $js);
    }

    // ─── helper ─────────────────────────────────────────────────────────

    /** @return array{order_number:string} */
    private function checkout(): array
    {
        $product = Product::factory()->create([
            'name' => 'S19 Mug '.Str::random(4), 'show_on_website' => true, 'is_active' => true,
            'status' => 'active', 'track_inventory' => false, 'stock_quantity' => 5,
            'price' => 20, 'tax_rate' => 0,
        ]);

        $token = bin2hex(random_bytes(32));
        $cart = StorefrontCart::create(['guest_token_hash' => hash('sha256', $token), 'status' => 'active']);
        app(CartService::class)->addOrUpdateItem($cart, (int) $product->id, null, 1);

        $res = $this->withUnencryptedCookie(CartService::COOKIE, $token)->postJson('/api/v1/storefront/checkout', [
            'email' => 's19-'.Str::random(6).'@example.test',
            'idempotency_key' => 'idem-'.Str::random(24),
            'shipping_address' => ['name' => 'T', 'address1' => 'U', 'city' => 'V', 'postal_code' => 'W', 'country_code' => 'MY'],
        ]);
        $res->assertStatus(201);

        return ['order_number' => $res->json('data.order_number')];
    }
}
