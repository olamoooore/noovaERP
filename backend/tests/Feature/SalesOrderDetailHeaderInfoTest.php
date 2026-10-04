<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #402 (SU4): the order detail page reads the warehouse and the approval
 * state. The order payload carried only warehouse_id and no approval link,
 * so the header could not show either without the UI inventing data.
 */
class SalesOrderDetailHeaderInfoTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Warehouse $warehouse;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'SO Header Tenant',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });

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
        $this->actingAs($this->admin);

        $this->customer = Customer::factory()->create(['credit_limit' => 1000000]);
        $this->warehouse = Warehouse::create(['name' => 'Header Warehouse', 'code' => 'HW-'.uniqid(), 'is_active' => true]);
    }

    private function order(): SalesOrder
    {
        return SalesOrder::create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'channel' => 'pos',
            'source_document_type' => 'PosOrder',
            'currency_code' => 'MYR',
            'exchange_rate' => 2.5,
            'base_currency_code' => 'USD',
            'order_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 100,
        ]);
    }

    private function approval(SalesOrder $order, string $status, string $reason): ApprovalRequest
    {
        return ApprovalRequest::create([
            'request_type' => SalesApprovalGate::REQUEST_TYPE_SALES_ORDER,
            'subject_type' => SalesOrder::class,
            'subject_id' => $order->id,
            'status' => $status,
            'amount' => $order->total,
            'reason' => $reason,
            'requested_by' => $this->admin->id,
            'requested_at' => now(),
        ]);
    }

    public function test_detail_payload_exposes_the_commitment_warehouse(): void
    {
        $order = $this->order();

        $detail = $this->actingAs($this->admin)->getJson("/api/v1/sales/orders/{$order->id}");

        $detail->assertOk();
        $detail->assertJsonPath('warehouse_id', $this->warehouse->id);
        $detail->assertJsonPath('warehouse.id', $this->warehouse->id);
        $detail->assertJsonPath('warehouse.name', 'Header Warehouse');
    }

    public function test_detail_payload_exposes_the_latest_approval_request_state(): void
    {
        $order = $this->order();

        $this->approval($order, 'approved', 'First request, since superseded.');
        $latest = $this->approval($order, 'pending', 'Second request is still awaiting review.');

        $detail = $this->actingAs($this->admin)->getJson("/api/v1/sales/orders/{$order->id}");

        $detail->assertOk();
        $detail->assertJsonPath('latest_approval_request.id', $latest->id);
        $detail->assertJsonPath('latest_approval_request.status', 'pending');
        $detail->assertJsonPath('latest_approval_request.request_number', $latest->request_number);
    }

    public function test_detail_payload_omits_approval_state_when_no_request_exists(): void
    {
        $order = $this->order();

        $detail = $this->actingAs($this->admin)->getJson("/api/v1/sales/orders/{$order->id}");

        $detail->assertOk();
        $detail->assertJsonPath('latest_approval_request', null);
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(function (string $tenantId, string $key) use ($isEntitled) {
                return [
                    'key' => $key,
                    'is_entitled' => $isEntitled,
                    'source' => $isEntitled ? 'tier_included' : null,
                    'name' => strtoupper($key),
                ];
            });

        $this->app->instance(FeatureGateService::class, $mock);
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
    }
}
