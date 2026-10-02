<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Services\ComplianceService;
use App\Models\Accounting\ComplianceDocument;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #812 EP1: a purchase bill (and a vendor debit note) is OUR purchase — it
 * must never be submitted to LHDN as if we were the seller. The route entry
 * points and the generic submit dispatch are disabled.
 */
class EinvPurchaseSubmissionDisabledTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@einv812.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_bill_submission_is_disabled(): void
    {
        $this->postJson('/api/v1/accounting/compliance/bills/999999/submit', ['country_code' => 'MY'])
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_vendor_debit_note_submission_is_disabled(): void
    {
        $this->postJson('/api/v1/accounting/compliance/vendor-debit-notes/999999/submit', ['country_code' => 'MY'])
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_the_generic_submit_dispatch_refuses_purchase_source_types(): void
    {
        // The guard is at the service dispatch, so every caller (generic submit,
        // bulk, internal) is covered — no LHDN call is attempted.
        foreach (['bill', 'vendor_debit_note'] as $type) {
            $document = new ComplianceDocument(['document_type' => $type]);
            $result = app(ComplianceService::class)->submitComplianceDocument($document, 'MY');

            $this->assertFalse($result['success'] ?? true, "A {$type} must not be submitted.");
            $this->assertStringContainsString('DISABLED_SOURCE_TYPE', (string) ($result['message'] ?? ''));
        }
    }
}
