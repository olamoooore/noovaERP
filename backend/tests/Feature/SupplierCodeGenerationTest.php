<?php

namespace Tests\Feature;

use Modules\Inventory\Models\Supplier;
use Tests\TenantTestCase;

class SupplierCodeGenerationTest extends TenantTestCase
{
    /** @test */
    public function it_skips_codes_belonging_to_soft_deleted_suppliers(): void
    {
        $this->assertTenantSchemaReady(['suppliers']);

        $supplier = Supplier::create([
            'name' => 'Joel Kisha',
            'code' => 'JOEL0001',
            'payment_terms_days' => 30,
            'is_active' => true,
        ]);

        $supplier->delete();

        $generatedCode = Supplier::generateCode('Joel Kisha');

        $this->assertSame('JOEL0002', $generatedCode);

        $replacementSupplier = Supplier::create([
            'name' => 'Joel Kisha',
            'code' => $generatedCode,
            'payment_terms_days' => 30,
            'is_active' => true,
        ]);

        $this->assertSame('JOEL0002', $replacementSupplier->code);
    }
}