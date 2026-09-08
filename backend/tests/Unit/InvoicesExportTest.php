<?php

namespace Tests\Unit;

use App\Exports\InvoicesExport;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use Modules\Contact\src\Models\CoreContact;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Mockery;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class InvoicesExportTest extends TestCase
{
    public function test_it_maps_selected_columns_with_excel_native_dates_and_numbers(): void
    {
        $columns = ['invoice_number', 'invoice_date', 'customer_name', 'total', 'status', 'due_date'];
        $export = new InvoicesExport(Mockery::mock(Builder::class), $columns);
        $invoice = (new Invoice)->forceFill([
            'invoice_number' => '2026/08/001',
            'invoice_date' => Carbon::parse('2026-08-21'),
            'due_date' => Carbon::parse('2026-09-20'),
            'total' => '1234.50',
            'status' => 'posted',
        ]);
        $invoice->setRelation('customer', (new Customer)->forceFill(['name' => 'Acme Hospital']));

        $this->assertSame(['Invoice No', 'Date', 'Customer', 'Amount', 'Status', 'Due Date'], $export->headings());
        $this->assertSame([
            '2026/08/001',
            Date::dateTimeToExcel(Carbon::parse('2026-08-21')),
            'Acme Hospital',
            1234.5,
            'posted',
            Date::dateTimeToExcel(Carbon::parse('2026-09-20')),
        ], $export->map($invoice));
        $this->assertSame('yyyy-mm-dd', $export->columnFormats()['B']);
        $this->assertSame('#,##0.00_-', $export->columnFormats()['D']);
        $this->assertSame(1000, $export->chunkSize());
    }

    public function test_custom_binder_writes_strings_and_numbers_without_recursion(): void
    {
        $export = new InvoicesExport(Mockery::mock(Builder::class), ['invoice_number', 'total']);
        $spreadsheet = new Spreadsheet;

        Cell::setValueBinder($export);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setCellValue('A1', '=unsafe invoice text');
            $sheet->setCellValue('B1', 1234.5);

            $this->assertSame('=unsafe invoice text', $sheet->getCell('A1')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());
            $this->assertSame(1234.5, $sheet->getCell('B1')->getValue());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B1')->getDataType());
        } finally {
            Cell::setValueBinder(new DefaultValueBinder);
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_it_expands_invoice_product_lines_into_separate_rows(): void
    {
        $columns = ['invoice_number', 'product_code', 'product_name', 'quantity', 'unit_price', 'expiry_date', 'line_total'];
        $export = new InvoicesExport(Mockery::mock(Builder::class), $columns);
        $invoice = (new Invoice)->forceFill(['invoice_number' => 'INV-001']);

        $firstLine = (new InvoiceLine)->forceFill([
            'quantity' => 2,
            'unit_price' => '15.50',
            'expiry_date' => Carbon::parse('2027-01-31'),
            'line_total' => '31.00',
        ]);
        $firstLine->setRelation('product', (new Product)->forceFill(['sku' => 'SKU-1', 'name' => 'Product One']));
        $secondLine = (new InvoiceLine)->forceFill([
            'quantity' => 1,
            'unit_price' => '20.00',
            'line_total' => '20.00',
        ]);
        $secondLine->setRelation('product', (new Product)->forceFill(['sku' => 'SKU-2', 'name' => 'Product Two']));
        $invoice->setRelation('lines', collect([$firstLine, $secondLine]));

        $this->assertSame([
            ['INV-001', 'SKU-1', 'Product One', 2.0, 15.5, Date::dateTimeToExcel(Carbon::parse('2027-01-31')), 31.0],
            ['INV-001', 'SKU-2', 'Product Two', 1.0, 20.0, null, 20.0],
        ], $export->map($invoice));
    }

    public function test_it_exports_case_contacts_and_structured_address_snapshots(): void
    {
        $columns = [
            'doctor', 'requestor', 'billing_contact', 'shipping_contact',
            'billing_label', 'billing_street', 'billing_street2', 'billing_city',
            'billing_state', 'billing_zip', 'billing_country',
            'shipping_label', 'shipping_street', 'shipping_street2', 'shipping_city',
            'shipping_state', 'shipping_zip', 'shipping_country',
        ];
        $export = new InvoicesExport(Mockery::mock(Builder::class), $columns);
        $invoice = (new Invoice)->forceFill([
            'billing_address_snapshot' => json_encode([
                'label' => 'Head Office', 'street' => '10 Billing Road', 'street2' => '#01-01',
                'city' => 'Singapore', 'state' => 'Singapore', 'zip' => '123456', 'country' => 'Singapore',
            ]),
            'delivery_address_snapshot' => [
                'label' => 'Warehouse', 'address' => '20 Delivery Road', 'address2' => '#02-02',
                'city' => 'Singapore', 'state' => 'Singapore', 'postal_code' => '654321', 'country' => 'Singapore',
            ],
        ]);
        $invoice->setRelation('doctor', (new CoreContact)->forceFill(['display_name' => 'Dr Sarah Lee']));
        $invoice->setRelation('requestor', (new CoreContact)->forceFill(['display_name' => 'Jane Doe']));
        $invoice->setRelation('billingContact', (new CoreContact)->forceFill(['display_name' => 'Acme Billing']));
        $invoice->setRelation('shippingContact', (new CoreContact)->forceFill(['display_name' => 'Acme Delivery']));

        $this->assertSame([
            'Dr Sarah Lee', 'Jane Doe', 'Acme Billing', 'Acme Delivery',
            'Head Office', '10 Billing Road', '#01-01', 'Singapore', 'Singapore', '123456', 'Singapore',
            'Warehouse', '20 Delivery Road', '#02-02', 'Singapore', 'Singapore', '654321', 'Singapore',
        ], $export->map($invoice));
    }

    public function test_it_exports_invoice_case_references_notes_and_status_fields(): void
    {
        $columns = [
            'po_number', 'case_number', 'date_of_case', 'case_location', 'patient_name',
            'cost_center_id', 'delivery_date', 'customer_reference', 'internal_reference',
            'notes', 'terms',
        ];
        $export = new InvoicesExport(Mockery::mock(Builder::class), $columns);
        $invoice = (new Invoice)->forceFill([
            'po_number' => 'PO-456',
            'case_number' => 'CASE-2026-001',
            'date_of_case' => Carbon::parse('2026-01-14'),
            'case_location' => 'Main Site',
            'patient_name' => 'John Smith',
            'cost_center_id' => 'CC-001',
            'delivery_date' => Carbon::parse('2026-01-20'),
            'customer_reference' => 'REF-789',
            'internal_reference' => 'INT-001',
            'notes' => 'Clinic visit',
            'terms' => 'Net 30',
        ]);

        $this->assertSame([
            'PO-456',
            'CASE-2026-001',
            Date::dateTimeToExcel(Carbon::parse('2026-01-14')),
            'Main Site',
            'John Smith',
            'CC-001',
            Date::dateTimeToExcel(Carbon::parse('2026-01-20')),
            'REF-789',
            'INT-001',
            'Clinic visit',
            'Net 30',
        ], $export->map($invoice));
    }
}
