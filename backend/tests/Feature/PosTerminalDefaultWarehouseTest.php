<?php

namespace Tests\Feature;

use App\Models\Warehouse;
use Modules\POS\Models\PosSetting;
use Modules\POS\Models\PosTerminal;
use Tests\TenantTestCase;

class PosTerminalDefaultWarehouseTest extends TenantTestCase
{
    /** @test */
    public function terminal_creation_uses_pos_default_warehouse_when_unset(): void
    {
        $warehouse = Warehouse::create([
            'name' => 'Default POS Warehouse',
            'code' => 'POS-DEFAULT',
            'is_active' => true,
        ]);

        PosSetting::setValue('default_warehouse_id', $warehouse->id);

        $terminal = PosTerminal::create([
            'name' => 'Auto-Warehouse Terminal',
            'is_active' => true,
        ]);

        $this->assertSame($warehouse->id, $terminal->warehouse_id);
    }

    /** @test */
    public function terminal_creation_keeps_null_warehouse_when_no_default_exists(): void
    {
        $terminal = PosTerminal::create([
            'name' => 'Unassigned Terminal',
            'is_active' => true,
        ]);

        $this->assertNull($terminal->warehouse_id);
    }
}