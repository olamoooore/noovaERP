<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #177 — delivery orders had a single unconditional confirmation path
 * with no audit trail distinguishing a signed physical POD from a digital
 * confirmation.
 */
class HR177DeliveryOrderConfirmationMethodTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '12121212-3434-5656-7878-909090909090',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['delivery_orders', 'customers', 'warehouses']);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'HR177 User',
            'email' => 'hr177-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->user->assignRole('admin');

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::create(['name' => 'HR177 Warehouse']);

        Storage::fake('local');
    }

    private function draftOrder(): DeliveryOrder
    {
        return DeliveryOrder::create([
            'delivery_order_number' => DeliveryOrder::generateNumber(),
            'sales_order_id' => null,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
            'source' => 'standalone',
            'delivery_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);
    }

    public function test_digital_confirmation_records_the_channel(): void
    {
        $order = $this->draftOrder();

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders/'.$order->id.'/confirm', [
                'method' => 'digital',
                'channel' => 'email',
            ]);

        $response->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('confirmed', $fresh->status);
        $this->assertSame('digital', $fresh->confirmation_method);
        $this->assertSame('email', $fresh->confirmed_via_channel);
    }

    public function test_default_confirmation_still_works_and_is_recorded_as_digital(): void
    {
        $order = $this->draftOrder();

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders/'.$order->id.'/confirm')
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('digital', $fresh->confirmation_method);
        $this->assertSame('system', $fresh->confirmed_via_channel);
    }

    public function test_physical_confirmation_requires_the_signed_document(): void
    {
        $order = $this->draftOrder();

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders/'.$order->id.'/confirm', [
                'method' => 'physical',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('signed', strtolower((string) $response->json('message')));
        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_signed_copy_upload_enables_physical_confirmation(): void
    {
        $order = $this->draftOrder();

        $upload = $this->actingAs($this->user)->post(
            '/api/v1/sales/delivery-orders/'.$order->id.'/signed-document',
            ['file' => UploadedFile::fake()->create('pod.pdf', 100, 'application/pdf')]
        );

        $upload->assertOk();
        $path = $upload->json('signed_document_path');
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->assertNotNull($order->fresh()->signed_document_uploaded_at);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders/'.$order->id.'/confirm', [
                'method' => 'physical',
            ])
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('physical', $fresh->confirmation_method);
        $this->assertSame($path, $fresh->signed_document_path);
        $this->assertNull($fresh->confirmed_via_channel);
    }

    public function test_signed_copy_cannot_be_uploaded_after_confirmation(): void
    {
        $order = $this->draftOrder();

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders/'.$order->id.'/confirm')
            ->assertOk();

        $this->actingAs($this->user)->post(
            '/api/v1/sales/delivery-orders/'.$order->id.'/signed-document',
            ['file' => UploadedFile::fake()->create('pod.pdf', 100, 'application/pdf')]
        )->assertStatus(422);
    }
}
