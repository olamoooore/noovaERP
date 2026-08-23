<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Modules\POS\Http\Controllers\PosOrderController;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Modules\POS\Services\PosPaymentCompletionService;
use Tests\TenantTestCase;

class PosMissingCostBasisResponseTest extends TenantTestCase
{
    protected string $tenantId;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->tenantId = 'dddddddd-dddd-dddd-dddd-dddddddddddd';
        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'Missing Cost Basis Test Tenant',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });
        $this->bindTenantContext($this->tenantId);

        $this->user = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'cashier',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! Schema::hasColumn('pos_orders', 'deleted_at')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        Schema::table('pos_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_orders', 'payment_state')) {
                $table->string('payment_state')->nullable();
            }

            if (! Schema::hasColumn('pos_orders', 'payment_method')) {
                $table->string('payment_method')->nullable();
            }

            if (! Schema::hasColumn('pos_orders', 'amount_tendered')) {
                $table->decimal('amount_tendered', 15, 2)->nullable();
            }

            if (! Schema::hasColumn('pos_orders', 'change_due')) {
                $table->decimal('change_due', 15, 2)->nullable();
            }
        });
    }

    /** @test */
    public function pay_returns_422_when_payment_completion_reports_missing_cost_basis(): void
    {
        $user = $this->user;

        $warehouse = Warehouse::create([
            'name' => 'POS Warehouse',
            'code' => 'POS-WH-001',
            'is_active' => true,
        ]);

        $terminal = PosTerminal::create([
            'name' => 'POS Terminal 1',
            'terminal_number' => 'TERM-001',
            'warehouse_id' => $warehouse->id,
            'is_active' => true,
        ]);

        $session = PosSession::create([
            'terminal_id' => $terminal->id,
            'user_id' => $user->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $order = PosOrder::create([
            'external_id' => (string) Str::uuid(),
            'order_number' => 'POS-TEST-'.uniqid(),
            'status' => 'draft',
            'terminal_id' => $terminal->id,
            'session_id' => $session->id,
            'subtotal' => 22.50,
            'tax_amount' => 0,
            'total' => 22.50,
            'currency' => 'SGD',
            'created_by' => $user->id,
        ]);

        $request = Request::create("/api/v1/pos/orders/{$order->id}/pay", 'POST', [
            'method' => 'cash',
            'amount' => 22.50,
        ]);

        $paymentCompletionService = Mockery::mock(PosPaymentCompletionService::class);
        $paymentCompletionService
            ->shouldReceive('complete')
            ->once()
            ->andThrow(new \RuntimeException(
                'Cannot post stock-tracked POS order without cost basis (COGS/inventory lines would be skipped). Affected items: ZERO-COST-001'
            ));

        $response = TestResponse::fromBaseResponse(
            app(PosOrderController::class)->pay($request, $order->id, $paymentCompletionService)
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Missing product cost basis');
        $response->assertJsonPath('message', fn (string $message) => str_contains($message, 'ZERO-COST-001'));
    }
}
