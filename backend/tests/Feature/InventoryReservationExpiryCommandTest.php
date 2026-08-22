<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Services\StockReservationService;
use Tests\TenantTestCase;

class InventoryReservationExpiryCommandTest extends TenantTestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    }

    public function test_expiry_command_expires_overdue_reservation_and_releases_reserved_quantity(): void
    {
        $productId = DB::table('products')->insertGetId([
            'name' => 'RM001 Product',
            'sku' => 'RM001-PROD-001',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $warehouseId = DB::table('warehouses')->insertGetId([
            'name' => 'RM001 Warehouse',
            'code' => 'RM001-WH-001',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stockLevel = StockLevel::create([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'quantity' => 10,
            'reserved_quantity' => 3,
            'min_quantity' => 0,
        ]);

        $reservation = StockReservation::create([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'quantity' => 3,
            'quantity_fulfilled' => 0,
            'status' => 'open',
            'source_document_type' => 'SalesOrder',
            'source_document_id' => 12001,
            'source_document_line_id' => 1,
            'expires_at' => now()->subMinutes(5),
            'notes' => 'RM-001 test reservation',
        ]);

        $this->artisan('inventory:expire-reservations')
            ->assertExitCode(0);

        $reservation->refresh();
        $stockLevel->refresh();

        $this->assertSame('expired', $reservation->status);
        $this->assertNotNull($reservation->released_at);
        $this->assertSame(0, $stockLevel->reserved_quantity);

        // Idempotency: running a second time must not double-release or mutate state.
        $this->artisan('inventory:expire-reservations')
            ->assertExitCode(0);

        $reservation->refresh();
        $stockLevel->refresh();

        $this->assertSame('expired', $reservation->status);
        $this->assertSame(0, $stockLevel->reserved_quantity);
    }

    public function test_expiry_command_logs_and_fails_when_service_throws(): void
    {
        $mock = Mockery::mock(StockReservationService::class);
        $mock->shouldReceive('expireOverdue')
            ->once()
            ->andThrow(new \RuntimeException('forced failure'));

        $this->app->instance(StockReservationService::class, $mock);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Failed to run reservation expiry command'
                    && ($context['command'] ?? null) === 'inventory:expire-reservations'
                    && ($context['error'] ?? null) === 'forced failure';
            });

        $this->artisan('inventory:expire-reservations')
            ->expectsOutput('Reservation expiry command failed. Check logs for details.')
            ->assertExitCode(1);
    }
}
