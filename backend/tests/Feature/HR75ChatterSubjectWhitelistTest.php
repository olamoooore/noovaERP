<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #75 (CRITICAL, CWE-94 code injection) — Chatter polymorphic subject
 * whitelist.
 *
 * Before the fix, ChatterController::validateSubject() only checked
 * class_exists($subjectType), which EVERY autoloadable class satisfies
 * (including framework/vendor classes with constructor side effects). An
 * attacker could pass an arbitrary FQCN as the URL subject_type.
 *
 * The fix enforces a strict config whitelist (config/chatter.php
 * allowed_models) plus defence-in-depth (Model subclass, framework-namespace
 * rejection). These tests assert the whitelist via real HTTP requests.
 */
class HR75ChatterSubjectWhitelistTest extends TenantTestCase
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
            ['name' => 'HR75 Tenant', 'email' => 'hr75-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
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

    // URL-encode a FQCN for use as the {subjectType} path segment.
    private function enc(string $fqcn): string
    {
        return rawurlencode($fqcn);
    }

    // ─── Config whitelist sanity ────────────────────────────────────

    public function test_whitelist_config_exists_and_is_non_empty(): void
    {
        $allowed = config('chatter.allowed_models');
        $this->assertIsArray($allowed);
        $this->assertNotEmpty($allowed, 'chatter.allowed_models must be configured');
        $this->assertContains(\App\Models\Invoice::class, $allowed);
    }

    public function test_whitelist_contains_only_eloquent_models(): void
    {
        foreach (config('chatter.allowed_models', []) as $class) {
            $this->assertTrue(class_exists($class), "Whitelisted class {$class} must exist");
            $this->assertTrue(
                is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class),
                "Whitelisted class {$class} must be an Eloquent Model"
            );
        }
    }

    // ─── Attack vectors: arbitrary / framework classes rejected ─────

    public function test_framework_class_subject_is_rejected(): void
    {
        $user = $this->actingAsAdmin();

        // The exact PoC from the issue: Illuminate\Foundation\Application.
        $response = $this->actingAs($user)->getJson(
            '/api/v1/chatter/'.$this->enc('Illuminate\Foundation\Application').'/1'
        );
        $this->assertSame(422, $response->getStatusCode(), 'Framework class subject_type must be rejected 422');
    }

    public function test_arbitrary_non_model_class_subject_is_rejected(): void
    {
        $user = $this->actingAsAdmin();

        // A class that exists and autoloads but is NOT a whitelisted model.
        $response = $this->actingAs($user)->getJson(
            '/api/v1/chatter/'.$this->enc('App\Services\Chatter\ChatterService').'/1'
        );
        $this->assertSame(422, $response->getStatusCode(), 'Non-whitelisted class must be rejected 422');
    }

    public function test_nonexistent_class_subject_is_rejected(): void
    {
        $user = $this->actingAsAdmin();

        $response = $this->actingAs($user)->getJson(
            '/api/v1/chatter/'.$this->enc('App\Models\DoesNotExist'.Str::random(4)).'/1'
        );
        $this->assertSame(422, $response->getStatusCode(), 'Non-existent class must be rejected 422');
    }

    public function test_mail_transport_class_subject_is_rejected(): void
    {
        $user = $this->actingAsAdmin();

        // The issue cites mailer/cache classes with constructor side effects.
        $response = $this->actingAs($user)->getJson(
            '/api/v1/chatter/'.$this->enc('Illuminate\Mail\Transport\SendmailTransport').'/1'
        );
        $this->assertSame(422, $response->getStatusCode(), 'Mail transport class must be rejected 422');
    }

    // ─── Legitimate subjects pass the whitelist gate ────────────────

    public function test_whitelisted_invoice_subject_passes_validation(): void
    {
        $user = $this->actingAsAdmin();

        // A whitelisted model must NOT be rejected by validateSubject (422).
        // It may return 200 (empty bundle) — the point is the whitelist gate
        // passes, unlike the framework/arbitrary classes above.
        $response = $this->actingAs($user)->getJson(
            '/api/v1/chatter/'.$this->enc('App\Models\Invoice').'/1'
        );
        $this->assertNotSame(422, $response->getStatusCode(), 'Whitelisted Invoice subject must pass validateSubject');
    }

    public function test_whitelisted_sales_order_subject_passes_validation(): void
    {
        $user = $this->actingAsAdmin();

        $response = $this->actingAs($user)->getJson(
            '/api/v1/chatter/'.$this->enc('Modules\Sales\Models\SalesOrder').'/1'
        );
        $this->assertNotSame(422, $response->getStatusCode(), 'Whitelisted SalesOrder subject must pass validateSubject');
    }

    // ─── The broken src\Models path (issue #157) is correctly rejected ──

    public function test_broken_sales_src_model_path_is_rejected(): void
    {
        $user = $this->actingAsAdmin();

        // The frontend currently sends Modules\Sales\src\Models\SalesOrder
        // (issue #157). That is NOT the canonical class and is not whitelisted,
        // so it must be rejected — confirming the whitelist is strict.
        $response = $this->actingAs($user)->getJson(
            '/api/v1/chatter/'.$this->enc('Modules\Sales\src\Models\SalesOrder').'/1'
        );
        $this->assertSame(422, $response->getStatusCode(), 'Non-canonical src\Models path must be rejected (see #157)');
    }

    // ─── subject_id must be numeric ─────────────────────────────────

    public function test_non_numeric_subject_id_is_rejected(): void
    {
        $user = $this->actingAsAdmin();

        $response = $this->actingAs($user)->getJson(
            '/api/v1/chatter/'.$this->enc('App\Models\Invoice').'/abc'
        );
        // Route binds {subjectId}; a non-numeric value must not reach a
        // successful bundle. Either 422 (validateSubject) or 404 (route model
        // binding) is acceptable — but never a 200 with data.
        $this->assertContains($response->getStatusCode(), [422, 404], 'Non-numeric subject id must be rejected');
    }
}
