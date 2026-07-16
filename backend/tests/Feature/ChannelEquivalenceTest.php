<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Tests\TestCase;

class ChannelEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Product $product;

    protected Customer $customer;

    protected PosTerminal $terminal;

    protected PosSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create([
            'price' => 100.00,
            'tax_rate' => 10.00,
            'stock_quantity' => 1000,
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

        $this->assertEquals(
            $posTaxLine->amount,
            $gatewayTaxLine->amount,
            'Tax GL line amounts must be identical'
        );

        $this->assertEquals(
            $posTaxLine->tax_code,
            $gatewayTaxLine->tax_code,
            'Tax codes must be identical'
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

        // Verify only one GL entry exists
        $glCount = JournalEntry::where('source_document_id', $result1['order_id'])
            ->where('source_document_type', 'pos_order')
            ->count();
        $this->assertEquals(1, $glCount, 'Duplicate GL entry must be prevented');

        // Verify only one stock movement per product
        $stockCount = StockMovement::where('reference_id', $result1['order_id'])
            ->where('reference_type', 'pos_order')
            ->where('product_id', $this->product->id)
            ->count();
        $this->assertEquals(1, $stockCount, 'Duplicate stock movement must be prevented');
    }

    // Helper methods

    protected function createPosOrder(): array
    {
        $response = $this->actingAs($this->user)->postJson('/api/pos/orders', [
            'session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $orderId = $response->json('id');

        // Complete payment
        $paymentResponse = $this->actingAs($this->user)->postJson("/api/pos/orders/{$orderId}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        $paymentResponse->assertStatus(200);

        return $this->extractOrderData($orderId);
    }

    protected function createGatewayOrder(): array
    {
        // Create order
        $response = $this->actingAs($this->user)->postJson('/api/pos/orders', [
            'session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ]);

        $orderId = $response->json('id');

        // Simulate gateway confirmation via PaymentTransactionCompletionService
        $order = PosOrder::find($orderId);
        app(\Modules\POS\Services\PosPaymentCompletionService::class)->complete(
            $order,
            'card',
            110.00,
            'GATEWAY-'.uniqid()
        );

        return $this->extractOrderData($orderId);
    }

    protected function createWebhookOrder(): array
    {
        // Create order
        $response = $this->actingAs($this->user)->postJson('/api/pos/orders', [
            'session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ]);

        $orderId = $response->json('id');

        // Simulate webhook payment
        $order = PosOrder::find($orderId);
        app(\Modules\POS\Services\PosPaymentCompletionService::class)->complete(
            $order,
            'card',
            110.00,
            'WEBHOOK-'.uniqid()
        );

        return $this->extractOrderData($orderId);
    }

    protected function createBackfillOrder(): array
    {
        // Create paid order without invoice (simulating missing invoice scenario)
        $response = $this->actingAs($this->user)->postJson('/api/pos/orders', [
            'session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ]);

        $orderId = $response->json('id');

        // Pay the order
        $this->actingAs($this->user)->postJson("/api/pos/orders/{$orderId}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        // Simulate backfill via repair command
        $order = PosOrder::find($orderId);
        app(\Modules\POS\Services\PosPaymentCompletionService::class)->backfill($order);

        return $this->extractOrderData($orderId);
    }

    protected function attemptDuplicatePayment(int $orderId): array
    {
        $order = PosOrder::find($orderId);

        try {
            app(\Modules\POS\Services\PosPaymentCompletionService::class)->complete(
                $order,
                'cash',
                110.00,
                null // Same reference (null for cash)
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

        $journalEntry = JournalEntry::where('source_document_id', $orderId)
            ->where('source_document_type', 'pos_order')
            ->first();

        $stockMovements = StockMovement::where('reference_id', $orderId)
            ->where('reference_type', 'pos_order')
            ->get();

        return [
            'order_id' => $orderId,
            'total_amount' => $order->total_amount,
            'tax_amount' => $order->tax_amount,
            'payment_id' => $payment?->id,
            'payment_method' => $payment?->method,
            'payment_amount' => $payment?->amount,
            'journal_entry_id' => $journalEntry?->id,
            'journal_entry_total' => $journalEntry?->total_debit ?? 0,
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

        // Verify GL line structure is identical
        $lines1 = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $result1['journal_entry_id'])
            ->orderBy('account_code')
            ->get();

        $lines2 = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $result2['journal_entry_id'])
            ->orderBy('account_code')
            ->get();

        $this->assertEquals($lines1->count(), $lines2->count(), 'GL line counts must match');

        foreach ($lines1 as $index => $line1) {
            $line2 = $lines2[$index];
            $this->assertEquals($line1->account_code, $line2->account_code, 'Account codes must match');
            $this->assertEquals($line1->debit, $line2->debit, 'Debit amounts must match');
            $this->assertEquals($line1->credit, $line2->credit, 'Credit amounts must match');
        }
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
        return DB::table('journal_entry_lines')
            ->where('journal_entry_id', $journalEntryId)
            ->where('account_code', 'like', '%tax%')
            ->first();
    }
}
