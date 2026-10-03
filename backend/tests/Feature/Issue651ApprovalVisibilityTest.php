<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #651 [SALES-APR] AP7 — GET /approvals and GET /approvals/{id}
 * (ApprovalController::index/show) carried no gate of any kind, so any
 * authenticated tenant user could list every pending approval request with its
 * subject document loaded — across document types and legal entities — and
 * fetch any single request by id. POST /approvals additionally validated
 * subject_type as a bare `string`, so a request could be raised against an
 * arbitrary value that list/show then handed to the polymorphic subject
 * relation (the arbitrary-class-instantiation problem chatter fixed in #75).
 *
 * Every assertion below goes through the real router chain
 * (auth:sanctum → tenant middleware → ApprovalController), not through a
 * service or a source-string check.
 *
 * Contract asserted (issue acceptance):
 *  - the list shows only requests the user can decide, requested themselves,
 *    or whose subject document they may view;
 *  - show enforces the same rule (another entity's request → 403);
 *  - POST /approvals rejects a subject_type outside the whitelist.
 */
class Issue651ApprovalVisibilityTest extends TenantTestCase
{
    private string $tenantId;

    private User $viewer;

    private User $requester;

    private User $configuredReviewer;

    private Customer $customer;

    private int $entityA;

    private int $entityB;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        // Viewer: has sales.view / invoices.view / pos.view etc. per
        // RolePermissionSeeder::PERMISSION_MATRIX, but is no one's reviewer
        // and holds no manager/supervisor role.
        $this->viewer = $this->makeUser('viewer', UserRole::VIEWER);
        $this->requester = $this->makeUser('cashier', UserRole::CASHIER);

        // A user with no tenant role at all: can view no document type and
        // decide nothing — for them a request can only become visible by
        // being the configured reviewer (or the requester, which they are not).
        $this->configuredReviewer = User::factory()->create();

        $this->customer = Customer::factory()->create(['is_active' => true]);

        // Two legal entities; the viewer is scoped to entity A only, so any
        // request whose document lives in entity B must be invisible to them.
        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Approvals Entity Group',
            'code' => 'AEG-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Approvals Entity A', 'code' => 'AEA-'.Str::upper(Str::random(5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Approvals Entity B', 'code' => 'AEB-'.Str::upper(Str::random(5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('user_entity_access')->insert([
            'user_id' => $this->viewer->id,
            'legal_entity_id' => $this->entityA,
            'role' => 'viewer',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /approvals — list scoping
    // ─────────────────────────────────────────────────────────────────────

    public function test_viewer_listing_returns_only_their_own_requests(): void
    {
        $own = $this->posRequest($this->viewer, 991);
        $this->posRequest($this->requester, 992);

        $response = $this->actingAs($this->viewer)->getJson('/api/v1/approvals');

        $response->assertOk();
        $this->assertSame([$own->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('count', 1);
    }

    public function test_viewer_sees_requests_on_documents_they_can_view(): void
    {
        $visible = $this->invoiceRequestFor($this->entityA, $this->requester);

        $response = $this->actingAs($this->viewer)->getJson('/api/v1/approvals');

        $response->assertOk();
        $this->assertContains($visible->id, array_column($response->json('data'), 'id'));
    }

    public function test_viewer_list_excludes_requests_from_another_legal_entity(): void
    {
        $hidden = $this->invoiceRequestFor($this->entityB, $this->requester);

        $response = $this->actingAs($this->viewer)->getJson('/api/v1/approvals');

        $response->assertOk();
        $this->assertNotContains($hidden->id, array_column($response->json('data'), 'id'));
        $response->assertJsonPath('count', 0);
    }

    public function test_configured_reviewer_sees_requests_they_can_decide(): void
    {
        Setting::setValue('sales.quotation.reviewer_user_ids', [$this->configuredReviewer->id]);

        $quotation = Quotation::create([
            'customer_id' => $this->customer->id,
            'quotation_number' => 'Q-651-'.uniqid(),
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 2500,
            'tax_amount' => 0,
            'total' => 2500,
            'created_by' => $this->requester->id,
        ]);

        $request = ApprovalRequest::create([
            'request_type' => SalesApprovalGate::REQUEST_TYPE_QUOTATION,
            'subject_type' => Quotation::class,
            'subject_id' => $quotation->id,
            'amount' => 2500,
            'reason' => 'Quotation over the tenant approval threshold',
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->configuredReviewer)->getJson('/api/v1/approvals');

        $response->assertOk();
        $this->assertContains($request->id, array_column($response->json('data'), 'id'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /approvals/{id} — show scoping
    // ─────────────────────────────────────────────────────────────────────

    public function test_show_own_request_is_allowed(): void
    {
        $own = $this->posRequest($this->viewer, 991);

        $this->actingAs($this->viewer)
            ->getJson("/api/v1/approvals/{$own->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $own->id);
    }

    public function test_show_another_users_request_is_forbidden(): void
    {
        $other = $this->posRequest($this->requester, 992);

        $this->actingAs($this->viewer)
            ->getJson("/api/v1/approvals/{$other->id}")
            ->assertStatus(403);
    }

    public function test_show_another_entities_request_is_forbidden(): void
    {
        $request = $this->invoiceRequestFor($this->entityB, $this->requester);

        $this->actingAs($this->viewer)
            ->getJson("/api/v1/approvals/{$request->id}")
            ->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /approvals/my-requests — own requests still load safely
    // ─────────────────────────────────────────────────────────────────────

    public function test_my_requests_lists_own_pos_requests_without_error(): void
    {
        $own = $this->posRequest($this->viewer, 991);

        $this->actingAs($this->viewer)
            ->getJson('/api/v1/approvals/my-requests')
            ->assertOk()
            ->assertJsonPath('data.0.id', $own->id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /approvals — subject_type whitelist
    // ─────────────────────────────────────────────────────────────────────

    public function test_store_rejects_subject_type_outside_the_whitelist(): void
    {
        $response = $this->actingAs($this->viewer)->postJson('/api/v1/approvals', [
            'request_type' => 'void',
            // A real, autoloadable Eloquent class the issue allows through
            // today — still not a legitimate approval subject.
            'subject_type' => \App\Models\User::class,
            'subject_id' => 1,
            'amount' => 10,
            'reason' => 'Arbitrary subject types must be rejected',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subject_type']);
        $this->assertFalse(
            ApprovalRequest::where('subject_type', \App\Models\User::class)->exists(),
            'A request must not be created for a non-whitelisted subject_type'
        );
    }

    public function test_store_rejects_non_class_subject_type(): void
    {
        $this->actingAs($this->viewer)->postJson('/api/v1/approvals', [
            'request_type' => 'void',
            'subject_type' => 'TotallyArbitrarySubject',
            'subject_id' => 1,
            'amount' => 10,
            'reason' => 'Non-class subject types must be rejected',
        ])->assertStatus(422);
    }

    public function test_store_still_accepts_the_whitelisted_subject_types(): void
    {
        // The POS flow (frontend ApprovalRequestModal) — the only subject type
        // POST /approvals receives in practice today.
        $this->actingAs($this->viewer)->postJson('/api/v1/approvals', [
            'request_type' => 'void',
            'subject_type' => 'PosOrder',
            'subject_id' => 991,
            'amount' => 10,
            'reason' => 'POS void approval request raised here',
        ])->assertStatus(201);

        // A document class written by the gate-driven flows, through the
        // frontend alias fields (source_type/source_id).
        $invoice = Invoice::factory()->create([
            'legal_entity_id' => $this->entityA,
            'customer_id' => $this->customer->id,
        ]);

        $this->actingAs($this->viewer)->postJson('/api/v1/approvals', [
            'request_type' => 'refund',
            'source_type' => Invoice::class,
            'source_id' => $invoice->id,
            'amount' => 10,
            'reason' => 'Document subject types must keep working',
        ])->assertStatus(201);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function makeUser(string $accessRole, UserRole $spatieRole): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole->spatieName());

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $accessRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /**
     * A pending POS void request — the shape the POS ApprovalQueue raises.
     * subject_id points at an order that does not exist in these tests; the
     * list must cope with that (no subject document to load) rather than 500.
     */
    private function posRequest(User $requestedBy, int $subjectId): ApprovalRequest
    {
        return ApprovalRequest::create([
            'request_type' => 'void',
            'subject_type' => 'PosOrder',
            'subject_id' => $subjectId,
            'amount' => 42.50,
            'reason' => 'POS void request raised in test',
            'requested_by' => $requestedBy->id,
            'requested_at' => now(),
            'status' => 'pending',
        ]);
    }

    private function invoiceRequestFor(int $legalEntityId, User $requestedBy): ApprovalRequest
    {
        $invoice = Invoice::factory()->create([
            'legal_entity_id' => $legalEntityId,
            'customer_id' => $this->customer->id,
        ]);

        return ApprovalRequest::create([
            'request_type' => SalesApprovalGate::REQUEST_TYPE_INVOICE,
            'subject_type' => Invoice::class,
            'subject_id' => $invoice->id,
            'amount' => (float) $invoice->total,
            'reason' => 'Invoice threshold approval raised in test',
            'requested_by' => $requestedBy->id,
            'requested_at' => now(),
            'status' => 'pending',
        ]);
    }
}
