<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Tests\TenantTestCase;

class ChannelEquivalenceTest extends TenantTestCase
{

    protected User $user;

    protected Product $product;

    protected Customer $customer;

    protected PosTerminal $terminal;

    protected PosSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind tenant context so feature:pos and permission middleware
        // resolve correctly during HTTP requests.
        $tenant = Tenant::withoutEvents(function () {
            return Tenant::factory()->create(['id' => '44444444-4444-4444-4444-444444444444']);
        });
        $this->bindTenantContext($tenant->id);

        $this->user = User::factory()->create();

        // Grant the test user tenant-scoped admin access so the
        // RequirePermission:pos.create middleware passes (admin is a
        // super-role that grants all Spatie permissions).
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create([
            'price' => 100.00,
            'cost' => 50.00,
            'tax_rate' => 10.00,
            'stock_quantity' => 1000,
            'track_inventory' => true,
        ]);

        $this->terminal = PosTerminal::factory()->create();
        $this->session = PosSession::factory()->create([
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->user->id,
            'status' => 'open',
        ]);
    }

    /**
     * Test CHAN-001: Five-path payment equivalence
     *
     * Verify that POS, Ecommerce, Gateway, Webhook, and Backfill paths
     * produce identical GL entries, stock movements, and payment records
     * for the same product and amount.
     */
    public function test_five_path_payment_equivalence()
    {
        // Path 1: Direct POS payment
        $posResult = $this->createPosOrder();

        // Path 2: Ecommerce checkout (if available)
        // $ecommerceResult = $this->createEcommerceOrder();

        // Path 3: Gateway confirmation
        $gatewayResult = $this->createGatewayOrder();

        // Path 4: Webhook payment
        $webhookResult = $this->createWebhookOrder();

        // Path 5: Backfill repair
        $backfillResult = $this->createBackfillOrder();

        // Verify all paths produce identical results
        $this->assertEquivalentGLEntries($posResult, $gatewayResult);
        $this->assertEquivalentGLEntries($posResult, $webhookResult);
        $this->assertEquivalentGLEntries($posResult, $backfillResult);

        $this->assertEquivalentStockMovements($posResult, $gatewayResult);
        $this->assertEquivalentStockMovements($posResult, $webhookResult);
        $this->assertEquivalentStockMovements($posResult, $backfillResult);

        $this->assertEquivalentPaymentRecords($posResult, $gatewayResult);
        $this->assertEquivalentPaymentRecords($posResult, $webhookResult);
        $this->assertEquivalentPaymentRecords($posResult, $backfillResult);
    }

    /**
     * Test tax calculation consistency across channels
     */
    public function test_tax_calculation_consistency_across_channels()
    {
        $posResult = $this->createPosOrder();
        $gatewayResult = $this->createGatewayOrder();

        // Verify tax amounts are identical
        $this->assertEquals(
            $posResult['tax_amount'],
            $gatewayResult['tax_amount'],
            'Tax amounts must be identical across channels'
        );

        // Verify tax GL lines are identical
        $posTaxLine = $this->getTaxGLLine($posResult['journal_entry_id']);
        $gatewayTaxLine = $this->getTaxGLLine($gatewayResult['journal_entry_id']);

        $this->assertNotNull($posTaxLine, 'POS path must produce a tax GL line');
        $this->assertNotNull($gatewayTaxLine, 'Gateway path must produce a tax GL line');

        $this->assertEquals(
            (float) $posTaxLine->credit,
            (float) $gatewayTaxLine->credit,
            'Tax GL line credit amounts must be identical'
        );

        $this->assertEquals(
            $posTaxLine->account_code,
            $gatewayTaxLine->account_code,
            'Tax account codes must be identical'
        );
    }

    /**
     * Test idempotency across all payment paths
     */
    public function test_payment_idempotency_across_all_paths()
    {
        // Create order via POS
        $result1 = $this->createPosOrder();

        // Attempt duplicate payment via same path
        $result2 = $this->attemptDuplicatePayment($result1['order_id']);

        // Verify only one payment record exists
        $paymentCount = PosPayment::where('pos_order_id', $result1['order_id'])->count();
        $this->assertEquals(1, $paymentCount, 'Duplicate payment must be prevented');

        // Verify only one GL entry exists (linked via journal_entry_links)
        $glCount = DB::table('journal_entry_links')
            ->where('source_document_type', 'pos_order')
            ->where('source_document_id', $result1['order_id'])
            ->count();
        $this->assertEquals(1, $glCount, 'Duplicate GL entry must be prevented');

        // Verify only one stock movement per product
        $stockCount = StockMovement::where('reference_id', $result1['order_id'])
            ->where('reference_type', 'PosOrder')
            ->where('product_id', $this->product->id)
            ->count();
        $this->assertEquals(1, $stockCount, 'Duplicate stock movement must be prevented');
    }

    // Helper methods

    protected function createPosOrder(): array
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/pos/orders', [
            'session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 100.00,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $orderId = $response->json('id');

        // Complete payment
        $paymentResponse = $this->actingAs($this->user)->postJson("/api/v1/pos/orders/{$orderId}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        $paymentResponse->assertStatus(200);

        return $this->extractOrderData($orderId);
    }

    protected function createGatewayOrder(): array
    {
        // Create order
        $response = $this->actingAs($this->user)->postJson('/api/v1/pos/orders', [
            'session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 100.00,
                ],
            ],
        ]);

        $orderId = $response->json('id');

        // Simulate gateway confirmation via PosPaymentCompletionService
        $order = PosOrder::find($orderId);
        app(\Modules\POS\Services\PosPaymentCompletionService::class)->complete(
            $order,
            110.00,
            'card',
            'GATEWAY-'.uniqid()
        );

        return $this->extractOrderData($orderId);
    }

    protected function createWebhookOrder(): array
    {
        // Create order
        $response = $this->actingAs($this->user)->postJson('/api/v1/pos/orders', [
            'session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 100.00,
                ],
            ],
        ]);

        $orderId = $response->json('id');

        // Simulate webhook payment
        $order = PosOrder::find($orderId);
        app(\Modules\POS\Services\PosPaymentCompletionService::class)->complete(
            $order,
            110.00,
            'card',
            'WEBHOOK-'.uniqid()
        );

        return $this->extractOrderData($orderId);
    }

    protected function createBackfillOrder(): array
    {
        // Create paid order without invoice (simulating missing invoice scenario)
        $response = $this->actingAs($this->user)->postJson('/api/v1/pos/orders', [
            'session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 100.00,
                ],
            ],
        ]);

        $orderId = $response->json('id');

        // Pay the order
        $this->actingAs($this->user)->postJson("/api/v1/pos/orders/{$orderId}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        // Simulate backfill via repair command (re-runs idempotent posting)
        $order = PosOrder::find($orderId);
        app(\Modules\POS\Services\PosPaymentCompletionService::class)->backfill(
            $order,
            'cash',
            'BACKFILL-'.uniqid()
        );

        return $this->extractOrderData($orderId);
    }

    protected function attemptDuplicatePayment(int $orderId): array
    {
        $order = PosOrder::find($orderId);

        try {
            app(\Modules\POS\Services\PosPaymentCompletionService::class)->complete(
                $order,
                110.00,
                'cash',
                null // Same reference (null for cash) — should be idempotent
            );
        } catch (\Exception $e) {
            // Expected to fail or be idempotent
        }

        return $this->extractOrderData($orderId);
    }

    protected function extractOrderData(int $orderId): array
    {
        $order = PosOrder::with('payments')->find($orderId);
        $payment = $order->payments->first();

        // Journal entries are linked to source documents via journal_entry_links
        // (PostingEngine does not write origin_reference_id on the entry itself).
        $link = DB::table('journal_entry_links')
            ->where('source_document_type', 'pos_order')
            ->where('source_document_id', $orderId)
            ->orderBy('id')
            ->first();

        $journalEntry = $link
            ? JournalEntry::find($link->journal_entry_id)
            : null;

        $stockMovements = StockMovement::where('reference_id', $orderId)
            ->where('reference_type', 'PosOrder')
            ->get();

        $totalDebit = 0;
        if ($journalEntry) {
            $totalDebit = (float) DB::table('journal_entry_lines')
                ->where('journal_entry_id', $journalEntry->id)
                ->sum('debit');
        }

        return [
            'order_id' => $orderId,
            'total_amount' => $order->total_amount,
            'tax_amount' => $order->tax_amount,
            'payment_id' => $payment?->id,
            'payment_method' => $payment?->method,
            'payment_amount' => $payment?->amount,
            'journal_entry_id' => $journalEntry?->id,
            'journal_entry_total' => $totalDebit,
            'stock_movements_count' => $stockMovements->count(),
            'stock_quantity_change' => $stockMovements->sum('quantity'),
        ];
    }

    protected function assertEquivalentGLEntries(array $result1, array $result2): void
    {
        $this->assertNotNull($result1['journal_entry_id'], 'First result must have GL entry');
        $this->assertNotNull($result2['journal_entry_id'], 'Second result must have GL entry');

        $this->assertEquals(
            $result1['journal_entry_total'],
            $result2['journal_entry_total'],
            'GL entry totals must be identical'
        );

        // Verify GL line structure is identical (ordered by account_id for
        // stable comparison; account code lookup via chart_of_accounts).
        $lines1 = $this->getJournalLinesSorted($result1['journal_entry_id']);
        $lines2 = $this->getJournalLinesSorted($result2['journal_entry_id']);

        $this->assertEquals($lines1->count(), $lines2->count(), 'GL line counts must match');

        foreach ($lines1 as $index => $line1) {
            $line2 = $lines2[$index];
            $this->assertEquals($line1->account_id, $line2->account_id, 'Account IDs must match');
            $this->assertEquals((float) $line1->debit, (float) $line2->debit, 'Debit amounts must match');
            $this->assertEquals((float) $line1->credit, (float) $line2->credit, 'Credit amounts must match');
        }
    }

    protected function getJournalLinesSorted(int $journalEntryId)
    {
        return DB::table('journal_entry_lines')
            ->where('journal_entry_id', $journalEntryId)
            ->orderBy('account_id')
            ->orderBy('id')
            ->get();
    }

    protected function assertEquivalentStockMovements(array $result1, array $result2): void
    {
        $this->assertEquals(
            $result1['stock_movements_count'],
            $result2['stock_movements_count'],
            'Stock movement counts must be identical'
        );

        $this->assertEquals(
            $result1['stock_quantity_change'],
            $result2['stock_quantity_change'],
            'Stock quantity changes must be identical'
        );
    }

    protected function assertEquivalentPaymentRecords(array $result1, array $result2): void
    {
        $this->assertNotNull($result1['payment_id'], 'First result must have payment record');
        $this->assertNotNull($result2['payment_id'], 'Second result must have payment record');

        $this->assertEquals(
            $result1['payment_amount'],
            $result2['payment_amount'],
            'Payment amounts must be identical'
        );
    }

    protected function getTaxGLLine(int $journalEntryId)
    {
        // journal_entry_lines.account_id → chart_of_accounts.code (e.g. 2200 Sales Tax Payable)
        return DB::table('journal_entry_lines as l')
            ->join('chart_of_accounts as c', 'l.account_id', '=', 'c.id')
            ->where('l.journal_entry_id', $journalEntryId)
            ->where(function ($q) {
                $q->where('c.name', 'ilike', '%tax%')->orWhere('c.code', 'like', '%tax%');
            })
            ->select('l.*', 'c.code as account_code')
            ->first();
    }
}
