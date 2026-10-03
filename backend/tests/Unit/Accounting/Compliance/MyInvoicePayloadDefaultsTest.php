<?php

namespace Tests\Unit\Accounting\Compliance;

use Modules\AccountingCountry\Malaysia\Services\MyInvoiceMapper;
use PHPUnit\Framework\TestCase;

/**
 * Issue #699 (EC2/AC3) — the MY payload-defaults overlay (tenant supplier
 * identity) is pack-owned: it moved from the core into MyInvoiceMapper behind
 * SupportsPayloadDefaults. Buyer identity is never touched (#813 EP2).
 */
class MyInvoicePayloadDefaultsTest extends TestCase
{
    public function test_apply_payload_defaults_overlays_the_tenant_supplier_identity(): void
    {
        $payload = (new MyInvoiceMapper)->applyPayloadDefaults([
            'supplier' => [],
            'buyer' => ['name' => 'Buyer', 'tin' => 'C1111111110'],
        ], [
            'supplier_tin' => 'C2584563200',
            'supplier_name' => 'NoovaERP Sdn Bhd',
            'supplier_registration_number' => '202401234567',
            'supplier_id_type' => 'BRN',
            'supplier_id_number' => '202401234567',
            'supplier_msic_code' => '46510',
            'supplier_business_activity_description' => 'Wholesale',
            'supplier_phone' => '+60123456789',
            'supplier_email' => 'supplier@example.com',
            'supplier_address' => 'Jalan Test',
            'supplier_city' => 'KL',
            'supplier_state' => '14',
            'supplier_postal_code' => '50000',
        ]);

        $this->assertSame('C2584563200', $payload['supplier']['tin']);
        $this->assertSame('NoovaERP Sdn Bhd', $payload['supplier']['name']);
        $this->assertSame('Jalan Test', $payload['supplier']['address']['street']);
        $this->assertSame('MYS', $payload['supplier']['address']['country']);
        $this->assertSame('C1111111110', $payload['buyer']['tin'], 'the buyer identity must not be touched');
        $this->assertSame('Buyer', $payload['buyer']['name']);
    }

    public function test_apply_payload_defaults_keeps_mapped_supplier_values(): void
    {
        $payload = (new MyInvoiceMapper)->applyPayloadDefaults([
            'supplier' => ['tin' => 'MAPPED-TIN', 'name' => 'Mapped Supplier'],
        ], ['supplier_tin' => 'CTX-TIN', 'supplier_name' => 'Context Supplier']);

        $this->assertSame('MAPPED-TIN', $payload['supplier']['tin']);
        $this->assertSame('Mapped Supplier', $payload['supplier']['name']);
    }
}
