<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Support\ContactTaxonomyRegistry;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #1037: the customer import must judge contact_category/contact_type
 * against the TENANT's vocabulary (#123 made healthcare codes per-tenant).
 * A value the tenant does not have is a staging `warning` and is rejected
 * at commit for that row only — it must not abort the whole commit.
 */
class Issue1037ImportTenantVocabularyTest extends TenantTestCase
{
    private string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = (string) Str::uuid();
        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Import Vocabulary Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantUuid);

        Role::firstOrCreate(['name' => 'admin']);
        Permission::firstOrCreate(['name' => 'customers.write']);
        Permission::firstOrCreate(['name' => 'customers.view']);

        // A generic (non-healthcare) tenant: CLINICAL is not in its vocabulary.
        Setting::setValue(ContactTaxonomyRegistry::HEALTHCARE_SETTING_KEY, false);
    }

    public function test_a_value_outside_the_tenant_vocabulary_rejects_its_row_not_the_whole_commit(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo('customers.write');
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        $csv = "name,code,contact_name,contact_category,contact_type\n".
            "Acme Corp,VC0001,John Doe,Clinical,Primary\n".
            'Bayu Trading,VC0002,Siti Aminah,Finance,Primary';

        $stage = $this->withHeaders($headers + ['Content-Type' => 'multipart/form-data'])
            ->postJson('/api/v1/accounting/customers/import', ['file' => $this->csvFile($csv)])
            ->assertOk();
        $batchId = $stage->json('batch_id');

        $rows = DB::table('contact_import_staging')->where('batch_id', $batchId)->orderBy('row_number')->get(['status', 'errors']);
        $this->assertSame('warning', $rows[0]->status, 'Staging flags a category the tenant does not have.');
        $this->assertStringContainsString('Clinical', (string) $rows[0]->errors);
        $this->assertSame('valid', $rows[1]->status);

        $commit = $this->withHeaders($headers)
            ->postJson("/api/v1/accounting/customers/import/{$batchId}/commit")
            ->assertOk();
        $this->assertSame(1, $commit->json('imported'));
        $this->assertSame(1, $commit->json('rejected'));
        // Row numbers count the header row, like the spreadsheet the user has open.
        $this->assertSame(2, $commit->json('rejections.0.row_number'));
        $this->assertStringContainsString('contact_category: Clinical', (string) $commit->json('rejections.0.reason'));

        $this->assertDatabaseHas('customers', ['code' => 'VC0002', 'name' => 'Bayu Trading']);
        $this->assertDatabaseMissing('customers', ['code' => 'VC0001']);
    }

    /**
     * Hostile review of the fix: a row that fails during COMMIT for a
     * business reason (here a batch staged BEFORE the fix, still carrying
     * CLINICAL as `valid`) must reject only that row — its writes rolled back
     * — and the rest of the batch must still import.
     */
    public function test_a_row_failing_at_commit_is_rejected_alone_and_rolled_back(): void
    {
        $headers = $this->authorisedHeaders();
        $csv = "name,code,contact_name,contact_category,contact_type\n".
            "Acme Corp,VC0001,John Doe,Finance,Primary\n".
            'Bayu Trading,VC0002,Siti Aminah,Finance,Primary';

        $batchId = $this->withHeaders($headers + ['Content-Type' => 'multipart/form-data'])
            ->postJson('/api/v1/accounting/customers/import', ['file' => $this->csvFile($csv)])
            ->assertOk()->json('batch_id');

        // What a batch staged before the fix looks like: CLINICAL resolved
        // from the static mapping and the row marked commit-ready.
        $first = DB::table('contact_import_staging')->where('batch_id', $batchId)->orderBy('row_number')->first();
        $mapped = json_decode((string) $first->mapped_data, true);
        $mapped['_taxonomy']['functional_category'] = 'CLINICAL';
        $mapped['_taxonomy']['functional_category_label'] = 'Clinical';
        $mapped['_taxonomy']['unknown'] = [];
        DB::table('contact_import_staging')->where('id', $first->id)->update(['mapped_data' => json_encode($mapped), 'status' => 'valid']);

        $commit = $this->withHeaders($headers)
            ->postJson("/api/v1/accounting/customers/import/{$batchId}/commit")
            ->assertOk();
        $this->assertSame(1, $commit->json('imported'));
        $this->assertSame(1, $commit->json('rejected'));

        // The response says WHY, per row, and does not read as a clean import.
        $this->assertSame('Import committed: 1 imported, 1 rejected.', $commit->json('message'));
        $this->assertSame([[
            'row_number' => (int) $first->row_number,
            'reason' => 'Rejected at COMMIT: UNKNOWN_TAXONOMY_VALUE (contact_category: Finance, contact_type: Primary)',
        ]], $commit->json('rejections'));

        $row = DB::table('contact_import_staging')->where('id', $first->id)->first();
        $this->assertSame('error', $row->status);
        $this->assertStringContainsString('UNKNOWN_TAXONOMY_VALUE', (string) $row->errors);
        $this->assertDatabaseMissing('customers', ['code' => 'VC0001']);
        $this->assertDatabaseHas('customers', ['code' => 'VC0002', 'name' => 'Bayu Trading']);
    }

    /** @return array<string, string> */
    private function authorisedHeaders(): array
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo('customers.write');
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function csvFile(string $csv): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($path, $csv);

        return new UploadedFile($path, 'customers.csv', 'text/csv', null, true);
    }
}
