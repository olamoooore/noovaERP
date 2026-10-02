<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #670 (EINV-UX1) — the invoice list can be filtered by e-invoice
 * status. The status itself lives on the compliance document (and, for an
 * open buyer rejection request, on the submission), so the filter is
 * translated server-side rather than filtered client-side per page.
 */
class EinvoiceListFilterTest extends TenantTestCase
{
    private User $user;

    private string $tenantId;

    private Customer $customer;

    /** @var array<string, int> invoice_number => invoice id */
    private array $invoices = [];

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->user = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();

        $this->invoices['bare'] = $this->invoice();
        $this->invoices['pending'] = $this->invoice();
        $this->invoices['accepted'] = $this->invoice();
        $this->invoices['failed'] = $this->invoice();
        $this->invoices['rejection'] = $this->invoice();

        $this->complianceDocument($this->invoices['pending'], 'pending');
        $this->complianceDocument($this->invoices['accepted'], 'accepted');
        $this->complianceDocument($this->invoices['failed'], 'failed');
        $rejectionDoc = $this->complianceDocument($this->invoices['rejection'], 'accepted');

        DB::table('acct_compliance_submissions')->insert([
            'tenant_id' => $this->tenantId,
            'document_id' => $rejectionDoc,
            'country_code' => 'MY',
            'submission_status' => 'rejection_requested',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function invoice(): int
    {
        return Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
        ])->id;
    }

    private function complianceDocument(int $invoiceId, string $status): int
    {
        return DB::table('acct_compliance_documents')->insertGetId([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'document_type' => 'invoice',
            'document_id' => $invoiceId,
            'document_number' => 'EINV-'.Str::upper(Str::random(8)),
            'issued_date' => now()->toDateString(),
            'total_amount' => 100,
            'currency_code' => 'MYR',
            'compliance_status' => $status,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<int>
     */
    private function idsFor(string $filter): array
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/invoices?per_page=50&einvoice_status='.urlencode($filter));

        $response->assertOk();

        return array_map('intval', array_column($response->json('data'), 'id'));
    }

    private function assertOnly(string $filter, string ...$expectedKeys): void
    {
        $expected = array_map(fn (string $key) => $this->invoices[$key], $expectedKeys);
        $actual = $this->idsFor($filter);

        sort($expected);
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            "einvoice_status={$filter} must match exactly the expected invoices, got: ".json_encode($actual)
        );
    }

    public function test_filter_by_not_submitted_matches_invoices_without_a_compliance_document(): void
    {
        $this->assertOnly('not_submitted', 'bare');
    }

    public function test_filter_by_queued_matches_pending_documents(): void
    {
        $this->assertOnly('queued', 'pending');
    }

    public function test_filter_by_valid_matches_accepted_documents(): void
    {
        $this->assertOnly('valid', 'accepted');
    }

    public function test_filter_by_invalid_matches_failed_and_rejected_documents(): void
    {
        $doc = $this->complianceDocument($this->invoices['bare'], 'rejected');
        $this->assertNotNull($doc);

        // 'bare' now has a rejected document, so it is no longer 'not_submitted'.
        $this->assertOnly('invalid', 'failed', 'bare');
    }

    public function test_filter_by_buyer_rejection_requested_matches_the_submission(): void
    {
        $this->assertOnly('buyer_rejection_requested', 'rejection');
    }

    public function test_filter_accepts_multiple_values(): void
    {
        $this->assertOnly('valid,invalid', 'accepted', 'failed');
    }
}
