<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AI\Services\OllamaService;
use Spatie\Permission\Models\Permission;
use Tests\TenantTestCase;

/**
 * MRD R9.3 + DoD: every AI tax advisory interaction is logged, and the
 * advisory surface provably NEVER mutates accounting state.
 */
class TaxEngineAiAdvisoryLogTest extends TenantTestCase
{
    private User $user;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => $this->tenantId]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['tax_ai_advisories', 'tax_determination_results']);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Permission::firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        $this->user->givePermissionTo('reports.view');

        // ai entitlement (entitlement:ai middleware on the route).
        $typeId = DB::table('public.catalog_item_types')->where('key', 'module')->value('id')
            ?? DB::table('public.catalog_item_types')->insertGetId([
                'key' => 'module', 'name' => 'Module', 'sort_order' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        $itemId = DB::table('public.catalog_items')->where('key', 'ai')->value('id')
            ?? DB::table('public.catalog_items')->insertGetId([
                'key' => 'ai', 'name' => 'AI', 'type_id' => $typeId, 'is_active' => true,
                'effective_from' => now()->toDateString(), 'source' => 'catalog',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        DB::table('public.tenant_entitlements')->updateOrInsert(
            ['tenant_id' => $this->tenantId, 'catalog_item_id' => $itemId],
            ['is_entitled' => true, 'source' => 'override_include', 'resolved_at' => now(),
                'created_at' => now(), 'updated_at' => now()],
        );
        app(FeatureGateService::class)->invalidate($this->tenantId);

        Tax::create([
            'name' => 'SG GST out', 'code' => 'AI-GST-9', 'type' => 'gst', 'regime' => 'GST',
            'jurisdiction' => 'SG', 'scope' => 'output', 'treatment' => 'standard_rated',
            'rate' => 9, 'is_active' => true,
        ]);
    }

    private function counts(): array
    {
        return [
            'taxes' => DB::table('taxes')->count(),
            'invoices' => DB::table('invoices')->count(),
            'journal_entries' => DB::table('journal_entries')->count(),
            'tax_determination_results' => DB::table('tax_determination_results')->count(),
            'tax_transaction_details' => DB::table('tax_transaction_details')->count(),
        ];
    }

    public function test_ai_advisory_is_logged_and_never_mutates_state(): void
    {
        $mock = \Mockery::mock(OllamaService::class);
        $mock->shouldReceive('chat')->andReturn([
            'content' => '{"summary":"Reviewed.","risks":["stale rate"],"actions":["verify with IRAS"]}',
        ]);
        $this->instance(OllamaService::class, $mock);

        $before = $this->counts();

        $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/tax-engine/ai-advisory?jurisdiction=SG&direction=output&amount=500')
            ->assertOk()
            ->assertJsonPath('data.mode', 'ai');

        $log = DB::table('tax_ai_advisories')->latest('id')->first();
        $this->assertNotNull($log, 'MRD R9.3: interactions must be logged for audit.');
        $this->assertSame('ai', $log->mode);
        $this->assertSame('SG', $log->jurisdiction);
        $this->assertSame((int) $this->user->id, (int) $log->actor_user_id);

        $basis = json_decode((string) $log->engine_basis, true);
        $this->assertSame(9.0, (float) $basis['rate']);

        $advice = json_decode((string) $log->advice, true);
        $this->assertSame('Reviewed.', $advice['summary']);
        $this->assertSame($before, $this->counts(), 'Advisory must never mutate accounting state.');
    }

    public function test_fallback_mode_is_also_logged_with_engine_warnings(): void
    {
        $mock = \Mockery::mock(OllamaService::class);
        $mock->shouldReceive('chat')->andThrow(new \RuntimeException('model offline'));
        $this->instance(OllamaService::class, $mock);

        $before = $this->counts();

        $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/tax-engine/ai-advisory?jurisdiction=DE&direction=output&amount=100')
            ->assertOk()
            ->assertJsonPath('data.mode', 'deterministic_fallback');

        $log = DB::table('tax_ai_advisories')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('deterministic_fallback', $log->mode);

        $advice = json_decode((string) $log->advice, true);
        $this->assertNotEmpty($advice['actions'], 'Fallback must carry the engine warnings forward.');
        $this->assertSame($before, $this->counts());
    }
}
