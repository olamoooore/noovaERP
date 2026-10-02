<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\MedicalCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;
use Modules\Inventory\Models\WarehouseTransfer;
use Tests\TenantTestCase;

/**
 * Audit of the remaining list searches:
 *
 *  - the medical-case list searched `customers.company_name`, a column that
 *    does not exist (customers has name/company), so ANY search term errored
 *    on Postgres. It now searches the customer's full surface.
 *  - the warehouse-transfer list matched only its number; it now also matches
 *    notes and the source/destination warehouse names.
 */
class SearchAuditFixTest extends TenantTestCase
{
    private User $user;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Search Audit Tenant',
            'email' => 'search-audit@example.com',
            'plan' => 'standard',
        ]));
        $this->bindTenantContext($this->tenantId);

        $this->user = User::create([
            'name' => 'Tenant User',
            'email' => 'search-audit-user@test.local',
            'password' => bcrypt('password'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function search(string $path, string $term): array
    {
        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->getJson($path.'?search='.urlencode($term).'&per_page=50')
            ->assertOk();

        return $response->json('data') ?? [];
    }

    public function test_medical_case_search_matches_customer_name_not_a_missing_column(): void
    {
        $customer = Customer::factory()->create(['name' => "Astra Women's Specialists"]);
        $case = MedicalCase::create([
            'customer_id' => $customer->id,
            'case_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $ids = collect($this->search('/api/v1/medical-cases', 'Astra Women'))->pluck('id')->map('intval')->all();
        $this->assertContains($case->id, $ids, 'Searching the customer name must find the case (no company_name column).');
    }

    public function test_medical_case_search_matches_case_contact_person(): void
    {
        $customer = Customer::factory()->create(['name' => 'Astra Specialists']);
        $doctor = CustomerContact::create([
            'customer_id' => $customer->id,
            'name' => 'Dr. Gregory House',
            'contact_category' => 'doctor',
            'contact_type' => 'doctor',
        ]);
        $case = MedicalCase::create([
            'customer_id' => $customer->id,
            'doctor_contact_id' => $doctor->id,
            'case_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $ids = collect($this->search('/api/v1/medical-cases', 'Gregory House'))->pluck('id')->map('intval')->all();
        $this->assertContains($case->id, $ids);
    }

    public function test_warehouse_transfer_search_matches_warehouse_names_and_notes(): void
    {
        $from = Warehouse::create(['name' => 'Central Store', 'code' => 'CS-'.Str::random(4), 'is_active' => true]);
        $to = Warehouse::create(['name' => 'Novena Outlet', 'code' => 'NO-'.Str::random(4), 'is_active' => true]);

        $transfer = WarehouseTransfer::create([
            'transfer_number' => 'TRF-'.Str::upper(Str::random(6)),
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'status' => 'draft',
            'transfer_date' => now()->toDateString(),
            'notes' => 'Urgent restock',
            'created_by' => $this->user->id,
        ]);

        $byWarehouse = collect($this->search('/api/v1/warehouse-transfers', 'Novena Outlet'))->pluck('id')->all();
        $this->assertContains($transfer->id, $byWarehouse);

        $byNotes = collect($this->search('/api/v1/warehouse-transfers', 'Urgent restock'))->pluck('id')->all();
        $this->assertContains($transfer->id, $byNotes);
    }
}
