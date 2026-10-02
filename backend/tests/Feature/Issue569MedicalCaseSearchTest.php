<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MedicalCase;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue #569 — the medical-case list search filtered on
 * customers.company_name, a column that does not exist (the customers table
 * has name/company), so any search term on the Medical Cases page returned
 * 500 on Postgres. Search must work on customers.name/company plus the case
 * number and patient reference.
 *
 * These tests go through the real GET /api/v1/medical-cases route (router →
 * middleware → controller), not a direct controller call.
 */
class Issue569MedicalCaseSearchTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '56956956-9596-4959-9959-695695695695',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['medical_cases', 'customers']);

        $this->user = User::factory()->create();

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeCase(array $customerAttributes, array $caseAttributes = []): MedicalCase
    {
        $customer = Customer::factory()->create($customerAttributes);

        return MedicalCase::create(array_merge([
            'customer_id' => $customer->id,
            'case_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ], $caseAttributes));
    }

    private function search(string $term): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->getJson('/api/v1/medical-cases?search='.urlencode($term));
    }

    /**
     * @return array<int, int>
     */
    private function resultIds(\Illuminate\Testing\TestResponse $response): array
    {
        return collect($response->json('data'))->pluck('id')->map('intval')->all();
    }

    public function test_search_by_customer_name_returns_the_case_instead_of_500(): void
    {
        $case = $this->makeCase(['name' => "Astra Women's Specialists"]);

        $response = $this->search('Astra Women');

        $response->assertOk();
        $this->assertContains($case->id, $this->resultIds($response));
    }

    public function test_search_by_customer_company_returns_the_case(): void
    {
        $case = $this->makeCase(['name' => 'Dr. Reyes', 'company' => 'Reyes Medical Group']);

        $response = $this->search('Reyes Medical');

        $response->assertOk();
        $this->assertContains($case->id, $this->resultIds($response));
    }

    public function test_search_by_case_number_and_patient_reference_still_work(): void
    {
        $case = $this->makeCase(
            ['name' => 'Unrelated Customer'],
            ['case_no' => 'CASE-569-0042', 'patient_reference' => 'PAT-569-XY'],
        );

        $byNumber = $this->search('CASE-569-0042');
        $byNumber->assertOk();
        $this->assertContains($case->id, $this->resultIds($byNumber));

        $byReference = $this->search('PAT-569-XY');
        $byReference->assertOk();
        $this->assertContains($case->id, $this->resultIds($byReference));
    }

    public function test_non_matching_search_returns_an_empty_200_list_not_a_missing_column_error(): void
    {
        $this->makeCase(['name' => 'Some Customer That Does Not Match']);

        $response = $this->search('zz-no-such-customer-or-case-9f8e7d');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }
}
