<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Services\AccountMappingService;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #683 [SALES-INV] P1 (IN3) — a direct invoice billed the line's lot and
 * serial but posting deducted stock through the generic stock path without
 * them: the selected lot stayed on hand (batch stock drifted), COGS was costed
 * from whichever FEFO layer came first (not the lot sold), and the serial was
 * never marked sold, so it could be sold again.
 *
 * Every test below drives the REAL route (router -> middleware -> controller):
 * `POST /api/v1/invoices` then `POST /api/v1/invoices/{id}/post`. The business
 * rules asserted are the issue's: the line's lot is the one consumed, COGS is
 * the cost of that lot, the serial is marked sold, and a second sale of the
 * same serial is refused.
 */
class Issue683InvoiceLotSerialIssueTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    private ProductBatch $fefoLot;

    private ProductBatch $soldLot;

    private ProductSerial $serial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Invoice Lot Serial Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        // Posting needs an open accounting period.
        FiscalPeriod::firstOrCreate(
            ['code' => now()->format('Y-m')],
            [
                'name' => now()->format('F Y'),
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $this->admin->id,
            ]
        );

        $this->customer = Customer::factory()->create(['payment_terms' => 30]);

        $this->warehouse = Warehouse::create([
            'name' => 'Lot Serial Warehouse',
            'code' => 'LSW-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Lot Serial Widget',
            'sku' => 'LSW-'.uniqid(),
            'track_inventory' => true,
            'tracking_method' => Product::TRACKING_SERIAL,
            'cost' => 50.00,
            'price' => 100,
            'stock_quantity' => 0,
            'warranty_months' => 12,
        ]);

        StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 15,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        // FEFO would pick this earlier-expiry layer — but the invoice line
        // names the OTHER lot, and it is the line's lot that must leave.
        $this->fefoLot = ProductBatch::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => 'LOT-FEFO-'.Str::upper(Str::random(4)),
            'lot_number' => 'LOT-FEFO-'.Str::upper(Str::random(4)),
            'quantity' => 10,
            'quantity_reserved' => 0,
            'cost' => 9.00,
            'expiry_date' => now()->addMonth()->toDateString(),
            'received_date' => now()->subMonth()->toDateString(),
            'status' => ProductBatch::STATUS_AVAILABLE,
        ]);

        $this->soldLot = ProductBatch::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => 'LOT-SOLD-'.Str::upper(Str::random(4)),
            'lot_number' => 'LOT-SOLD-'.Str::upper(Str::random(4)),
            'quantity' => 5,
            'quantity_reserved' => 0,
            'cost' => 7.00,
            'expiry_date' => now()->addYear()->toDateString(),
            'received_date' => now()->subWeek()->toDateString(),
            'status' => ProductBatch::STATUS_AVAILABLE,
        ]);

        $this->serial = ProductSerial::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_id' => $this->soldLot->id,
            'serial_number' => 'SN-683-'.Str::upper(Str::random(6)),
            'status' => ProductSerial::STATUS_AVAILABLE,
            'cost' => 7.00,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')->andReturnUsing(fn (string $tenantId, string $key) => [
            'key' => $key,
            'is_entitled' => $isEntitled,
            'source' => $isEntitled ? 'tier_included' : null,
            'name' => strtoupper($key),
        ]);

        $this->app->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    private function apiPost(string $uri, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson($uri, $payload);
    }

    /**
     * Create a direct (invoice-form) draft invoice for one unit naming the
     * line's lot + serial, exactly as the invoice form sends them.
     */
    private function draftDirectInvoice(): int
    {
        $response = $this->apiPost('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
                'lot_number' => $this->soldLot->lot_number,
                'serial_number' => $this->serial->serial_number,
                'product_batch_id' => $this->soldLot->id,
                'product_serial_id' => $this->serial->id,
                'expiry_date' => $this->soldLot->expiry_date?->toDateString(),
            ]],
        ]);
        $response->assertCreated();

        return (int) $response->json('id');
    }

    private function postInvoice(int $invoiceId): \Illuminate\Testing\TestResponse
    {
        return $this->apiPost("/api/v1/invoices/{$invoiceId}/post");
    }

    private function stockOnHand(): float
    {
        return (float) StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('quantity');
    }

    /** COGS recognised on the entry posted by one specific source document. */
    private function cogsForDocument(string $sourceDocumentType, int $sourceDocumentId): float
    {
        $cogsAccountId = app(AccountMappingService::class)->resolveId('cogs');

        $entryIds = \App\Models\JournalEntryLink::where('source_document_type', $sourceDocumentType)
            ->where('source_document_id', $sourceDocumentId)
            ->where('relation_type', 'post')
            ->pluck('journal_entry_id');

        return round((float) JournalEntryLine::whereIn('journal_entry_id', $entryIds)
            ->where('account_id', $cogsAccountId)
            ->sum('debit'), 2);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // The core rule: posting consumes the line's lot + serial
    // ═══════════════════════════════════════════════════════════════════════

    public function test_direct_invoice_posting_consumes_the_lines_lot_serial_and_costs(): void
    {
        $invoiceId = $this->draftDirectInvoice();

        $this->postInvoice($invoiceId)->assertOk();

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->assertSame('posted', $invoice->status, 'Fixture: the invoice posted');

        // The line's lot left the warehouse; the FEFO-first lot did not.
        $this->assertSame(
            4,
            (int) $this->soldLot->fresh()->quantity,
            'The lot named on the invoice line must be decremented'
        );
        $this->assertSame(
            10,
            (int) $this->fefoLot->fresh()->quantity,
            'A lot the invoice line did not name must not be consumed'
        );
        $this->assertSame(14.0, $this->stockOnHand(), 'One unit of the line lot left the warehouse');

        // The serial belongs to the customer now and cannot be sold again.
        $this->serial->refresh();
        $this->assertSame(ProductSerial::STATUS_SOLD, $this->serial->status, 'The invoice must mark the serial sold');
        $this->assertSame($this->customer->id, (int) $this->serial->customer_id);
        $this->assertSame($invoice->invoice_number, $this->serial->sale_reference);
        $this->assertNotNull($this->serial->sold_at);

        // COGS is the cost of the lot that actually left — not the FEFO layer.
        $this->assertSame(
            7.00,
            $this->cogsForDocument('invoice', $invoice->id),
            'COGS must be costed from the lot the invoice issued (1 x 7.00), not FEFO (1 x 9.00)'
        );

        // The stock movement carries the line's lot + expiry for traceability.
        $movement = StockMovement::where('reference_type', 'Invoice')
            ->where('reference_id', $invoice->id)
            ->where('type', 'sale')
            ->first();

        $this->assertNotNull($movement, 'Posting must record a sale movement for the invoice');
        $this->assertSame($this->soldLot->lot_number, $movement->batch_number);
        $this->assertSame(
            $this->soldLot->expiry_date?->toDateString(),
            $movement->expiry_date?->toDateString()
        );
    }

    public function test_a_second_sale_of_the_same_serial_is_refused(): void
    {
        $firstInvoiceId = $this->draftDirectInvoice();
        $this->postInvoice($firstInvoiceId)->assertOk();

        $secondInvoiceId = $this->draftDirectInvoice();
        $response = $this->postInvoice($secondInvoiceId);

        // The serial is no longer available, so the second posting must fail
        // and leave no partial effect behind.
        $response->assertStatus(422);

        $second = Invoice::query()->findOrFail($secondInvoiceId);
        $this->assertSame('draft', $second->status, 'The refused invoice must stay draft');
        $this->assertNull($second->journal_entry_id, 'The refused invoice must book no journal entry');

        $this->serial->refresh();
        $this->assertSame(ProductSerial::STATUS_SOLD, $this->serial->status);
        $this->assertSame(
            4,
            (int) $this->soldLot->fresh()->quantity,
            'The refused sale must not consume another unit of the lot'
        );
        $this->assertSame(14.0, $this->stockOnHand(), 'The refused sale must not move stock');

        $this->assertSame(
            0.0,
            (float) StockMovement::where('reference_type', 'Invoice')
                ->where('reference_id', $secondInvoiceId)
                ->where('type', 'sale')
                ->sum('quantity'),
            'The refused sale must record no stock movement'
        );
    }
}
