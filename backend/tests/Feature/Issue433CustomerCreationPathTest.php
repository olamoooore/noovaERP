<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\CanonicalCustomerService;
use App\Services\CustomerContactSyncService;
use App\Services\CustomerImportService;
use App\Services\DocumentSnapshotService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\ContactImportBatch;
use Tests\TenantTestCase;

/**
 * Issue #433 (CU5): parallel customer creation paths and a client-named
 * importer in shared code.
 *
 * Acceptance criteria under test, through the real router:
 *  1. Every customer creation goes through CanonicalCustomerService —
 *     including POST /api/v1/customers and the staged importer's commit.
 *  2. The importer is the generic CustomerImportService; the client-specific
 *     source system/mapping lives in config as a profile, not in a class name.
 *  3. /accounting/customers stays as the accounting surface, but its import
 *     delegates to the generic service and creates through the canonical path
 *     (no second creation implementation).
 */
class Issue433CustomerCreationPathTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Issue 433 Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function spyOnCanonicalService(): RecordingCanonicalCustomerService
    {
        $spy = new RecordingCanonicalCustomerService(
            app(DocumentSnapshotService::class),
            app(CustomerContactSyncService::class),
        );

        $this->app->instance(CanonicalCustomerService::class, $spy);

        return $spy;
    }

    private function csvFile(string $csv): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'customer-import');
        file_put_contents($path, $csv);

        return new UploadedFile($path, 'customers.csv', 'text/csv', null, true);
    }

    public function test_customer_store_creates_through_the_canonical_service(): void
    {
        $spy = $this->spyOnCanonicalService();

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/customers', [
            'name' => 'Canonical Only Trading',
            'type' => 'company',
            'email' => 'canonical@example.test',
            'addresses' => [[
                'type' => 'billing',
                'street' => '1 Canonical Way',
                'city' => 'Singapore',
            ]],
            'contacts' => [[
                'name' => 'Jane Contact',
                'email' => 'jane@example.test',
                'is_primary' => true,
            ]],
        ])->assertCreated();

        $this->assertCount(
            1,
            $spy->createCalls,
            'POST /api/v1/customers must create the customer through CanonicalCustomerService (SOP §4), '
            .'not Customer::create directly.'
        );

        $customerId = $response->json('id');
        $this->assertNotNull(
            Customer::findOrFail($customerId)->core_contact_uuid,
            'The canonical service must link the Contact Master organisation contact.'
        );
        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customerId,
            'street' => '1 Canonical Way',
        ]);
        $this->assertDatabaseHas('customer_contacts', [
            'customer_id' => $customerId,
            'name' => 'Jane Contact',
        ]);
    }

    public function test_import_stages_under_the_generic_importer_and_default_profile(): void
    {
        $this->assertTrue(
            class_exists(CustomerImportService::class),
            'The customer importer must be the generic CustomerImportService, not a client-named class.'
        );

        $this->assertFileDoesNotExist(
            app_path('Services/AcmeCustomerImportService.php'),
            'Client-specific importer class names must not exist in shared code; they belong in config profiles.'
        );

        $controller = app(\App\Http\Controllers\Api\CustomersController::class);
        $importServiceType = (new \ReflectionMethod($controller, 'import'))
            ->getParameters()[1]->getType()->getName();
        $this->assertSame(
            CustomerImportService::class,
            $importServiceType,
            'The import endpoint must depend on the generic CustomerImportService.'
        );

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/accounting/customers/import', [
                'file' => $this->csvFile("code,name,email\nGEN-100,Generic Import Co,generic.import@example.test\n"),
            ])
            ->assertOk();

        $batch = ContactImportBatch::query()->first();
        $this->assertNotNull($batch);
        $this->assertSame(
            'customer_import',
            $batch->source_system,
            'The generic customer import must stage under the generic profile source system, '
            .'not the client-specific acme_customer_master.'
        );
    }

    public function test_import_commit_creates_customer_through_the_canonical_service(): void
    {
        $spy = $this->spyOnCanonicalService();

        $staged = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/accounting/customers/import', [
                'file' => $this->csvFile("code,name,email\nGEN-200,Generic Commit Co,commit@example.test\n"),
            ])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/accounting/customers/import/'.$staged->json('batch_id').'/commit')
            ->assertOk();

        $this->assertCount(
            1,
            $spy->createCalls,
            'The importer must create customers through CanonicalCustomerService, not a parallel write path.'
        );

        $customer = Customer::where('code', 'GEN-200')->firstOrFail();
        $this->assertNotNull($customer->core_contact_uuid);
    }
}

final class RecordingCanonicalCustomerService extends CanonicalCustomerService
{
    /** @var list<array{data: array<string, mixed>, relations: array<string, mixed>}> */
    public array $createCalls = [];

    public function create(array $customerData, array $relations = []): Customer
    {
        $this->createCalls[] = ['data' => $customerData, 'relations' => $relations];

        return parent::create($customerData, $relations);
    }
}
