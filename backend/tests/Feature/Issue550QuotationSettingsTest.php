<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #550: quotation settings — default validity days, require signature,
 * require payment (prepayment %). Each rule is driven through the real HTTP
 * routes: PUT /settings/sales, POST /quotations (server-side defaults, per
 * quotation overrides) and the public portal /quotation-accept/{token}.
 */
class Issue550QuotationSettingsTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();
        $this->mockFeatureGate();

        $this->tenantId = (string) Str::uuid();
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue 550 Quotation Settings Tenant',
            'email' => 'issue550-'.uniqid().'@test.local',
            'plan' => 'standard',
        ]));
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create([
            'email' => 'issue550-customer@example.com',
            'payment_terms' => 30,
            'credit_limit' => 1000000,
        ]);
        $this->product = Product::factory()->create([
            'name' => 'Issue 550 Widget',
            'sku' => 'I550-'.Str::upper(Str::random(6)),
            'price' => 100,
            'cost' => 40,
            'track_inventory' => false,
        ]);
        Warehouse::factory()->create(['is_default' => true]);

        $this->actingAs($this->admin);
    }

    // ── Default validity days (server side) ─────────────────────────────

    public function test_quotation_created_without_valid_until_takes_the_default_validity_days(): void
    {
        $this->putJson('/api/v1/settings/sales', ['quotation' => ['default_validity_days' => 14]])->assertOk();

        $created = $this->storeQuotation([], withValidUntil: false)->assertCreated();

        $this->assertSame(
            now()->addDays(14)->toDateString(),
            $this->column($created, 'valid_until'),
            'POST /quotations without valid_until must apply the tenant default validity days.',
        );
    }

    public function test_default_validity_runs_from_a_future_quotation_date(): void
    {
        $this->putJson('/api/v1/settings/sales', ['quotation' => ['default_validity_days' => 10]])->assertOk();

        $date = now()->addDays(5)->toDateString();
        $created = $this->storeQuotation(['quotation_date' => $date], withValidUntil: false)->assertCreated();

        $this->assertSame(Carbon::parse($date)->addDays(10)->toDateString(), $this->column($created, 'valid_until'));
    }

    public function test_explicit_valid_until_and_zero_default_are_respected(): void
    {
        $this->putJson('/api/v1/settings/sales', ['quotation' => ['default_validity_days' => 14]])->assertOk();

        $explicit = $this->storeQuotation(['valid_until' => now()->addDays(60)->toDateString()], withValidUntil: false)->assertCreated();
        $this->assertSame(now()->addDays(60)->toDateString(), $this->column($explicit, 'valid_until'));

        // An explicit null is the user clearing Valid Until (no expiry).
        $cleared = $this->storeQuotation(['valid_until' => null], withValidUntil: false)->assertCreated();
        $this->assertNull($this->column($cleared, 'valid_until'));

        $this->putJson('/api/v1/settings/sales', ['quotation' => ['default_validity_days' => 0]])->assertOk();
        $none = $this->storeQuotation([], withValidUntil: false)->assertCreated();
        $this->assertNull($this->column($none, 'valid_until'), '0 days = new quotations have no expiry date.');
    }

    // ── Settings round trip ─────────────────────────────────────────────

    public function test_signature_and_payment_settings_round_trip(): void
    {
        $before = $this->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->assertJsonPath('data.quotation.require_signature', false)
            ->assertJsonPath('data.quotation.require_payment', false);
        $this->assertEquals(100, $before->json('data.quotation.prepayment_percent'));

        $this->putJson('/api/v1/settings/sales', ['quotation' => [
            'require_signature' => true,
            'require_payment' => true,
            'prepayment_percent' => 30,
        ]])->assertOk();

        $after = $this->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->assertJsonPath('data.quotation.require_signature', true)
            ->assertJsonPath('data.quotation.require_payment', true);
        $this->assertEquals(30, $after->json('data.quotation.prepayment_percent'));

        $defaults = $this->getJson('/api/v1/settings/sales/defaults')
            ->assertOk()
            ->assertJsonPath('data.quotation.require_signature', true)
            ->assertJsonPath('data.quotation.require_payment', true);
        $this->assertEquals(30, $defaults->json('data.quotation.prepayment_percent'));

        $this->putJson('/api/v1/settings/sales', ['quotation' => ['prepayment_percent' => 0]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quotation.prepayment_percent');
        $this->putJson('/api/v1/settings/sales', ['quotation' => ['prepayment_percent' => 101]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quotation.prepayment_percent');
    }

    public function test_new_quotation_stamps_the_tenant_requirements_and_accepts_overrides(): void
    {
        $default = $this->storeQuotation()->assertCreated();
        $this->assertFalse((bool) $this->column($default, 'require_signature'));
        $this->assertFalse((bool) $this->column($default, 'require_payment'));

        $this->putJson('/api/v1/settings/sales', ['quotation' => [
            'require_signature' => true,
            'require_payment' => true,
            'prepayment_percent' => 25,
        ]])->assertOk();

        $stamped = $this->storeQuotation()->assertCreated();
        $this->assertTrue((bool) $this->column($stamped, 'require_signature'));
        $this->assertTrue((bool) $this->column($stamped, 'require_payment'));
        $this->assertSame(25.0, (float) $this->column($stamped, 'prepayment_percent'));

        $override = $this->storeQuotation(['require_signature' => false, 'require_payment' => false])->assertCreated();
        $this->assertFalse((bool) $this->column($override, 'require_signature'));
        $this->assertFalse((bool) $this->column($override, 'require_payment'));

        // Editable per draft quotation as well.
        $id = $this->idOf($override);
        $this->putJson("/api/v1/quotations/{$id}", ['require_payment' => true, 'prepayment_percent' => 50])->assertOk();
        $this->assertTrue((bool) DB::table('quotations')->where('id', $id)->value('require_payment'));
        $this->assertSame(50.0, (float) DB::table('quotations')->where('id', $id)->value('prepayment_percent'));
    }

    // ── Portal: require signature ───────────────────────────────────────

    public function test_portal_refuses_acceptance_without_signature_when_required(): void
    {
        Mail::fake();
        $this->putJson('/api/v1/settings/sales', ['quotation' => ['require_signature' => true]])->assertOk();

        $quotation = $this->sentQuotation();
        $token = $quotation->acceptance_token;

        $this->getJson("/api/v1/quotation-accept/{$token}")
            ->assertOk()
            ->assertJsonPath('require_signature', true);

        $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('signature');

        $quotation->refresh();
        $this->assertNull($quotation->accepted_at, 'A refused acceptance leaves no acceptance trace.');
        $this->assertSame('sent', $quotation->status);

        $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
            'signature' => 'data:image/png;base64,iVBORw0KGgo=',
        ])->assertOk()->assertJsonPath('status', 'confirmed');
    }

    public function test_per_quotation_override_lifts_the_signature_requirement(): void
    {
        Mail::fake();
        $this->putJson('/api/v1/settings/sales', ['quotation' => ['require_signature' => true]])->assertOk();

        $quotation = $this->sentQuotation(['require_signature' => false]);

        $this->postJson("/api/v1/quotation-accept/{$quotation->acceptance_token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
        ])->assertOk()->assertJsonPath('status', 'confirmed');
    }

    // ── Portal: require payment (prepayment %) ──────────────────────────

    public function test_portal_acceptance_of_a_payment_required_quotation_does_not_confirm_it(): void
    {
        Mail::fake();
        $this->putJson('/api/v1/settings/sales', ['quotation' => ['require_payment' => true, 'prepayment_percent' => 30]])->assertOk();

        $quotation = $this->sentQuotation();
        $token = $quotation->acceptance_token;
        $total = (float) $quotation->total;
        $this->assertGreaterThan(0, $total);

        $show = $this->getJson("/api/v1/quotation-accept/{$token}")
            ->assertOk()
            ->assertJsonPath('require_payment', true);
        $this->assertEquals(30, $show->json('prepayment_percent'));
        $this->assertEquals(round($total * 0.30, 2), $show->json('prepayment_amount'));

        $accept = $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
        ]);

        $accept->assertStatus(202)
            ->assertJsonPath('requires_payment', true)
            ->assertJsonPath('status', 'sent');
        $this->assertEquals(round($total * 0.30, 2), $accept->json('prepayment_amount'));

        $quotation->refresh();
        $this->assertNotNull($quotation->accepted_at, 'The customer acceptance itself is recorded.');
        $this->assertSame('sent', $quotation->status, 'A quotation awaiting prepayment must not be confirmed by the portal.');
        $this->assertNull($quotation->sales_order_id, 'No Sales Order before the prepayment is received.');

        // The salesperson confirms once the prepayment has been received.
        $this->postJson("/api/v1/quotations/{$quotation->id}/confirm")->assertOk();
        $this->assertSame('confirmed', $quotation->fresh()->status);
    }

    public function test_per_quotation_override_lifts_the_payment_requirement(): void
    {
        Mail::fake();
        $this->putJson('/api/v1/settings/sales', ['quotation' => ['require_payment' => true]])->assertOk();

        $quotation = $this->sentQuotation(['require_payment' => false]);

        $this->postJson("/api/v1/quotation-accept/{$quotation->acceptance_token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
        ])->assertOk()->assertJsonPath('status', 'confirmed');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function storeQuotation(array $overrides = [], bool $withValidUntil = true)
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 2,
                'unit_price' => 100,
                'tax_rate' => 0,
            ]],
        ];

        if ($withValidUntil) {
            $payload['valid_until'] = now()->addDays(30)->toDateString();
        }

        return $this->postJson('/api/v1/quotations', array_merge($payload, $overrides));
    }

    private function sentQuotation(array $overrides = []): Quotation
    {
        $created = $this->storeQuotation($overrides)->assertCreated();
        $id = $this->idOf($created);

        $this->postJson("/api/v1/quotations/{$id}/send")->assertOk();

        return Quotation::query()->findOrFail($id);
    }

    private function idOf($response): int
    {
        return (int) ($response->json('data.id') ?? $response->json('id'));
    }

    private function column($response, string $column): mixed
    {
        $value = DB::table('quotations')->where('id', $this->idOf($response))->value($column);

        return $column === 'valid_until' && $value !== null ? Carbon::parse($value)->toDateString() : $value;
    }

    private function mockFeatureGate(): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(fn (string $tenantId, string $key) => [
                'key' => $key,
                'is_entitled' => true,
                'source' => 'tier_included',
                'name' => strtoupper($key),
            ]);
        $mock->shouldReceive('all')->andReturn([['key' => 'sales', 'source' => 'tier_included', 'name' => 'SALES']]);
        $mock->shouldReceive('invalidate')->andReturnNull();

        app()->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        Feature::query()->updateOrCreate(
            ['key' => 'sales'],
            ['name' => 'Sales', 'description' => 'Sales module feature gate for tests', 'is_core' => false, 'price' => 0],
        );
    }
}
