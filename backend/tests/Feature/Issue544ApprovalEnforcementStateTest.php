<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #544 (ST7): the Sales Settings approval section showed a hard-coded
 * green "Enforced" badge ("unapproved invoices cannot be posted"). Posting is
 * only blocked for invoices whose requires_approval is true
 * (InvoiceApprovalService::checkGuard), so the badge must reflect the real
 * state the server can report: "all invoices" only when the tenant policy is
 * on AND no unposted draft has approval switched off; otherwise
 * "per document" with the number of drafts that would post unreviewed.
 *
 * Every request runs through the real route GET /api/v1/settings/sales
 * (router -> middleware -> controller).
 */
class Issue544ApprovalEnforcementStateTest extends TenantTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenantUuid,
            'name' => 'Issue 544 Tenant',
            'email' => $tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function enforcement(): array
    {
        return (array) $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->json('data.invoice.approval_enforcement');
    }

    public function test_policy_off_is_reported_as_per_document_with_the_unprotected_draft_count(): void
    {
        Setting::setValue('sales.invoice.default_requires_approval', false);
        Invoice::factory()->create(['requires_approval' => false]);
        Invoice::factory()->create(['requires_approval' => true, 'approval_status' => Invoice::APPROVAL_PENDING]);

        $state = $this->enforcement();

        $this->assertSame('per_document', $state['mode'] ?? null);
        $this->assertSame(1, $state['drafts_without_approval'] ?? null);
    }

    public function test_policy_on_with_a_legacy_draft_that_has_approval_off_is_not_reported_as_enforced(): void
    {
        // Draft created before the policy was switched on: checkGuard lets it post unreviewed.
        $legacy = Invoice::factory()->create(['requires_approval' => false]);
        Setting::setValue('sales.invoice.default_requires_approval', true);

        $this->assertSame(['mode' => 'per_document', 'drafts_without_approval' => 1], $this->onlyStateKeys($this->enforcement()));

        // Once no unposted draft can bypass approval, the policy is genuinely enforced.
        $legacy->forceFill(['requires_approval' => true])->save();
        Invoice::factory()->posted()->create(['requires_approval' => false]); // already posted: not bypassable

        $this->assertSame(['mode' => 'all_invoices', 'drafts_without_approval' => 0], $this->onlyStateKeys($this->enforcement()));
    }

    private function onlyStateKeys(array $state): array
    {
        return [
            'mode' => $state['mode'] ?? null,
            'drafts_without_approval' => $state['drafts_without_approval'] ?? null,
        ];
    }
}
