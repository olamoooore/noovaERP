<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SettlementAuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #482 (follows the #477 decision): a draft payment is deleted, not corrected.
 * When legacy rows still carry live payment_applications (created before
 * drafts stopped settling invoices), deleting the draft must reverse every
 * allocation through the canonical path — restoring each invoice and writing a
 * settlement audit row — instead of silently dropping the applications.
 *
 * Exercises the real router/middleware/controller chain via the HTTP API.
 */
class PaymentDraftDeletionReversalTest extends TenantTestCase
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

    /**
     * Simulate a legacy draft that had already allocated parts of itself to two
     * invoices (pre-#477 behaviour, where a recorded payment settled instantly).
     */
    private function legacySplitDraft(Invoice $a, Invoice $b): Payment
    {
        $payment = $this->recordDraft($a, 300);

        $invoiceService = app(InvoiceService::class);
        $invoiceService->applyAllocatedPayment($a, 100, $payment);
        $invoiceService->applyAllocatedPayment($b, 200, $payment);

        return $payment;
    }

    public function test_deleting_a_legacy_draft_restores_all_invoices_and_writes_audit_rows(): void
    {
        $a = $this->openInvoice(500);
        $b = $this->openInvoice(500);
        $payment = $this->legacySplitDraft($a, $b);

        $this->assertSame(100.0, (float) $a->fresh()->paid_amount);
        $this->assertSame(200.0, (float) $b->fresh()->paid_amount);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/payments/{$payment->id}")
            ->assertOk();

        $this->assertNull(Payment::find($payment->id), 'Draft payment must be deleted.');

        $this->assertSame(0.0, (float) $a->fresh()->paid_amount, 'Invoice A must be restored.');
        $this->assertSame(500.0, (float) $a->fresh()->balance);
        $this->assertSame(0.0, (float) $b->fresh()->paid_amount, 'Invoice B must be restored.');
        $this->assertSame(500.0, (float) $b->fresh()->balance);

        $auditRows = SettlementAuditLog::where('payment_id', $payment->id)
            ->where('notes', 'Payment allocation reversed')
            ->count();
        $this->assertSame(2, $auditRows, 'Each reversed allocation must write a settlement audit row.');
    }

    public function test_bulk_delete_reverses_legacy_applications_too(): void
    {
        $a = $this->openInvoice(500);
        $b = $this->openInvoice(500);
        $payment = $this->legacySplitDraft($a, $b);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/payments/bulk-delete', ['ids' => [$payment->id]])
            ->assertOk();

        $this->assertNull(Payment::find($payment->id));
        $this->assertSame(0.0, (float) $a->fresh()->paid_amount);
        $this->assertSame(0.0, (float) $b->fresh()->paid_amount);
        $this->assertSame(
            2,
            SettlementAuditLog::where('payment_id', $payment->id)
                ->where('notes', 'Payment allocation reversed')
                ->count(),
        );
    }
}