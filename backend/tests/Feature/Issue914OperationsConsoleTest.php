<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Platform\Exceptions\ExceptionQueue;
use App\Platform\Exceptions\ExceptionRetryRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #914 [SALES-PLAT] P1: #902 persists typed operational failures
 * (failed postings, e-invoice rejections, notification failures, tax
 * variances, audit-chain breaks) on `platform_exception_queue` with routing
 * metadata — but nothing lists them for operations. This suite drives the
 * real HTTP routes of the operations console:
 *
 *  - list with filters (type / module / entity / status / owner) and stats
 *    grouped by type and entity,
 *  - show / assign / retry,
 *  - per-exception-type authorization derived from `owner_role` mapped to
 *    EXISTING seeded permissions (accountant → accounting.view/write,
 *    sales → sales.view/write, unowned/platform → settings.view/write) —
 *    no new permission strings,
 *  - SLA age computed against the operational per-severity targets.
 */
class Issue914OperationsConsoleTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            [
                'name' => 'Issue 914 Operations Console Tenant',
                'email' => 'ops914-'.substr(md5($this->tenantId), 0, 8).'@example.com',
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

        $this->assertTenantSchemaReady(['platform_exception_queue']);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Listing, filters and grouping stats
    // ═════════════════════════════════════════════════════════════════════

    public function test_console_lists_queued_exceptions_with_filters_and_grouped_stats(): void
    {
        $admin = $this->makeUser(UserRole::ADMIN);

        // The real Sales handler key (#902's SalesServiceProvider) is the
        // integration the capability flag reflects; register it explicitly so
        // the assertion does not depend on provider boot order.
        app(ExceptionRetryRegistry::class)->register(
            'sales.delivery_order.notify_approvers',
            fn () => null,
        );

        $tax = $this->queued([
            'exception_type' => 'sales.invoice.tax_variance',
            'severity' => 'high',
            'message' => 'Tax variance on INV-1',
            'document_type' => 'invoice',
            'document_id' => '11',
            'legal_entity_id' => 7,
            'owner_role' => UserRole::ACCOUNTANT->value,
            'created_at' => now()->subHours(30),
        ]);

        $notify = $this->queued([
            'exception_type' => 'sales.delivery_order.approval_notification_failed',
            'severity' => 'high',
            'message' => 'Approvers could not be notified for DO-1',
            'document_type' => 'delivery_order',
            'document_id' => '12',
            'legal_entity_id' => 8,
            'owner_role' => UserRole::SALES->value,
            'retry_handler' => 'sales.delivery_order.notify_approvers',
            'created_at' => now()->subHour(),
        ]);

        $audit = $this->queued([
            'module' => 'platform',
            'exception_type' => 'audit.chain.broken',
            'severity' => 'critical',
            'message' => 'Tamper-evident audit chain verification failed',
            'legal_entity_id' => null,
            'owner_role' => null,
            'created_at' => now()->subHours(5),
        ]);

        $list = $this->actingAs($admin)->getJson('/api/v1/platform/exceptions');
        $list->assertOk();
        $list->assertJsonCount(3, 'data');

        $rows = collect($list->json('data'))->keyBy('id');

        // SLA: high = 24h target (30h old → breached); critical = 4h (5h old
        // → breached); high raised 1h ago → within target.
        $this->assertSame(24, $rows[$tax->id]['sla_hours']);
        $this->assertTrue($rows[$tax->id]['sla_breached']);
        $this->assertGreaterThanOrEqual(30, $rows[$tax->id]['age_hours']);
        $this->assertFalse($rows[$notify->id]['sla_breached']);
        $this->assertSame(4, $rows[$audit->id]['sla_hours']);
        $this->assertTrue($rows[$audit->id]['sla_breached']);

        // Per-row action capability for an admin: assign anything; retry only
        // where a handler exists.
        $this->assertTrue($rows[$tax->id]['can_assign']);
        $this->assertFalse($rows[$tax->id]['can_retry']);
        $this->assertTrue($rows[$notify->id]['can_retry']);
        $this->assertFalse($rows[$audit->id]['can_retry']);

        // Filters: type, module, entity, status, owner.
        $this->actingAs($admin)
            ->getJson('/api/v1/platform/exceptions?type=sales.delivery_order.approval_notification_failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $notify->id);

        $this->actingAs($admin)
            ->getJson('/api/v1/platform/exceptions?module=platform')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $audit->id);

        $this->actingAs($admin)
            ->getJson('/api/v1/platform/exceptions?legal_entity_id=8')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $notify->id);

        $this->actingAs($admin)
            ->getJson('/api/v1/platform/exceptions?status=open&owner=accountant')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tax->id);

        // Grouping stats the console renders (by type, entity, status, owner).
        $stats = $this->actingAs($admin)->getJson('/api/v1/platform/exceptions/stats');
        $stats->assertOk()->assertJsonPath('data.total', 3);
        $byType = $stats->json('data.by_type');
        $this->assertSame(1, $byType['sales.invoice.tax_variance']);
        $this->assertSame(1, $byType['audit.chain.broken']);
        $this->assertSame(['7' => 1, '8' => 1, 'unassigned' => 1], $stats->json('data.by_entity'));
        $this->assertSame(3, $stats->json('data.by_status.open'));
        $this->assertSame(2, $stats->json('data.sla_breached'));

        // Pagination meta is exposed for the console table.
        $this->assertSame(3, $list->json('meta.total'));
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Per-exception-type authorization (derived from owner_role)
    // ═════════════════════════════════════════════════════════════════════

    public function test_list_is_scoped_to_the_exception_types_the_caller_is_permissioned_for(): void
    {
        $sales = $this->makeUser(UserRole::SALES);
        $accountant = $this->makeUser(UserRole::ACCOUNTANT);
        $cashier = $this->makeUser(UserRole::CASHIER);

        $tax = $this->queued([
            'exception_type' => 'sales.invoice.tax_variance',
            'owner_role' => UserRole::ACCOUNTANT->value,
        ]);

        $notify = $this->queued([
            'exception_type' => 'sales.delivery_order.approval_notification_failed',
            'owner_role' => UserRole::SALES->value,
            'retry_handler' => 'test.ops.retry',
        ]);

        // Sales holds only sales.view → sees only sales-owned exceptions.
        $this->actingAs($sales)
            ->getJson('/api/v1/platform/exceptions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $notify->id)
            ->assertJsonPath('meta.total', 1);

        // Accountant holds only accounting.view → sees only accountant-owned.
        $this->actingAs($accountant)
            ->getJson('/api/v1/platform/exceptions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tax->id);

        // Cashier holds none of the mapped view permissions → empty console.
        $this->actingAs($cashier)
            ->getJson('/api/v1/platform/exceptions')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        // Show is denied for a type the caller does not own.
        $this->actingAs($sales)
            ->getJson("/api/v1/platform/exceptions/{$tax->id}")
            ->assertForbidden();

        $this->actingAs($sales)
            ->getJson("/api/v1/platform/exceptions/{$notify->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $notify->id);
    }

    public function test_unowned_platform_exceptions_require_the_admin_view_permission(): void
    {
        $sales = $this->makeUser(UserRole::SALES);
        $admin = $this->makeUser(UserRole::ADMIN);

        $audit = $this->queued([
            'module' => 'platform',
            'exception_type' => 'audit.chain.broken',
            'severity' => 'critical',
            'owner_role' => null,
        ]);

        // Legacy/platform rows have no owner_role and map to settings.view
        // (Owner/Admin only) — a sales user must not see them.
        $this->actingAs($sales)
            ->getJson('/api/v1/platform/exceptions')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($sales)
            ->getJson("/api/v1/platform/exceptions/{$audit->id}")
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson('/api/v1/platform/exceptions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $audit->id);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Owner assignment
    // ═════════════════════════════════════════════════════════════════════

    public function test_assign_sets_the_owner_for_a_permitted_type_and_denies_other_types(): void
    {
        $sales = $this->makeUser(UserRole::SALES);
        $accountant = $this->makeUser(UserRole::ACCOUNTANT);

        $notify = $this->queued([
            'exception_type' => 'sales.delivery_order.approval_notification_failed',
            'owner_role' => UserRole::SALES->value,
        ]);

        $tax = $this->queued([
            'exception_type' => 'sales.invoice.tax_variance',
            'owner_role' => UserRole::ACCOUNTANT->value,
        ]);

        $this->actingAs($sales)
            ->postJson("/api/v1/platform/exceptions/{$notify->id}/assign", [
                'user_id' => $sales->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned_to', $sales->id)
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('platform_exception_queue', [
            'id' => $notify->id,
            'assigned_to' => $sales->id,
            'status' => 'in_progress',
        ]);

        // Sales cannot assign an accountant-owned exception.
        $this->actingAs($sales)
            ->postJson("/api/v1/platform/exceptions/{$tax->id}/assign", [
                'user_id' => $sales->id,
            ])
            ->assertForbidden();

        // The assignee must be a member of this tenant.
        $this->actingAs($accountant)
            ->postJson("/api/v1/platform/exceptions/{$tax->id}/assign", [
                'user_id' => 999999,
            ])
            ->assertStatus(422);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  One-click retry
    // ═════════════════════════════════════════════════════════════════════

    public function test_retry_runs_the_registered_handler_and_reports_the_result(): void
    {
        $accountant = $this->makeUser(UserRole::ACCOUNTANT);
        $sales = $this->makeUser(UserRole::SALES);

        $registry = app(ExceptionRetryRegistry::class);
        $calls = [];
        $registry->register('test.ops.retry', function (ExceptionQueue $exception) use (&$calls): void {
            $calls[] = $exception->id;
        });

        $retryable = $this->queued([
            'exception_type' => 'sales.payment.posting_failed',
            'owner_role' => UserRole::ACCOUNTANT->value,
            'retry_handler' => 'test.ops.retry',
        ]);

        $response = $this->actingAs($accountant)
            ->postJson("/api/v1/platform/exceptions/{$retryable->id}/retry");

        $response->assertOk()
            ->assertJsonPath('retry.handler', 'test.ops.retry')
            ->assertJsonPath('retry.outcome', 'resolved')
            ->assertJsonPath('data.status', 'resolved');

        $this->assertSame([$retryable->id], $calls);
        $this->assertSame('resolved', ExceptionQueue::find($retryable->id)->status);

        // A failing handler reopens the exception with the retry error.
        $registry->register('test.ops.retry_fail', function (): void {
            throw new \RuntimeException('upstream still down');
        });

        $failing = $this->queued([
            'exception_type' => 'sales.einvoice.auto_submit_failed',
            'owner_role' => UserRole::ACCOUNTANT->value,
            'retry_handler' => 'test.ops.retry_fail',
        ]);

        $this->actingAs($accountant)
            ->postJson("/api/v1/platform/exceptions/{$failing->id}/retry")
            ->assertOk()
            ->assertJsonPath('retry.outcome', 'failed')
            ->assertJsonPath('retry.error', 'upstream still down')
            ->assertJsonPath('data.status', 'open');

        $this->assertSame(
            'upstream still down',
            ExceptionQueue::find($failing->id)->context['last_retry_error'] ?? null,
        );

        // Sales cannot retry an accountant-owned exception.
        $this->actingAs($sales)
            ->postJson("/api/v1/platform/exceptions/{$retryable->id}/retry")
            ->assertForbidden();

        // A record without a registered handler is not retryable.
        $noHandler = $this->queued([
            'exception_type' => 'sales.invoice.tax_variance',
            'owner_role' => UserRole::ACCOUNTANT->value,
            'retry_handler' => null,
        ]);

        $this->actingAs($accountant)
            ->postJson("/api/v1/platform/exceptions/{$noHandler->id}/retry")
            ->assertStatus(422);

        // An already-resolved exception is not retryable again.
        $resolved = $this->queued([
            'exception_type' => 'sales.payment.posting_failed',
            'owner_role' => UserRole::ACCOUNTANT->value,
            'retry_handler' => 'test.ops.retry',
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        $this->actingAs($accountant)
            ->postJson("/api/v1/platform/exceptions/{$resolved->id}/retry")
            ->assertStatus(422);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Fixtures
    // ═════════════════════════════════════════════════════════════════════

    private function makeUser(UserRole $role): User
    {
        $user = User::factory()->create();

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            [
                'name' => $user->name,
                'password' => $user->password,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $centralId = (int) DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            [
                'role' => $role->value,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $user->assignRole($role->spatieName());

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function queued(array $overrides = []): ExceptionQueue
    {
        return ExceptionQueue::create(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'module' => 'sales',
            'exception_type' => 'sales.invoice.tax_variance',
            'severity' => 'high',
            'message' => 'Queued operational exception',
            'status' => 'open',
            'retry_count' => 0,
            'max_retries' => 3,
        ], $overrides));
    }
}
