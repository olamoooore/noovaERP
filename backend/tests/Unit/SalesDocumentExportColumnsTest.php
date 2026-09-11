<?php

namespace Tests\Unit;

use App\Exports\DeliveryOrdersExport;
use App\Exports\QuotationsExport;
use PHPUnit\Framework\TestCase;

class SalesDocumentExportColumnsTest extends TestCase
{
    public function test_quotation_export_exposes_detail_panel_and_linked_invoice_fields(): void
    {
        $this->assertArrayHasKey('quotation_date', QuotationsExport::COLUMNS);
        $this->assertArrayHasKey('valid_until', QuotationsExport::COLUMNS);
        $this->assertArrayHasKey('customer_reference', QuotationsExport::COLUMNS);

        foreach ([
            'internal_reference',
            'salesperson',
            'delivery_lead_time',
            'shipping_method',
            'shipping_terms',
            'validity',
            'attention_to',
            'contact_email',
            'contact_phone',
            'billing_address',
            'shipping_address',
            'notes',
            'terms',
            'invoice_numbers',
        ] as $column) {
            $this->assertArrayHasKey($column, QuotationsExport::COLUMNS);
        }
    }

    public function test_delivery_order_export_exposes_every_delivery_detail_field(): void
    {
        foreach ([
            'delivery_date',
            'expected_date',
            'carrier',
            'tracking_number',
            'customer_name',
            'warehouse_name',
            'po_number',
            'salesperson',
            'attention_to',
            'created_by_name',
            'confirmed_by_name',
            'invoice_number',
            'quotation_number',
            'notes',
            'shipping_address',
        ] as $column) {
            $this->assertArrayHasKey($column, DeliveryOrdersExport::COLUMNS);
        }
    }

    public function test_delivery_order_export_exposes_patient_name_for_product_lines(): void
    {
        $this->assertSame(
            ['heading' => 'Patient Name', 'type' => 'text', 'line' => true],
            DeliveryOrdersExport::COLUMNS['patient_name'],
        );
    }

    public function test_both_document_exports_expose_notes(): void
    {
        $this->assertArrayHasKey('notes', QuotationsExport::COLUMNS);
        $this->assertArrayHasKey('notes', DeliveryOrdersExport::COLUMNS);
    }
}
