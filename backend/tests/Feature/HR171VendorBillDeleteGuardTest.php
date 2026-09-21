<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #171 (CRITICAL) — Vendor bill delete guard.
 *
 * Before the fix, VendorBillsController::destroy() only blocked deletion when
 * status === 'posted'. But a bill progresses to 'partial' (once any payment is
 * applied) and 'overdue' (posted, unpaid, past due) — both real post-posting
 * states with a live GL journal entry. Deleting those orphaned the AP journal
 * entry (unrecoverable AP-subledger-to-GL break), and for 'partial' bills the
 * bill_payments FK (onDelete restrict) leaked a raw SQL error instead of a
 * clean 422.
 *
 * Fix: invert the guard to a whitelist of the one safe-to-delete state
 * ('draft'), matching InvoiceController::destroy(), plus a direct
 * payments()->exists() defence-in-depth check, and stop leaking raw SQL errors.
 */
class HR171VendorBillDeleteGuardTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'HR171 Tenant', 'email' => 'hr171-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );
        User::factory()->create();
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
            ['role' => 'admin', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole('Admin');

        return $user;
    }

    private function makeVendor(): int
    {
        return DB::table('vendors')->insertGetId([
            'name' => 'HR171 Vendor', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeBill(int $vendorId, string $status): Bill
    {
        return Bill::create([
            'bill_number' => 'BILL-HR171-'.Str::random(6),
            'vendor_id' => $vendorId,
            'bill_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'total' => 500,
            'balance' => 500,
            'status' => $status,
        ]);
    }

    // ─── Draft bill: deletion allowed ───────────────────────────────

    public function test_draft_bill_can_be_deleted(): void
    {
        $admin = $this->actingAsAdmin();
        $bill = $this->makeBill($this->makeVendor(), 'draft');

        $response = $this->actingAs($admin)->deleteJson("/api/v1/accounting/vendor-bills/{$bill->id}");
        $this->assertSame(200, $response->getStatusCode(), 'A draft bill must be deletable: '.$response->getContent());
    }

    // ─── Posted bill: deletion blocked (the case the old guard caught) ──

    public function test_posted_bill_delete_is_blocked(): void
    {
        $admin = $this->actingAsAdmin();
        $bill = $this->makeBill($this->makeVendor(), 'posted');

        $response = $this->actingAs($admin)->deleteJson("/api/v1/accounting/vendor-bills/{$bill->id}");
        $this->assertSame(422, $response->getStatusCode(), 'Posted bill delete must be blocked');
        $this->assertNotNull(Bill::find($bill->id), 'Posted bill must survive');
    }

    // ─── Overdue bill: THE data-loss case the old guard missed ──────

    public function test_overdue_bill_delete_is_blocked(): void
    {
        $admin = $this->actingAsAdmin();
        // 'overdue' = posted, unpaid, past due. The old `=== 'posted'` guard
        // did NOT catch this, so the bill + lines were destroyed while its GL
        // journal entry was orphaned.
        $bill = $this->makeBill($this->makeVendor(), 'overdue');

        $response = $this->actingAs($admin)->deleteJson("/api/v1/accounting/vendor-bills/{$bill->id}");
        $this->assertSame(422, $response->getStatusCode(), 'Overdue bill delete must be blocked (was the data-loss case)');
        $this->assertStringContainsString('Only draft bills can be deleted', $response->json('message'));
        $this->assertNotNull(Bill::find($bill->id), 'Overdue bill must survive — no orphaned GL entry');
    }

    // ─── Partial bill: clean 422, not a leaked raw SQL error ────────

    public function test_partial_bill_delete_returns_clean_422_not_sql_error(): void
    {
        $admin = $this->actingAsAdmin();
        $vendorId = $this->makeVendor();
        $bill = $this->makeBill($vendorId, 'partial');

        // A real payment row against the bill (bill_payments.bill_id is
        // onDelete('restrict')). Before the fix this produced a raw
        // QueryException leaked to the caller via 'error' => $e->getMessage().
        DB::table('bill_payments')->insert([
            'payment_number' => 'BP-HR171-'.Str::random(6),
            'vendor_id' => $vendorId,
            'bill_id' => $bill->id,
            'payment_date' => '2026-01-15',
            'amount' => 100,
            'payment_method' => 'bank',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->deleteJson("/api/v1/accounting/vendor-bills/{$bill->id}");
        $this->assertSame(422, $response->getStatusCode(), 'Partial bill delete must be a clean 422');
        // The clean guard message must be returned — NOT a raw SQL/constraint error.
        $message = (string) $response->json('message');
        $this->assertStringNotContainsString('SQLSTATE', $message, 'Must not leak a raw SQL error');
        $this->assertStringNotContainsString('constraint', strtolower($message), 'Must not leak a constraint error');
        $this->assertNull($response->json('error'), 'No raw error field should be present on the guarded path');
        $this->assertNotNull(Bill::find($bill->id), 'Partial bill must survive');
    }

    // ─── 404 for a missing bill ─────────────────────────────────────

    public function test_missing_bill_returns_404(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin)->deleteJson('/api/v1/accounting/vendor-bills/999999');
        $this->assertSame(404, $response->getStatusCode());
    }
}
