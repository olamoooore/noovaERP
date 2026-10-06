<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Settlement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * ACC-005B producer: a posted customer receipt must be recognised as a
 * Settlement so /accounting/settlements is not permanently empty, and the
 * settlement must follow the receipt through allocation and reversal.
 *
 * Exercises the real router/middleware/controller chain via the HTTP API.
 */
class SettlementRecognitionTest extends TenantTestCase
{
    protected User $admin;

    protected Customer $customer;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create(['payment_terms' => 30]);
        $this->seedPostingFixtures($this->admin->id);
    }

    private function seedPostingFixtures(int $userId): void
    {
        $code = now()->format('Y-m');
        if (! DB::table('fiscal_periods')->where('code', $code)->exists()) {
            DB::table('fiscal_periods')->insert([
                'name' => now()->format('F Y'),
                'code' => $code,
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['cash' => '1010', 'bank' => '1020', 'accounts_receivable' => '1100'] as $key => $accountCode) {
            if (DB::table('account_mappings')->where('mapping_key', $key)->whereNull('document_type')->exists()) {
                continue;
            }
            DB::table('account_mappings')->insert([
                'mapping_key' => $key,
                'account_id' => DB::table('chart_of_accounts')->where('code', $accountCode)->value('id'),
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function openInvoice(float $total = 500): Invoice
    {
        return Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'sent',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'paid_amount' => 0,
            'balance' => $total,
            'created_by' => $this->admin->id,
        ]);
    }

    private function recordDraft(Invoice $invoice, float $amount): Payment
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'method' => 'cash',
            ])
            ->assertCreated();

        return Payment::findOrFail($response->json('id'));
    }

    private function settlementsFromApi(): array
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/accounting/settlements')
            ->assertOk();

        return $response->json('data.data') ?? [];
    }

    public function test_a_draft_receipt_creates_no_settlement(): void
    {
        $invoice = $this->openInvoice(500);
        $this->recordDraft($invoice, 200);

        $this->assertSame([], $this->settlementsFromApi(), 'A draft receipt carries no cash, so it must not create a settlement.');
    }

    public function test_posting_a_receipt_recognises_a_fully_allocated_settlement(): void
    {
        $invoice = $this->openInvoice(500);
        $payment = $this->recordDraft($invoice, 200);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payments/{$payment->id}/post")
            ->assertOk();

        $rows = $this->settlementsFromApi();
        $this->assertCount(1, $rows, 'Posting a receipt must create exactly one settlement.');

        $row = $rows[0];
        $this->assertSame('App\\Models\\Payment', $row['source_record_type']);
        $this->assertSame($payment->id, (int) $row['source_record_id']);
        $this->assertSame('FULLY_ALLOCATED', $row['settlement_state']);
        $this->assertEquals(200.0, (float) $row['allocated_amount']);
        $this->assertEquals(0.0, (float) $row['unapplied_amount']);
        $this->assertEquals(200.0, (float) $row['total_amount'], 'total_amount must be allocated + unapplied.');
        $this->assertSame(
            strtoupper((string) ($payment->fresh()->currency ?: $payment->fresh()->base_currency_code ?: 'USD')),
            $row['currency_code'],
        );
    }

    public function test_reversing_a_posted_receipt_marks_its_settlement_reversed(): void
    {
        $invoice = $this->openInvoice(500);
        $payment = $this->recordDraft($invoice, 200);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payments/{$payment->id}/post")
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/corrections/payments/{$payment->id}/reverse", [
                'reason_code' => 'PAYMENT_REVERSAL',
                'reason_text' => 'Test reversal',
            ])
            ->assertOk();

        $settlement = Settlement::where('source_record_type', Payment::class)
            ->where('source_record_id', $payment->id)
            ->firstOrFail();

        $this->assertSame('REVERSED', $settlement->settlement_state);
        $this->assertEquals(0.0, (float) $settlement->allocated_amount);
    }

    public function test_recording_a_vendor_payment_recognises_a_settlement(): void
    {
        $vendor = Vendor::factory()->create();
        $bill = Bill::factory()->create([
            'vendor_id' => $vendor->id,
            'total' => 300,
            'balance' => 300,
            'status' => 'posted',
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/accounting/bill-payments', [
                'vendor_id' => $vendor->id,
                'bill_id' => $bill->id,
                'payment_date' => now()->toDateString(),
                'amount' => 300,
                'payment_method' => 'bank',
            ])
            ->assertCreated();

        $rows = $this->settlementsFromApi();
        $this->assertCount(1, $rows, 'A vendor payment must be recognised as a settlement.');

        $row = $rows[0];
        $this->assertSame('App\\Models\\BillPayment', $row['source_record_type']);
        $this->assertSame('FULLY_ALLOCATED', $row['settlement_state']);
        $this->assertEquals(300.0, (float) $row['allocated_amount']);
        $this->assertEquals(0.0, (float) $row['unapplied_amount']);
        $this->assertEquals(300.0, (float) $row['total_amount']);
    }

    public function test_backfill_command_recognises_receipts_that_predate_the_producer(): void
    {
        $invoice = $this->openInvoice(500);
        $payment = $this->recordDraft($invoice, 200);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payments/{$payment->id}/post")
            ->assertOk();

        // Simulate a legacy receipt recorded before the producer existed.
        Settlement::where('source_record_type', Payment::class)
            ->where('source_record_id', $payment->id)
            ->delete();

        $this->artisan('settlements:recognise')->assertSuccessful();

        $rows = $this->settlementsFromApi();
        $this->assertCount(1, $rows, 'The backfill command must recognise existing posted receipts.');
        $this->assertSame('FULLY_ALLOCATED', $rows[0]['settlement_state']);
        $this->assertEquals(200.0, (float) $rows[0]['total_amount']);
    }
}
