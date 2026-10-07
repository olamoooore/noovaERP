<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Feature;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\DocumentTemplateAssignment;
use Tests\TenantTestCase;

/**
 * Issue #551: Sales Settings → Sales Orders "Pro-forma invoice" toggle. When
 * off, the pro-forma render routes refuse (and the create screens learn it
 * through GET /settings/sales/defaults to hide the action). Driven through
 * the real routes: PUT /settings/sales, then GET /sales/orders/{id}/proforma.
 */
class Issue551ProformaInvoiceToggleTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->ensureFeaturesTableExists();
        $this->mockFeatureGate();

        $this->tenantId = (string) Str::uuid();
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue 551 Pro-forma Tenant',
            'email' => 'issue551-'.uniqid().'@test.local',
            'plan' => 'standard',
        ]));
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin);
    }

    public function test_proforma_toggle_round_trips_and_defaults_to_enabled(): void
    {
        $this->getJson('/api/v1/settings/sales')->assertOk()->assertJsonPath('data.sales_order.proforma_enabled', true);
        $this->getJson('/api/v1/settings/sales/defaults')->assertOk()->assertJsonPath('data.sales_order.proforma_enabled', true);

        $this->putJson('/api/v1/settings/sales', ['sales_order' => ['proforma_enabled' => false]])->assertOk();

        $this->getJson('/api/v1/settings/sales')->assertOk()->assertJsonPath('data.sales_order.proforma_enabled', false);
        $this->getJson('/api/v1/settings/sales/defaults')->assertOk()->assertJsonPath('data.sales_order.proforma_enabled', false);
    }

    public function test_proforma_routes_render_when_enabled_and_refuse_when_disabled(): void
    {
        $order = $this->salesOrder();
        $this->publishedTemplate('sales_proforma_invoice', '<div>PRO FORMA INVOICE {{ sales_order.number }}</div>');

        $this->get("/api/v1/sales/orders/{$order->id}/proforma/html")
            ->assertOk()
            ->assertSee('PRO FORMA INVOICE');

        $this->putJson('/api/v1/settings/sales', ['sales_order' => ['proforma_enabled' => false]])->assertOk();

        $this->getJson("/api/v1/sales/orders/{$order->id}/proforma/html")
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROFORMA_DISABLED');
        $this->getJson("/api/v1/sales/orders/{$order->id}/proforma/pdf")
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROFORMA_DISABLED');
        $this->postJson("/api/v1/documents/sales_proforma_invoice/{$order->id}/regenerate")
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROFORMA_DISABLED');

        // The other sales order documents are unaffected.
        $this->publishedTemplate('sales_order', '<div>SALES ORDER {{ sales_order.number }}</div>');
        $this->get("/api/v1/sales/orders/{$order->id}/html")->assertOk()->assertSee('SALES ORDER');
    }

    private function salesOrder(): SalesOrder
    {
        $customer = Customer::factory()->create(['name' => 'Issue 551 Customer']);
        $product = Product::factory()->create(['track_inventory' => false, 'name' => 'Issue 551 Widget', 'price' => 100, 'cost' => 60]);

        $order = SalesOrder::create([
            'customer_id' => $customer->id,
            'status' => 'confirmed',
            'order_date' => now()->toDateString(),
            'currency_code' => 'MYR',
            'created_by' => $this->admin->id,
        ]);
        $order->lines()->create([
            'product_id' => $product->id,
            'description' => 'Issue 551 Widget',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]);
        $order->calculateTotals();

        return $order->fresh(['lines', 'customer']);
    }

    private function publishedTemplate(string $documentType, string $html): DocumentTemplate
    {
        $template = DocumentTemplate::create([
            'document_type' => $documentType,
            'name' => 'Issue 551 '.$documentType,
            'mode' => DocumentTemplate::MODE_CODE,
            'html' => $html,
            'status' => DocumentTemplate::STATUS_PUBLISHED,
            'published_version' => 1,
        ]);
        $template->versions()->create([
            'template_id' => $template->id,
            'version' => 1,
            'html' => $template->html,
            'is_published' => true,
        ]);
        DocumentTemplateAssignment::create([
            'document_type' => $documentType,
            'template_type_code' => null,
            'template_id' => $template->id,
            'scope_type' => 'tenant',
            'scope_value' => null,
            'priority' => 10,
            'is_active' => true,
        ]);

        return $template;
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
