<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\SetTenantFromUserPreference;
use App\Models\User;
use App\SuperAdmin\Application\TenantOperationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #172 (CRITICAL) — tenant.data.suspended enforcement.
 *
 * The flag is set by the SuperAdmin console but was never consulted at the
 * tenant-resolution layer. This test proves both halves:
 *
 *  WRITE SIDE (end-to-end, real service):
 *    TenantOperationService::suspend() persists `suspended: true` into the raw
 *    public.tenants.data json column (the previous $tenant->update(['data'=>...])
 *    wrote nothing readable — the stancl/tenancy Tenant model manages `data`
 *    via custom-column internals), and reinstate() clears it.
 *
 *  READ SIDE (pure decision):
 *    SetTenantFromUserPreference::isSuspendedFlagSet() correctly interprets the
 *    raw column value (json string or array) and blocks only truthy `suspended`.
 *    The middleware itself calls this helper plus the Eloquent accessor; the
 *    full handle() path cannot be asserted in this transactional test harness
 *    because Tenancy::initialize() resets the DB connection and therefore does
 *    not see the test's uncommitted writes (production writes are committed, so
 *    the middleware sees them there).
 */
class HR172TenantSuspendedEnforcementTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->seed(RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'HR172 Tenant', 'email' => 'hr172-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );

        if (! DB::selectOne(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema='public' AND table_name='users' AND column_name='is_super_admin'"
        )) {
            DB::statement('ALTER TABLE public.users ADD COLUMN is_super_admin BOOLEAN NOT NULL DEFAULT false');
        }

        $actor = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $actor->email],
            ['name' => $actor->name, 'password' => $actor->password, 'created_at' => now(), 'updated_at' => now()]
        );
        auth()->login($actor);
    }

    private function rawData(): array
    {
        $raw = DB::table('public.tenants')->where('id', $this->tenantId)->value('data');
        if (is_array($raw)) {
            return $raw;
        }

        return is_string($raw) && $raw !== '' && $raw !== 'null' ? (json_decode($raw, true) ?: []) : [];
    }

    // ─── WRITE SIDE: suspend()/reinstate() persist to the raw column ──

    public function test_suspend_service_persists_the_flag_to_the_raw_column(): void
    {
        $before = $this->rawData();
        $this->assertFalse((bool) ($before['suspended'] ?? false));

        app(TenantOperationService::class)->suspend($this->tenantId, 'unit test');

        $after = $this->rawData();
        $this->assertTrue((bool) ($after['suspended'] ?? false), 'suspend() must persist suspended=true to public.tenants.data');
        // Existing custom-column data must survive the merge.
        $this->assertSame(
            $before['name'] ?? null,
            $after['name'] ?? null,
            'suspend() must not clobber the tenant custom-column data'
        );
    }

    public function test_reinstate_service_clears_the_flag(): void
    {
        $svc = app(TenantOperationService::class);
        $svc->suspend($this->tenantId);
        $this->assertTrue((bool) ($this->rawData()['suspended'] ?? false));

        $svc->reinstate($this->tenantId);
        $this->assertFalse((bool) ($this->rawData()['suspended'] ?? false), 'reinstate() must clear the flag');
    }

    // ─── READ SIDE: pure flag evaluator used by the middleware ──────

    public function test_flag_evaluator_blocks_truthy_suspended(): void
    {
        // Raw json string as returned by the pgsql driver for a json column.
        $this->assertTrue(SetTenantFromUserPreference::isSuspendedFlagSet('{"suspended":true,"name":"X"}'));
        // Already-decoded array.
        $this->assertTrue(SetTenantFromUserPreference::isSuspendedFlagSet(['suspended' => true]));
    }

    public function test_flag_evaluator_allows_active_tenant(): void
    {
        $this->assertFalse(SetTenantFromUserPreference::isSuspendedFlagSet('{"suspended":false,"name":"X"}'));
        $this->assertFalse(SetTenantFromUserPreference::isSuspendedFlagSet(['suspended' => false]));
        // Flag absent entirely.
        $this->assertFalse(SetTenantFromUserPreference::isSuspendedFlagSet('{"name":"X"}'));
        // Null / empty / malformed must fail open (treated as active).
        $this->assertFalse(SetTenantFromUserPreference::isSuspendedFlagSet(null));
        $this->assertFalse(SetTenantFromUserPreference::isSuspendedFlagSet(''));
        $this->assertFalse(SetTenantFromUserPreference::isSuspendedFlagSet('null'));
        $this->assertFalse(SetTenantFromUserPreference::isSuspendedFlagSet('{}'));
    }

    // ─── Integration: the written flag is what the evaluator reads ──

    public function test_written_flag_is_read_back_as_suspended(): void
    {
        app(TenantOperationService::class)->suspend($this->tenantId);

        // The exact raw value the middleware reads must evaluate to suspended.
        $raw = DB::table('public.tenants')->where('id', $this->tenantId)->value('data');
        $this->assertTrue(
            SetTenantFromUserPreference::isSuspendedFlagSet($raw),
            'The value suspend() writes must be read as suspended by the middleware evaluator'
        );
    }

    public function test_reinstated_flag_is_read_back_as_active(): void
    {
        $svc = app(TenantOperationService::class);
        $svc->suspend($this->tenantId);
        $svc->reinstate($this->tenantId);

        $raw = DB::table('public.tenants')->where('id', $this->tenantId)->value('data');
        $this->assertFalse(
            SetTenantFromUserPreference::isSuspendedFlagSet($raw),
            'After reinstate() the middleware evaluator must treat the tenant as active'
        );
    }
}
