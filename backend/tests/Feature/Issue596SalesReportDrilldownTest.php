<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

/**
 * Issue #596 (SALES-RPT, RPT-UX1): every sales-report row — customer, product,
 * category and period — drills down to the source documents behind it
 * (number, date, net amount), and the drill-down's total equals the row.
 *
 * The rule asserted here is "panel total = row value" for EVERY row of every
 * tab, over a mixed period (posted invoice with a header discount, a posted
 * credit note, a walk-in POS sale and an un-invoiced booking), so the drill
 * can only pass by reading the same #355 line-item read model and the same
 * #593 filters as the row. Everything goes through the real HTTP routes.
 */
class Issue596SalesReportDrilldownTest extends TenantTestCase
{
    private User $admin;

    private Customer $hospital;

    private Customer $clinic;

    private ProductCategory $implants;

    private Product $implant;

    private Product $service;

    private Invoice $invoice;

    private CustomerCreditNote $creditNote;

    private PosOrder $posSale;

    private \Modules\Sales\Models\SalesOrder $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Drilldown 596 Tenant');
        CompanySettings::query()->create(['base_currency' => 'MYR']);

        $this->implants = ProductCategory::create(['name' => '596 Implants', 'is_active' => true]);

        $this->hospital = Customer::factory()->create(['name' => '596 Hospital', 'is_active' => true]);
        $this->clinic = Customer::factory()->create(['name' => '596 Clinic', 'is_active' => true]);

        $this->implant = Product::factory()->create([
            'name' => '596 Implant',
            'sku' => 'IMP596-'.uniqid(),
            'category_id' => $this->implants->id,
            'price' => 300,
            'cost' => 100,
            'track_inventory' => false,
        ]);
        $this->service = Product::factory()->create([
            'name' => '596 Service',
            'sku' => 'SRV596-'.uniqid(),
            'category_id' => null,
            'price' => 100.33,
            'cost' => 0,
            'track_inventory' => false,
        ]);

        // Posted invoice, two products, header discount spread pro-rata
        // (rounding per line is exactly what the drill total must match).
        $create = $this->actingAs($this->admin)->postJson('/api/v1/accounting/origin/invoices', [
            'customer_id' => $this->hospital->id,
            'invoice_date' => now()->toDateString(),
            'auto_post' => true,
            'discount_amount' => 10,
            'lines' => [
                [
                    'line_type' => 'product', 'product_id' => $this->implant->id,
                    'description' => '596 implant', 'quantity' => 1, 'unit_price' => 300,
                    'tax_rate' => 6, 'discount_amount' => 0,
                ],
                [
                    'line_type' => 'product', 'product_id' => $this->service->id,
                    'description' => '596 service', 'quantity' => 2, 'unit_price' => 100.33,
                    'tax_rate' => 0, 'discount_amount' => 0,
                ],
            ],
        ]);
        $create->assertCreated();
        $this->invoice = Invoice::findOrFail((int) $create->json('id'));
        $this->assertSame('posted', $this->invoice->lifecycle_status);

        // Posted credit note against it (negative revenue on the implant row).
        $creditNote = CustomerCreditNote::create([
            'customer_id' => $this->hospital->id,
            'invoice_id' => $this->invoice->id,
            'credit_note_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 50,
            'tax_amount' => 0,
            'total_amount' => 50,
            'reason' => '596 credit',
        ]);
        $creditNote->lines()->create([
            'product_id' => $this->implant->id,
            'description' => '596 credit line',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_rate' => 0,
        ]);
        $this->actingAs($this->admin)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNote->id}/post")
            ->assertOk();
        $this->creditNote = $creditNote->fresh();
        $this->assertSame('posted', $this->creditNote->status);

        // Walk-in POS sale (no customer): the "Walk-in Customer" row.
        $this->posSale = PosOrder::factory()->create([
            'customer_id' => null,
            'status' => 'paid',
            'is_return' => false,
            'subtotal' => 120,
            'tax_amount' => 0,
            'total' => 120,
            'discount_amount' => 0,
            'currency' => 'MYR',
            'paid_at' => now(),
            'payment_method' => 'cash',
        ]);
        PosOrderItem::create([
            'pos_order_id' => $this->posSale->id,
            'product_id' => $this->implant->id,
            'name' => $this->implant->name,
            'quantity' => 1,
            'unit_price' => 120,
            'tax_amount' => 0,
            'line_total' => 120,
        ]);

        // Un-invoiced booking for the clinic.
        $order = app(SalesOrderService::class)->createOrder([
            'customer_id' => $this->clinic->id,
            'channel' => 'admin',
            'created_by' => $this->admin->id,
            'currency_code' => 'MYR',
            'exchange_rate' => 1,
            'lines' => [[
                'product_id' => $this->implant->id,
                'description' => '596 booking line',
                'quantity' => 1,
                'unit_price' => 250,
                'price_mode' => 'carried',
                'tax_rate' => 0,
            ]],
        ]);
        DB::table('sales_orders')->where('id', $order->id)->update([
            'status' => 'confirmed',
            'order_date' => now()->toDateString(),
        ]);
        $this->booking = $order->fresh();
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────

    private function range(): array
    {
        return [
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ];
    }

    private function rows(string $endpoint, string $key, array $params = []): Collection
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/'.$endpoint.'?'.http_build_query(array_merge($this->range(), $params)));
        $response->assertOk();

        return collect($response->json("data.{$key}") ?? []);
    }

    private function drill(string $dimension, string $key, array $params = [])
    {
        return $this->actingAs($this->admin)->getJson('/api/v1/analytics/sales/drilldown?'.http_build_query(array_merge(
            $this->range(),
            ['dimension' => $dimension, 'key' => $key],
            $params,
        )));
    }

    /**
     * Asserts the drill-down for one row lists documents whose net amounts
     * add up to the row value, and returns the documents.
     */
    private function assertDrillEqualsRow(string $dimension, string $key, float $rowValue, array $params = []): Collection
    {
        $response = $this->drill($dimension, $key, $params);
        $response->assertOk();

        $documents = collect($response->json('data.documents'));
        $sum = round((float) $documents->sum(fn ($doc) => (float) $doc['net_amount']), 2);

        $this->assertSame(
            round($rowValue, 2),
            $sum,
            "Drill-down {$dimension}={$key}: documents must add up to the row value"
        );
        $this->assertSame(round($rowValue, 2), round((float) $response->json('data.summary.total_net_amount'), 2));

        foreach ($documents as $doc) {
            $this->assertNotEmpty($doc['document_number'], 'Every drilled document carries its number');
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $doc['document_date']);
            $this->assertContains($doc['document_type'], ['invoice', 'credit_note', 'pos_order', 'sales_order']);
            $this->assertIsInt($doc['document_id']);
        }

        return $documents;
    }

    private static function keyOf(mixed $id): string
    {
        return $id === null ? 'none' : (string) $id;
    }

    // ── Panel total equals the row, for every row of every tab ───────────

    public function test_every_customer_row_drills_to_documents_that_total_the_row(): void
    {
        $rows = $this->rows('by-customer', 'customers');
        $this->assertCount(3, $rows, 'Hospital, clinic and walk-in');

        foreach ($rows as $row) {
            $this->assertDrillEqualsRow('customer', self::keyOf($row['id']), (float) $row['total_revenue']);
        }

        // The hospital's documents are its invoice and its credit note, by number.
        $hospital = $this->assertDrillEqualsRow(
            'customer',
            (string) $this->hospital->id,
            (float) $rows->firstWhere('id', $this->hospital->id)['total_revenue'],
        );
        $this->assertEqualsCanonicalizing(
            [$this->invoice->invoice_number, $this->creditNote->credit_note_number],
            $hospital->pluck('document_number')->all(),
        );
        $this->assertLessThan(0, (float) $hospital->firstWhere('document_type', 'credit_note')['net_amount']);

        // The walk-in row is the POS sale.
        $walkIn = $this->assertDrillEqualsRow('customer', 'none', 120.0);
        $this->assertSame(['pos_order'], $walkIn->pluck('document_type')->all());
        $this->assertSame($this->posSale->id, $walkIn->first()['document_id']);
    }

    public function test_every_product_row_drills_to_documents_that_total_the_row(): void
    {
        $rows = $this->rows('by-product', 'products');
        $this->assertGreaterThanOrEqual(2, $rows->count());

        foreach ($rows as $row) {
            $this->assertDrillEqualsRow('product', self::keyOf($row['id']), (float) $row['total_revenue']);
        }

        // The implant appears on all four source documents.
        $implant = $this->assertDrillEqualsRow(
            'product',
            (string) $this->implant->id,
            (float) $rows->firstWhere('id', $this->implant->id)['total_revenue'],
        );
        $this->assertEqualsCanonicalizing(
            ['invoice', 'credit_note', 'pos_order', 'sales_order'],
            $implant->pluck('document_type')->all(),
        );
        $this->assertContains($this->booking->sales_order_number, $implant->pluck('document_number')->all());
    }

    public function test_every_category_row_drills_to_documents_that_total_the_row(): void
    {
        $rows = $this->rows('by-category', 'categories');
        $this->assertCount(2, $rows, 'Implants and Uncategorized');

        foreach ($rows as $row) {
            $this->assertDrillEqualsRow('category', self::keyOf($row['category_id']), (float) $row['revenue']);
        }
    }

    public function test_every_period_row_drills_to_documents_that_total_the_row(): void
    {
        foreach (['day', 'week', 'month'] as $interval) {
            $rows = $this->rows('trends', 'trends', ['interval' => $interval]);
            $this->assertNotEmpty($rows);

            foreach ($rows as $row) {
                $this->assertDrillEqualsRow('period', (string) $row['period'], (float) $row['revenue'], ['interval' => $interval]);
            }
        }
    }

    // ── The drill honours the same #593 filters as the row ───────────────

    public function test_drill_down_applies_the_same_filters_as_the_row(): void
    {
        $filters = ['channel' => 'pos'];
        $rows = $this->rows('by-product', 'products', $filters);
        $implantRow = $rows->firstWhere('id', $this->implant->id);
        $this->assertSame(120.0, (float) $implantRow['total_revenue'], 'Fixture: POS-only implant revenue');

        $documents = $this->assertDrillEqualsRow('product', (string) $this->implant->id, 120.0, $filters);
        $this->assertSame(['pos_order'], $documents->pluck('document_type')->all());

        $customerFiltered = ['customer_id' => $this->clinic->id];
        $categoryRow = $this->rows('by-category', 'categories', $customerFiltered)
            ->firstWhere('category_id', $this->implants->id);
        $this->assertDrillEqualsRow('category', (string) $this->implants->id, (float) $categoryRow['revenue'], $customerFiltered);
    }

    // ── Bad input is rejected, never silently widened ─────────────────────

    public function test_invalid_dimension_or_key_is_rejected(): void
    {
        $this->drill('warehouse', '1')->assertStatus(422);
        $this->drill('customer', 'abc')->assertStatus(422);
        $this->drill('period', '2026-13-99', ['interval' => 'day'])->assertStatus(422);
        $this->drill('customer', '1', ['bogus' => 'x'])->assertStatus(422);
    }
}
