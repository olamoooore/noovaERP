<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #439 (CU11): the customer list passed raw `sort_by` / `sort_dir`
 * request values straight into orderBy():
 *
 *     $query->orderBy($request->sort_by ?? 'name', $request->sort_dir ?? 'asc');
 *
 * An unknown column made Postgres raise SQLSTATE 42703 and a bad direction a
 * syntax error — both surfaced as HTTP 500 instead of a 422 validation error.
 *
 * The contract now is: sort_by must be one of the allow-listed customer
 * columns, sort_dir must be asc|desc, and anything else is a 422.
 *
 * All checks go through the real HTTP route (router -> middleware -> controller).
 */
class Issue439CustomerSortValidationTest extends TenantTestCase
{
    /**
     * The public sorting contract of GET /api/v1/customers. Hardcoded here on
     * purpose: if the controller narrows the list, this test must fail.
     */
    private const ALLOWED_SORT_COLUMNS = [
        'id',
        'name',
        'code',
        'email',
        'phone',
        'mobile',
        'company',
        'tax_number',
        'type',
        'credit_limit',
        'payment_terms',
        'total_outstanding',
        'total_purchases',
        'total_orders',
        'customer_since',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected string $tenantUuid;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Issue 439 Sort Tenant',
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

    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create(array_merge([
            'code' => 'VC-'.Str::upper(Str::random(5)),
            'type' => 'company',
        ], $attributes));
    }

    private function listCustomers(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/customers'.($query !== '' ? '?'.$query : ''));
    }

    public function test_every_allow_listed_sort_column_is_accepted(): void
    {
        $this->customer();

        foreach (self::ALLOWED_SORT_COLUMNS as $column) {
            $response = $this->listCustomers('sort_by='.$column);

            $this->assertSame(
                200,
                $response->status(),
                "sort_by={$column} is a customer column and must be accepted, got {$response->status()}."
            );
        }
    }

    public function test_unknown_sort_column_returns_422_not_500(): void
    {
        $this->customer();

        $response = $this->listCustomers('sort_by=not_a_real_column');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('sort_by');
    }

    public function test_invalid_sort_direction_returns_422_not_500(): void
    {
        $this->customer();

        $response = $this->listCustomers('sort_by=name&sort_dir=sideways');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('sort_dir');
    }

    public function test_invalid_sort_direction_alone_returns_422(): void
    {
        $this->customer();

        $response = $this->listCustomers('sort_dir=sideways');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('sort_dir');
    }

    public function test_desc_direction_orders_descending(): void
    {
        $this->customer(['name' => 'Alpha Customer']);
        $this->customer(['name' => 'Beta Customer']);
        $this->customer(['name' => 'Gamma Customer']);

        $names = collect(
            $this->listCustomers('sort_by=name&sort_dir=desc')->assertOk()->json('data')
        )->pluck('name')->all();

        $this->assertSame(['Gamma Customer', 'Beta Customer', 'Alpha Customer'], $names);
    }

    public function test_asc_direction_orders_ascending(): void
    {
        $this->customer(['name' => 'Beta Customer']);
        $this->customer(['name' => 'Alpha Customer']);
        $this->customer(['name' => 'Gamma Customer']);

        $names = collect(
            $this->listCustomers('sort_by=name&sort_dir=asc')->assertOk()->json('data')
        )->pluck('name')->all();

        $this->assertSame(['Alpha Customer', 'Beta Customer', 'Gamma Customer'], $names);
    }

    public function test_default_sort_remains_name_ascending(): void
    {
        $this->customer(['name' => 'Beta Customer']);
        $this->customer(['name' => 'Alpha Customer']);

        $names = collect(
            $this->listCustomers()->assertOk()->json('data')
        )->pluck('name')->all();

        $this->assertSame(['Alpha Customer', 'Beta Customer'], $names);
    }

    /**
     * The customers list UI sends sort_by=created_at&sort_dir=desc, so this
     * exact request (not just its status) is pinned down.
     */
    public function test_created_at_desc_is_the_ui_sort_and_orders_newest_first(): void
    {
        $older = $this->customer(['name' => 'Older Customer']);
        $older->forceFill(['created_at' => now()->subDay()])->save();

        $newer = $this->customer(['name' => 'Newer Customer']);
        $newer->forceFill(['created_at' => now()])->save();

        $ids = collect(
            $this->listCustomers('sort_by=created_at&sort_dir=desc')->assertOk()->json('data')
        )->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([(int) $newer->id, (int) $older->id], $ids);
    }
}
