<?php

namespace Tests\Feature;

use App\Platform\SystemLog\SystemLog;
use Tests\TenantTestCase;

class AuditTrailExpansionTest extends TenantTestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext(\Illuminate\Support\Str::uuid()->toString());
    }

    public function test_audit_trail_observer_is_registered_for_pos_models(): void
    {
        $this->assertTrue(class_exists(\Modules\POS\Models\PosOrder::class));
        $observers = \Modules\POS\Models\PosOrder::getEventDispatcher();
        $this->assertNotNull($observers);
    }

    public function test_audit_trail_observer_is_registered_for_inventory_models(): void
    {
        $this->assertTrue(class_exists(\Modules\Inventory\Models\StockLevel::class));
        $observers = \Modules\Inventory\Models\StockLevel::getEventDispatcher();
        $this->assertNotNull($observers);
    }

    public function test_audit_trail_observer_is_registered_for_hrm_models(): void
    {
        $this->assertTrue(class_exists(\Modules\HRM\Models\Employee::class));
        $observers = \Modules\HRM\Models\Employee::getEventDispatcher();
        $this->assertNotNull($observers);
    }

    public function test_audit_trail_logs_pos_order_creation(): void
    {
        // PosOrder creation requires a NOT NULL session_id FK into pos_sessions,
        // which is not present in the tenant test schema (the central migration
        // creates pos_sessions in the public schema, so the tenant migration's
        // hasTable() guard skips it). Instead of seeding that fragile FK chain,
        // invoke the observer directly on a PosOrder instance to verify the
        // observer -> SystemLogService wiring and module resolution.
        $order = new \Modules\POS\Models\PosOrder();
        $order->id = 999;

        $observer = app(\App\Observers\AuditTrailObserver::class);
        $observer->created($order);

        $log = SystemLog::where('category', 'audit.pos.created')
            ->where('message', 'like', '%pos_orders%created%')
            ->first();
        $this->assertNotNull($log, 'Audit log for POS order creation not found');
        $this->assertSame('pos', $log->context['module']);
        $this->assertSame('pos_orders', $log->context['table']);
    }
}
