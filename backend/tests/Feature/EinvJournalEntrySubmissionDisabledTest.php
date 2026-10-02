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
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #813 EP2: a journal entry is not an e-invoice source document, and
 * self-billing does NOT mean buyer = supplier — the buyer keeps its own TIN.
 */
class EinvJournalEntrySubmissionDisabledTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@einv813.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_journal_entry_submission_is_disabled(): void
    {
        $this->postJson('/api/v1/accounting/compliance/journal-entries/999999/submit', ['country_code' => 'MY'])
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_the_generic_dispatch_refuses_journal_entries(): void
    {
        $document = new ComplianceDocument(['document_type' => 'journal_entry']);
        $result = app(ComplianceService::class)->submitComplianceDocument($document, 'MY');

        $this->assertFalse($result['success'] ?? true);
        $this->assertStringContainsString('DISABLED_SOURCE_TYPE', (string) ($result['message'] ?? ''));
    }

    public function test_a_self_billed_payload_keeps_distinct_supplier_and_buyer_tins(): void
    {
        $payload = [
            'document_type' => 'self_billed_invoice',
            'supplier' => ['tin' => 'SUPPLIER-TIN-001', 'name' => 'Foreign Vendor Sdn Bhd', 'address' => []],
            'buyer' => ['tin' => 'BUYER-TIN-002', 'name' => 'Our Company Sdn Bhd', 'address' => []],
        ];

        $service = app(ComplianceService::class);
        $method = new ReflectionMethod($service, 'applyProviderPayloadDefaults');
        $method->setAccessible(true);
        $result = $method->invoke($service, $payload, [], 'MY');

        $this->assertSame('SUPPLIER-TIN-001', $result['supplier']['tin']);
        $this->assertSame('BUYER-TIN-002', $result['buyer']['tin'], 'The buyer TIN must not be overwritten with the supplier TIN.');
        $this->assertSame('Our Company Sdn Bhd', $result['buyer']['name']);
    }
}
