<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Platform\SystemLog\SystemLog;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #895 (SALES-PLAT / PC1): Sales documents had no tamper-evident audit
 * trail — the shared AuditTrailObserver was never attached to invoice /
 * quotation / sales-order / delivery-order / payment / credit-note / customer
 * models, and the invoice/quotation lifecycle actions never called AuditLogger.
 *
 * These tests run through the real HTTP routes. They assert the business rule:
 * a price change is recorded with its old and new value and the user who made
 * it, lifecycle transitions are explicit events, and audit rows are
 * append-only.
 */
class Issue895SalesAuditTrailTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            [
                'name' => 'Issue 895 Audit Tenant',
                'email' => 'audit895-'.substr(md5($this->tenantId), 0, 8).'@example.com',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->bindTenantContextWithPermissions(
            $this->tenantId,
            null,
            UserRole::ADMIN->spatieName(),
            autoAssignRole: false,
        );
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => UserRole::ADMIN->value, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole(UserRole::ADMIN->spatieName());

        return $user;
    }

    private function createDraftInvoice(User $admin, float $unitPrice = 500, ?string $correlationId = null): Invoice
    {
        $customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 100000]);
        $product = Product::factory()->create([
            'name' => 'Audited Widget',
            'price' => $unitPrice,
            'track_inventory' => false,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 2,
                'unit_price' => $unitPrice,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ], $correlationId !== null ? ['X-Correlation-ID' => $correlationId] : []);

        $response->assertCreated();

        return Invoice::query()->with('lines')->findOrFail($response->json('id'));
    }

    public function test_sales_models_are_observed_and_record_field_level_before_after_with_actor(): void
    {
        $admin = $this->actingAsAdmin();
        $customer = Customer::factory()->create(['name' => 'Before Name']);

        $this->actingAs($admin);
        $customer->update(['name' => 'After Name']);

        $log = SystemLog::query()
            ->where('category', 'audit.sales.updated')
            ->where('context->table', 'customers')
            ->where('context->model_id', $customer->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull(
            $log,
            'The Sales module provider must register the shared AuditTrailObserver on Sales models.'
        );
        $this->assertSame('Before Name', $log->context['before']['name'] ?? null, 'The audit trail must keep the old field value.');
        $this->assertSame('After Name', $log->context['after']['name'] ?? null, 'The audit trail must keep the new field value.');
        $this->assertSame((int) $admin->id, (int) $log->user_id, 'The audit trail must name the acting user.');
        $this->assertNotNull($log->created_at, 'The audit trail must carry a timestamp.');
    }

    public function test_invoice_line_price_change_is_audited_with_old_and_new_values_and_the_user(): void
    {
        $admin = $this->actingAsAdmin();
        $createCorrelationId = 'issue895-invoice-create';
        $invoice = $this->createDraftInvoice($admin, 500, $createCorrelationId);

        // Field-level audit of the LINE itself through the shared observer:
        // the created row must carry the price, the actor and the request id.
        $lineLog = SystemLog::query()
            ->where('category', 'audit.sales.created')
            ->where('context->table', 'invoice_lines')
            ->latest('created_at')
            ->first();

        $this->assertNotNull(
            $lineLog,
            'The Sales module provider must register the shared AuditTrailObserver on invoice lines.'
        );
        $this->assertSame(
            500.0,
            (float) ($lineLog->context['after']['unit_price'] ?? 0),
            'The line audit must carry the field value, not just the id.'
        );
        $this->assertSame((int) $admin->id, (int) ($lineLog->context['actor_id'] ?? 0), 'The line audit must name the acting user.');
        $this->assertSame($createCorrelationId, $lineLog->context['request_id'] ?? null, 'The line audit must carry the request id.');

        $line = $invoice->lines->first();
        $oldPrice = (float) $line->unit_price;

        $updateCorrelationId = 'issue895-invoice-update';
        $response = $this->actingAs($admin)->putJson("/api/v1/invoices/{$invoice->id}", [
            'customer_id' => $invoice->customer_id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => 2,
                'unit_price' => 875,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ], ['X-Correlation-ID' => $updateCorrelationId]);
        $response->assertOk();

        $log = AuditLog::query()
            ->where('operation', 'invoice_updated')
            ->where('reference_type', 'invoice')
            ->where('reference_id', $invoice->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'Changing an invoice line price must write an audit row.');
        $this->assertSame((int) $admin->id, (int) $log->actor_id, 'The audit row must name the user who changed the price.');
        $this->assertSame(
            $oldPrice,
            (float) $log->before_state['lines'][0]['unit_price'],
            'The audit row must keep the old line price.'
        );
        $this->assertSame(
            875.0,
            (float) $log->after_state['lines'][0]['unit_price'],
            'The audit row must keep the new line price.'
        );
        $this->assertNotNull($log->created_at, 'The audit row must carry a timestamp.');
        $this->assertSame(
            $updateCorrelationId,
            $log->metadata['request_id'] ?? null,
            'The audit row must carry the request id.'
        );
    }

    public function test_invoice_lifecycle_post_cancel_and_reset_are_explicit_audit_events(): void
    {
        $admin = $this->actingAsAdmin();
        $invoice = $this->createDraftInvoice($admin);

        $this->actingAs($admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/send")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'invoice_posted',
            'reference_type' => 'invoice',
            'reference_id' => $invoice->id,
            'actor_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'invoice_cancelled',
            'reference_type' => 'invoice',
            'reference_id' => $invoice->id,
            'actor_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/reset-to-draft")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'invoice_reset_to_draft',
            'reference_type' => 'invoice',
            'reference_id' => $invoice->id,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_sales_order_cancel_writes_explicit_lifecycle_audit(): void
    {
        $this->mockFeatureGate(true);
        $admin = $this->actingAsAdmin();
        $customer = Customer::factory()->create();

        $create = $this->actingAs($admin)->postJson('/api/v1/sales/orders', [
            'customer_id' => $customer->id,
            'channel' => 'admin',
            'lines' => [[
                'description' => 'Audited order line',
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ]);
        $create->assertCreated();
        $orderId = (int) $create->json('id');

        $this->actingAs($admin)
            ->postJson("/api/v1/sales/orders/{$orderId}/cancel", ['reason' => 'Audit test cancellation'])
            ->assertOk();

        $log = AuditLog::query()
            ->where('operation', 'sales_order_cancelled')
            ->where('reference_type', 'sales_order')
            ->where('reference_id', $orderId)
            ->first();

        $this->assertNotNull($log, 'Cancelling a sales order must write an explicit lifecycle audit row.');
        $this->assertSame((int) $admin->id, (int) $log->actor_id);
        $this->assertSame('cancelled', $log->after_state['status']);
    }

    public function test_payment_lifecycle_events_are_explicit_audit_rows(): void
    {
        $admin = $this->actingAsAdmin();
        $invoice = $this->createDraftInvoice($admin, 500); // total 1000, balance 1000

        // Record a draft payment through the real route (the Payment model is
        // observed, so its create/update rows land in the shared audit trail).
        $first = $this->actingAs($admin)->postJson('/api/v1/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 300,
            'method' => 'cash',
        ]);
        $first->assertCreated();
        $firstId = (int) $first->json('id');

        $this->actingAs($admin)
            ->postJson("/api/v1/payments/{$firstId}/post")
            ->assertOk();

        $postedLog = AuditLog::query()
            ->where('operation', 'payment_posted')
            ->where('reference_type', 'payment')
            ->where('reference_id', $firstId)
            ->first();

        $this->assertNotNull($postedLog, 'Posting a payment must write an explicit lifecycle audit row.');
        $this->assertSame((int) $admin->id, (int) $postedLog->actor_id, 'The audit row must name the user who posted it.');
        $this->assertNotNull($postedLog->after_state['journal_entry_id'] ?? null, 'The audit row must record the ledger link it created.');

        // Bulk post: the same explicit "posted" event per payment.
        $second = $this->actingAs($admin)->postJson('/api/v1/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 200,
            'method' => 'cash',
        ]);
        $second->assertCreated();
        $secondId = (int) $second->json('id');

        $this->actingAs($admin)
            ->postJson('/api/v1/payments/bulk-post', ['ids' => [$secondId]])
            ->assertOk();

        $bulkLog = AuditLog::query()
            ->where('operation', 'payment_posted')
            ->where('reference_type', 'payment')
            ->where('reference_id', $secondId)
            ->first();

        $this->assertNotNull($bulkLog, 'Bulk posting a payment must write an explicit lifecycle audit row too.');
        $this->assertSame((int) $admin->id, (int) $bulkLog->actor_id);
        $this->assertTrue((bool) ($bulkLog->metadata['bulk'] ?? false), 'The audit row must record that it came from the bulk action.');

        // Cancel a draft payment through the real route.
        $third = $this->actingAs($admin)->postJson('/api/v1/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'method' => 'cash',
        ]);
        $third->assertCreated();
        $thirdId = (int) $third->json('id');

        $this->actingAs($admin)
            ->postJson("/api/v1/payments/{$thirdId}/cancel")
            ->assertOk();

        $cancelledLog = AuditLog::query()
            ->where('operation', 'payment_cancelled')
            ->where('reference_type', 'payment')
            ->where('reference_id', $thirdId)
            ->first();

        $this->assertNotNull($cancelledLog, 'Cancelling a payment must write an explicit lifecycle audit row.');
        $this->assertSame((int) $admin->id, (int) $cancelledLog->actor_id);
        $this->assertSame(100.0, (float) ($cancelledLog->before_state['amount'] ?? 0), 'The audit row must keep the old amount.');
    }

    public function test_audit_rows_are_append_only(): void
    {
        $log = AuditLog::create([
            'operation' => 'issue895_append_only_probe',
            'reference_type' => 'invoice',
            'reference_id' => 1,
            'actor_id' => null,
        ]);

        try {
            $log->update(['operation' => 'tampered']);
            $this->fail('Audit rows must not be updatable.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            AuditLog::findOrFail($log->id)->delete();
            $this->fail('Audit rows must not be deletable.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(function (string $tenantId, string $key) use ($isEntitled) {
                return [
                    'key' => $key,
                    'is_entitled' => $isEntitled,
                    'source' => $isEntitled ? 'tier_included' : null,
                    'name' => strtoupper($key),
                ];
            });

        $mock->shouldReceive('all')
            ->andReturn($isEntitled ? [['key' => 'sales', 'source' => 'tier_included', 'name' => 'SALES']] : []);

        $mock->shouldReceive('invalidate')->andReturnNull();

        app()->instance(FeatureGateService::class, $mock);
    }
}
