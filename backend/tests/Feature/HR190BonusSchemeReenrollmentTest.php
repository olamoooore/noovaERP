<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BonusScheme;
use App\Models\Customer;
use App\Models\CustomerBonusScheme;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #190 — store() blocked re-enrolment whenever any prior row existed for
 * (customer, scheme), and the DB unique constraint rejects a second row, so a
 * customer suspended/expired from a scheme could never be re-assigned.
 */
class HR190BonusSchemeReenrollmentTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private BonusScheme $scheme;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '77777777-6666-5555-4444-333333333333',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['bonus_schemes', 'customer_bonus_schemes', 'customers']);

        $this->user = User::factory()->create();

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
        $this->scheme = BonusScheme::create([
            'scheme_code' => 'HR190-'.Str::upper(Str::random(5)),
            'name' => 'HR190 Scheme',
            'start_date' => '2026-01-01',
            'created_by' => $this->user->id,
        ]);
    }

    private function enroll(string $startDate = '2026-01-01')
    {
        return $this->actingAs($this->user)->postJson('/api/v1/customer-bonus-schemes', [
            'customer_id' => $this->customer->id,
            'bonus_scheme_id' => $this->scheme->id,
            'start_date' => $startDate,
        ]);
    }

    public function test_suspended_assignment_can_be_reenrolled_with_new_terms(): void
    {
        $created = $this->enroll();
        $created->assertCreated();
        $assignmentId = $created->json('data.id');

        $this->actingAs($this->user)
            ->postJson('/api/v1/customer-bonus-schemes/'.$assignmentId.'/suspend')
            ->assertOk();

        $reenrolled = $this->enroll('2026-06-01');
        $reenrolled->assertOk();
        $reenrolled->assertJsonPath('data.id', $assignmentId);
        $reenrolled->assertJsonPath('data.status', 'active');
        $this->assertStringStartsWith('2026-06-01', (string) $reenrolled->json('data.start_date'));

        // History is preserved in place: exactly one row for the pair.
        $this->assertSame(1, CustomerBonusScheme::where('customer_id', $this->customer->id)
            ->where('bonus_scheme_id', $this->scheme->id)
            ->count());
    }

    public function test_expired_assignment_can_be_reenrolled(): void
    {
        $created = $this->enroll();
        $assignmentId = $created->json('data.id');

        CustomerBonusScheme::find($assignmentId)->update(['status' => 'expired']);

        $reenrolled = $this->enroll('2026-07-01');
        $reenrolled->assertOk();
        $reenrolled->assertJsonPath('data.id', $assignmentId);
        $reenrolled->assertJsonPath('data.status', 'active');
    }

    public function test_active_assignment_is_still_rejected(): void
    {
        $this->enroll()->assertCreated();

        $this->enroll('2026-06-01')->assertStatus(422);
    }
}
