<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\AccountingCountry\Singapore\Services\PeppolSubmissionProvider;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #99 — the Singapore Peppol provider must fail closed: no example.com
 * endpoint fallback, no unsigned submissions, and capability reporting that
 * tells the truth about what is configured.
 */
class Issue99PeppolFailClosedTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '99999999-0909-1919-2929-393939393939',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['invoices']);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // No Peppol endpoint configured in this environment.
        config([
            'compliance.singapore.peppol_test_endpoint' => null,
            'compliance.singapore.peppol_endpoint' => null,
        ]);
    }

    private function payload(): array
    {
        return [
            'custom_id' => 'INV-99-001',
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-30',
            'currency' => 'SGD',
            'subtotal' => 100,
            'tax_amount' => 9,
            'total' => 109,
            'supplier' => ['name' => 'Supplier Pte Ltd', 'registration' => '202600001A'],
            'customer' => ['name' => 'Customer Pte Ltd', 'registration' => '202600002B'],
            'lines' => [
                ['id' => 1, 'description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100],
            ],
        ];
    }

    public function test_an_unconfigured_provider_fails_closed_without_any_http_call(): void
    {
        Http::fake();

        $provider = new PeppolSubmissionProvider;

        $this->assertFalse($provider->isConfigured());

        $result = $provider->submit($this->payload(), ['api_key' => 'x', 'signing_key' => 'key']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not configured', $result['message']);
        Http::assertNothingSent();
    }

    public function test_placeholder_example_endpoints_are_not_treated_as_configured(): void
    {
        config(['compliance.singapore.peppol_test_endpoint' => 'https://test.peppol.example.com']);

        $provider = new PeppolSubmissionProvider;

        $this->assertFalse($provider->isConfigured(), 'The example.com placeholder must never count as configured.');
    }

    public function test_a_configured_provider_still_refuses_unsigned_submissions(): void
    {
        Http::fake();
        config(['compliance.singapore.peppol_test_endpoint' => 'https://ap.real-peppol.test']);

        $provider = new PeppolSubmissionProvider;
        $this->assertTrue($provider->isConfigured());

        // No signing key -> refused.
        $unsigned = $provider->submit($this->payload(), ['api_key' => 'x']);
        $this->assertFalse($unsigned['success']);
        $this->assertStringContainsString('unsigned', $unsigned['message']);

        // Signing key present but no XMLDSig library installed -> refused.
        $result = $provider->submit($this->payload(), ['api_key' => 'x', 'signing_key' => 'key']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('signing', strtolower($result['message']));
        $this->assertFalse($provider->signingAvailable());

        Http::assertNothingSent();
    }

    public function test_capability_endpoint_reports_singapore_as_unavailable(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/accounting/e-invoicing/capabilities');

        $response->assertOk();

        $singapore = collect($response->json('data'))->firstWhere('country', 'SG');

        $this->assertNotNull($singapore);
        $this->assertFalse($singapore['configured']);
        $this->assertFalse($singapore['signing_available']);
        $this->assertFalse($singapore['submission_available']);
    }
}
