<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Accounting\AccountingImportBatch;
use App\Models\Accounting\AccountingImportStaging;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CanonicalCustomerService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\ContactDuplicateCandidate;
use Tests\TenantTestCase;

/**
 * Issue #431 (CU3): accounting import auto-matched customers by name only —
 * two different same-name customers (two "Klinik Ali" branches) collapsed
 * into the first row and invoices were booked to the wrong customer.
 * ADR-003A VEN-AT-04 / VEN-NT-07: same name only → candidate, no auto-merge.
 *
 * Resolution now takes a strong key (customer_id, customer_code); a
 * name-only path may match exactly ONE customer or create a new one;
 * ambiguity is a dry-run row error with duplicate candidates recorded.
 */
class AccountingImportCustomerAmbiguityTest extends TenantTestCase
{
    private User $user;

    private string $tenantId;

    private CanonicalCustomerService $canonical;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->assertTenantSchemaReady([
            'customers', 'invoices', 'core_contacts', 'contact_duplicate_candidates',
            'accounting_import_batches', 'accounting_import_staging', 'accounting_import_audit',
        ]);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->canonical = app(CanonicalCustomerService::class);
    }

    private function stage(string $csv)
    {
        $file = new UploadedFile(
            tempnam(sys_get_temp_dir(), 'import'),
            'import.csv',
            'text/csv',
            null,
            true
        );
        file_put_contents($file->getRealPath(), $csv);

        return $this->actingAs($this->user)->post('/api/v1/accounting/import/stage', [
            'file' => $file,
            'import_type' => 'invoice',
        ], ['Accept' => 'application/json']);
    }

    private function invoiceCsv(string $name, string $number, string $extraHeader = '', string $extraValue = ''): string
    {
        $header = 'customer_name,invoice_number,invoice_date,total';
        $row = "{$name},{$number},2026-04-01,500.00";
        if ($extraHeader !== '') {
            $header .= ','.$extraHeader;
            $row .= ','.$extraValue;
        }

        return $header."\n".$row;
    }

    public function test_two_same_name_customers_are_flagged_as_ambiguous_with_duplicate_candidates_and_never_merged(): void
    {
        $branch1 = $this->canonical->create(['name' => 'Klinik Ali', 'code' => 'KA-001']);
        $branch2 = $this->canonical->create(['name' => 'Klinik Ali', 'code' => 'KA-002']);
        $this->assertNotSame($branch1->id, $branch2->id);

        $response = $this->stage($this->invoiceCsv('Klinik Ali', 'INV-431-A'));
        $response->assertCreated()
            ->assertJsonPath('data.error_rows', 1)
            ->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.status', 'pending', 'an ambiguous batch must not be validated for commit');

        $batchId = $response->json('data.batch_id');

        $staging = AccountingImportStaging::where('batch_id', $batchId)->firstOrFail();
        $this->assertSame('error', $staging->status, 'the dry-run report must flag the row');
        $errors = implode(' ', $staging->validation_errors ?? []);
        $this->assertStringContainsString('matches 2 existing customers', $errors);
        $this->assertStringContainsString('KA-001', $errors, 'the report must list the colliding customers');
        $this->assertStringContainsString('KA-002', $errors);
        $this->assertStringContainsString('flagged as a duplicate candidate', $errors);

        $candidate = ContactDuplicateCandidate::where('tenant_id', $this->tenantId)->sole();
        $this->assertSame('pending', $candidate->status, 'a human review candidate must exist instead of a merge');
        $this->assertSame('exact_name', $candidate->match_type);
        $this->assertSame(
            $branch1->core_contact_uuid < $branch2->core_contact_uuid
                ? [$branch1->core_contact_uuid, $branch2->core_contact_uuid]
                : [$branch2->core_contact_uuid, $branch1->core_contact_uuid],
            [$candidate->contact_id_1, $candidate->contact_id_2],
        );
        $this->assertSame('Klinik Ali', $candidate->match_details['customer_name']);
        $this->assertSame('accounting_import', $candidate->match_details['source']);
        $this->assertSame($batchId, $candidate->match_details['batch_id']);

        $this->assertSame(0, Invoice::count(), 'no invoice may be booked to either same-name customer');
        $this->assertNotSame(
            AccountingImportBatch::find($batchId)->status,
            'completed',
        );
    }

    public function test_a_single_name_match_still_resolves_and_imports_normally(): void
    {
        $clinic = $this->canonical->create(['name' => 'Sinar Clinic', 'code' => 'SC-001']);

        $staged = $this->stage($this->invoiceCsv('Sinar Clinic', 'INV-431-B'));
        $staged->assertCreated()
            ->assertJsonPath('data.error_rows', 0)
            ->assertJsonPath('data.status', 'validated');

        $batchId = $staged->json('data.batch_id');
        $commit = $this->actingAs($this->user)->postJson("/api/v1/accounting/import/{$batchId}/commit");
        $commit->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.rejected', 0);

        $invoice = Invoice::sole();
        $this->assertSame($clinic->id, $invoice->customer_id, 'the only match must be used — no candidate needed');
        $this->assertSame(0, ContactDuplicateCandidate::where('tenant_id', $this->tenantId)->count());
    }

    public function test_customer_code_is_a_strong_key_even_when_the_name_is_ambiguous(): void
    {
        $this->canonical->create(['name' => 'Maju Clinic', 'code' => 'MA-001']);
        $target = $this->canonical->create(['name' => 'Maju Clinic', 'code' => 'MA-002']);

        $staged = $this->stage($this->invoiceCsv('Maju Clinic', 'INV-431-C', 'customer_code', 'MA-002'));
        $staged->assertCreated()
            ->assertJsonPath('data.error_rows', 0)
            ->assertJsonPath('data.status', 'validated');

        $batchId = $staged->json('data.batch_id');
        $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/import/{$batchId}/commit")
            ->assertOk()
            ->assertJsonPath('data.imported', 1);

        $this->assertSame($target->id, Invoice::sole()->customer_id, 'the strong key must win over the ambiguous name');
        $this->assertSame(0, ContactDuplicateCandidate::where('tenant_id', $this->tenantId)->count());
    }

    public function test_commit_refuses_a_row_that_became_ambiguous_after_staging(): void
    {
        $first = $this->canonical->create(['name' => 'Reka Clinic', 'code' => 'RA-001']);

        $staged = $this->stage($this->invoiceCsv('Reka Clinic', 'INV-431-D'));
        $staged->assertCreated()->assertJsonPath('data.status', 'validated');
        $batchId = $staged->json('data.batch_id');

        // A second same-name customer appears between dry-run and commit:
        // the defense-in-depth path must still refuse the silent pick.
        $this->canonical->create(['name' => 'Reka Clinic', 'code' => 'RA-002']);

        $commit = $this->actingAs($this->user)->postJson("/api/v1/accounting/import/{$batchId}/commit");
        $commit->assertOk()
            ->assertJsonPath('data.imported', 0)
            ->assertJsonPath('data.rejected', 1);

        $staging = AccountingImportStaging::where('batch_id', $batchId)->firstOrFail();
        $this->assertSame('error', $staging->status);
        $this->assertStringContainsString('matches 2 existing customers', implode(' ', $staging->validation_errors ?? []));

        $this->assertSame(0, Invoice::count(), "the invoice must not be booked to {$first->code} silently");
        $this->assertSame(1, ContactDuplicateCandidate::where('tenant_id', $this->tenantId)->where('status', 'pending')->count());
    }
}
