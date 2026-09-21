<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #74 (CRITICAL, CWE-639 broken access control) — Chatter record-level
 * access control.
 *
 * Before the fix, ChatterController only validated that subject_type was a
 * known class (#75) and that the id was numeric — it never checked that the
 * record exists or that the caller may access it. Any authenticated user could
 * read the chatter of ANY record by guessing model + id.
 *
 * The fix resolves the subject's module view-permission
 * (config/chatter.php subject_permissions) and enforces it via
 * TenantRoleService BEFORE resolving the record, so an unauthorized caller
 * gets 403 and cannot even enumerate which ids exist.
 *
 * Permission matrix relied on (RolePermissionSeeder):
 *   accounting.view -> OWNER, ADMIN, MANAGER, ACCOUNTANT, VIEWER  (NOT sales)
 *   sales.view      -> OWNER, ADMIN, MANAGER, SALES, VIEWER        (NOT accountant)
 * So: Accountant is denied SalesOrder chatter; Sales is denied Invoice chatter.
 */
class HR74ChatterRecordAccessTest extends TenantTestCase
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
            ['name' => 'HR74 Tenant', 'email' => 'hr74-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );
        User::factory()->create();
    }

    private function actingAsRole(string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole);

        return $user;
    }

    private function enc(string $fqcn): string
    {
        return rawurlencode($fqcn);
    }

    private function makeInvoice(int $userId): int
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'HR74 Customer', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-HR74-'.Str::random(6),
            'customer_id' => $customerId,
            'invoice_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'subtotal' => 100, 'tax_amount' => 0, 'discount_amount' => 0,
            'shipping_cost' => 0, 'total' => 100, 'status' => 'posted',
            'lifecycle_status' => 'posted', 'version_number' => 1, 'is_active_version' => true,
            'created_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ─── Deny: caller lacks the subject's module view-permission ────

    public function test_accountant_cannot_read_sales_order_chatter(): void
    {
        $user = $this->actingAsRole('accountant', 'Accountant');

        // Accountant has accounting.view but NOT sales.view; SalesOrder chatter
        // is gated by sales.view -> 403.
        $response = $this->actingAs($user)->getJson(
            '/api/v1/chatter/'.$this->enc('Modules\Sales\Models\SalesOrder').'/1'
        );
        $this->assertSame(403, $response->getStatusCode(), 'Accountant must be denied SalesOrder chatter (lacks sales.view)');
    }

    public function test_sales_cannot_read_invoice_chatter(): void
    {
        $user = $this->actingAsRole('sales', 'Sales');

        // Sales has sales.view but NOT accounting.view; Invoice chatter is
        // gated by accounting.view -> 403.
        $response = $this->actingAs($user)->getJson(
            '/api/v1/chatter/'.$this->enc('App\Models\Invoice').'/1'
        );
        $this->assertSame(403, $response->getStatusCode(), 'Sales must be denied Invoice chatter (lacks accounting.view)');
    }

    // ─── Permission is checked BEFORE record existence (no enumeration) ──

    public function test_unauthorized_caller_gets_403_not_404_for_missing_record(): void
    {
        $user = $this->actingAsRole('accountant', 'Accountant');

        // Non-existent SalesOrder id. Because permission is checked first, the
        // caller gets 403 (not 404) — so they cannot enumerate which ids exist.
        $response = $this->actingAs($user)->getJson(
            '/api/v1/chatter/'.$this->enc('Modules\Sales\Models\SalesOrder').'/999999'
        );
        $this->assertSame(403, $response->getStatusCode(), 'Permission must be checked before record existence');
    }

    // ─── Allow: authorized caller + existing record ─────────────────

    public function test_authorized_accountant_can_read_existing_invoice_chatter(): void
    {
        $user = $this->actingAsRole('accountant', 'Accountant');
        $invoiceId = $this->makeInvoice($user->id);

        // Accountant HAS accounting.view and the invoice exists -> not 403/404.
        $response = $this->actingAs($user)->getJson(
            '/api/v1/chatter/'.$this->enc('App\Models\Invoice').'/'.$invoiceId
        );
        $this->assertNotContains(
            $response->getStatusCode(),
            [403, 404],
            'Authorized accountant reading an existing invoice must pass access control: '.$response->getContent()
        );
    }

    // ─── Authorized caller + missing record -> 404 ──────────────────

    public function test_authorized_caller_gets_404_for_missing_record(): void
    {
        $user = $this->actingAsRole('accountant', 'Accountant');

        // Accountant has accounting.view, but the invoice id does not exist.
        $response = $this->actingAs($user)->getJson(
            '/api/v1/chatter/'.$this->enc('App\Models\Invoice').'/999999'
        );
        $this->assertSame(404, $response->getStatusCode(), 'Authorized caller + missing record must 404');
    }

    // ─── Unauthenticated ────────────────────────────────────────────

    public function test_unauthenticated_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/chatter/'.$this->enc('App\Models\Invoice').'/1');
        $this->assertSame(401, $response->getStatusCode());
    }

    // ─── Config integrity: every allowed model has a permission mapping ──

    public function test_every_allowed_model_has_a_subject_permission_mapping(): void
    {
        $allowed = config('chatter.allowed_models', []);
        $map = config('chatter.subject_permissions', []);

        $missing = [];
        foreach ($allowed as $model) {
            if (! isset($map[$model])) {
                $missing[] = $model;
            }
        }
        $this->assertSame(
            [],
            $missing,
            'These whitelisted chatter models have no subject_permissions mapping (would fail closed): '.implode(', ', $missing)
        );
    }

    public function test_subject_permissions_map_only_references_allowed_models(): void
    {
        $allowed = config('chatter.allowed_models', []);
        $map = config('chatter.subject_permissions', []);

        foreach (array_keys($map) as $model) {
            $this->assertContains($model, $allowed, "subject_permissions references {$model} which is not in allowed_models");
        }
    }
}
