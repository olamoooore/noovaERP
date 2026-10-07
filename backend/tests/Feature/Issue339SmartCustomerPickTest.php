<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\LegalEntity;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TagService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #339 (D1 Smart Customer Pick): one customer search matches company,
 * contact person (name / email / phone), tag or address, and every result row
 * carries the id of the matched contact or address so ONE pick can fill the
 * customer AND that contact/address on the document header.
 *
 * The match payload stays inside the requester's legal-entity scope (#434) and
 * carries no contact email/phone or bank fields (#444/#567 masking).
 *
 * All checks go through the real router: GET /api/v1/customers?search=.
 */
class Issue339SmartCustomerPickTest extends TenantTestCase
{
    private string $tenantId;

    private int $entityA;

    private int $entityB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => $this->tenantId,
            'name' => 'Issue 339 Smart Pick Tenant',
        ]));

        $this->bindTenantContextWithPermissions($this->tenantId);

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'S339 Group',
            'code' => 'S339-'.Str::upper(Str::random(4)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityA = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Entity A 339',
            'code' => 'A339-'.Str::upper(Str::random(4)),
            'status' => 'active',
        ])->id;

        $this->entityB = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Entity B 339',
            'code' => 'B339-'.Str::upper(Str::random(4)),
            'status' => 'active',
        ])->id;
    }

    private function makeUser(string $role = 'manager', int ...$entityIds): User
    {
        $user = User::factory()->create();

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $role, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $user->assignRole(UserRole::tryFrom($role)->spatieName());

        foreach ($entityIds as $index => $entityId) {
            DB::table('user_entity_access')->insert([
                'user_id' => $user->id,
                'legal_entity_id' => $entityId,
                'role' => 'viewer',
                'is_default' => $index === 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create(array_merge([
            'code' => 'S339-'.Str::upper(Str::random(5)),
            'type' => 'company',
            'is_active' => true,
        ], $attributes));
    }

    private function search(User $user, string $term): array
    {
        return collect(
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/customers?per_page=50&search='.urlencode($term))
                ->assertOk()
                ->json('data')
        )->keyBy('id')->all();
    }

    public function test_contact_matched_by_email_or_phone_carries_its_id_and_a_pick_row(): void
    {
        $user = $this->makeUser();
        $acme = $this->customer(['name' => 'ACME Trading 339']);
        $john = CustomerContact::create([
            'customer_id' => $acme->id,
            'name' => 'John Tan',
            'email' => 'john.tan339@acme.example',
            'phone' => '+60 12 339 0001',
            'job_title' => 'Buyer',
            'contact_category' => 'billing',
        ]);
        CustomerContact::create([
            'customer_id' => $acme->id,
            'name' => 'Mary Lim',
            'email' => 'mary@acme.example',
        ]);

        foreach (['john.tan339@acme', '339 0001', 'John Tan'] as $term) {
            $row = $this->search($user, $term)[$acme->id] ?? null;
            $this->assertNotNull($row, "Searching '{$term}' must find the customer.");
            $this->assertSame(
                [(int) $john->id],
                array_map('intval', $row['matched_contact_ids'] ?? []),
                "Searching '{$term}' must report exactly the matched contact's id so one pick selects it."
            );

            $picks = collect($row['matched_picks'] ?? []);
            $contactPick = $picks->firstWhere('kind', 'contact');
            $this->assertNotNull($contactPick, "Searching '{$term}' must expose a contact pick row.");
            $this->assertSame((int) $john->id, (int) $contactPick['contact_id']);
            $this->assertSame('John Tan', $contactPick['contact_name']);
            // #444/#567: the pick row never echoes contact email/phone.
            $this->assertArrayNotHasKey('email', $contactPick);
            $this->assertArrayNotHasKey('phone', $contactPick);
        }
    }

    public function test_address_match_carries_the_address_id_and_type(): void
    {
        $user = $this->makeUser();
        $acme = $this->customer(['name' => 'ACME Holdings 339']);
        CustomerAddress::create([
            'customer_id' => $acme->id,
            'type' => 'billing',
            'label' => 'Head Office',
            'street' => '1 Jalan Ampang',
            'city' => 'Singapore',
            'is_default' => true,
        ]);
        $kl = CustomerAddress::create([
            'customer_id' => $acme->id,
            'type' => 'shipping',
            'label' => 'KL Warehouse 339',
            'street' => '8 Jalan Klang',
            'city' => 'Kuala Lumpur',
            'is_default' => false,
        ]);

        $row = $this->search($user, 'KL Warehouse 339')[$acme->id] ?? null;

        $this->assertNotNull($row);
        $this->assertSame([(int) $kl->id], array_map('intval', $row['matched_address_ids'] ?? []));

        $addressPick = collect($row['matched_picks'] ?? [])->firstWhere('kind', 'address');
        $this->assertNotNull($addressPick, 'An address match must expose an address pick row.');
        $this->assertSame((int) $kl->id, (int) $addressPick['address_id']);
        $this->assertSame('shipping', $addressPick['address_type']);
        $this->assertSame('Kuala Lumpur', $addressPick['city']);
    }

    public function test_company_and_tag_matches_carry_no_contact_or_address_pick(): void
    {
        $user = $this->makeUser();
        $acme = $this->customer(['name' => 'Smartpick Company 339']);
        CustomerContact::create(['customer_id' => $acme->id, 'name' => 'Unrelated Person']);
        app(TagService::class)->attach($acme, ['Gold339']);

        foreach (['Smartpick Company 339', 'Gold339'] as $term) {
            $row = $this->search($user, $term)[$acme->id] ?? null;
            $this->assertNotNull($row, "Searching '{$term}' must find the customer.");
            $this->assertSame([], $row['matched_contact_ids'] ?? null);
            $this->assertSame([], $row['matched_address_ids'] ?? null);
            $this->assertSame([], $row['matched_picks'] ?? null);
        }
    }

    public function test_out_of_scope_customers_contacts_and_addresses_never_surface(): void
    {
        $scoped = $this->makeUser('manager', $this->entityA);
        $inScope = $this->customer(['name' => 'Scope A Co', 'legal_entity_id' => $this->entityA]);
        $outOfScope = $this->customer(['name' => 'Scope B Co', 'legal_entity_id' => $this->entityB]);

        CustomerContact::create(['customer_id' => $inScope->id, 'name' => 'Zebra Contact 339']);
        $hidden = CustomerContact::create(['customer_id' => $outOfScope->id, 'name' => 'Zebra Contact 339 B']);
        CustomerAddress::create([
            'customer_id' => $outOfScope->id, 'type' => 'shipping', 'label' => 'Zebra Contact 339 Depot', 'city' => 'Penang',
        ]);

        $rows = $this->search($scoped, 'Zebra Contact 339');

        $this->assertArrayHasKey($inScope->id, $rows);
        $this->assertArrayNotHasKey($outOfScope->id, $rows, '#434: an out-of-entity customer must not be found via its contact/address.');
        $allPickContactIds = collect($rows)->flatMap(fn ($row) => collect($row['matched_picks'] ?? [])->pluck('contact_id'))->filter()->all();
        $this->assertNotContains((int) $hidden->id, array_map('intval', $allPickContactIds));
    }
}
