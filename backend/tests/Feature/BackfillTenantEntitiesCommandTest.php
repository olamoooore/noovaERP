<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Tests\TenantTestCase;

/**
 * tenants:backfill-entities converges tenants whose documents predate legal
 * entities. Without it, granting a user entity access hides every NULL-entity
 * document (fail-closed accessibleBy), which looked like "delivery order not
 * found" in production until the demo tenant was repaired by hand.
 */
class BackfillTenantEntitiesCommandTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected User $user;

    protected int $entityId;

    protected int $secondEntityId;

    protected int $bookId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Entity Backfill Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::factory()->create();

        DB::table('entity_groups')->insert([
            'name' => 'Backfill Group',
            'code' => 'BG-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $groupId = (int) DB::table('entity_groups')->value('id');

        $this->entityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Main Entity',
            'code' => 'ENT-'.Str::upper(Str::random(5)),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->secondEntityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Second Entity',
            'code' => 'ENT-'.Str::upper(Str::random(5)),
            'country_code' => 'SG',
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bookId = DB::table('entity_books')->insertGetId([
            'legal_entity_id' => $this->entityId,
            'book_type' => 'general',
            'name' => 'Main Book',
            'currency_code' => 'MYR',
            'is_primary' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function nullEntityOrder(): DeliveryOrder
    {
        $customer = Customer::factory()->create();

        return DeliveryOrder::create([
            'customer_id' => $customer->id,
            'delivery_date' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_it_backfills_with_an_explicit_entity_and_assigns_the_book(): void
    {
        $order = $this->nullEntityOrder();

        // payments carry both legal_entity_id and entity_book_id (delivery
        // orders only have the entity column, so the book is asserted here).
        $paymentId = DB::table('payments')->insertGetId([
            'payment_number' => 'PAY-'.Str::upper(Str::random(8)),
            'customer_id' => $order->customer_id,
            'payment_date' => now()->toDateString(),
            'amount' => 10,
            'method' => 'cash',
            'received_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('tenants:backfill-entities', [
            '--tenant' => $this->tenantUuid,
            '--entity' => $this->entityId,
        ])->assertExitCode(0);

        $row = DB::table('delivery_orders')->where('id', $order->id)->first();
        $this->assertSame($this->entityId, (int) $row->legal_entity_id);

        if (\Schema::hasColumn('payments', 'entity_book_id')) {
            $payment = DB::table('payments')->where('id', $paymentId)->first();
            $this->assertSame($this->entityId, (int) $payment->legal_entity_id);
            $this->assertSame($this->bookId, (int) $payment->entity_book_id);
        }
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $order = $this->nullEntityOrder();

        $this->artisan('tenants:backfill-entities', [
            '--tenant' => $this->tenantUuid,
            '--entity' => $this->entityId,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertNull(DB::table('delivery_orders')->where('id', $order->id)->value('legal_entity_id'));
    }

    public function test_it_refuses_to_guess_between_multiple_entities(): void
    {
        $order = $this->nullEntityOrder();

        // Two active entities and no --entity: must fail, not pick one.
        $this->artisan('tenants:backfill-entities', [
            '--tenant' => $this->tenantUuid,
        ])->assertExitCode(1);

        $this->assertNull(DB::table('delivery_orders')->where('id', $order->id)->value('legal_entity_id'));
    }

    public function test_it_uses_the_only_entity_when_unambiguous(): void
    {
        DB::table('legal_entities')->where('id', $this->secondEntityId)->update(['status' => 'inactive']);

        $order = $this->nullEntityOrder();

        $this->artisan('tenants:backfill-entities', [
            '--tenant' => $this->tenantUuid,
        ])->assertExitCode(0);

        $row = DB::table('delivery_orders')->where('id', $order->id)->first();
        $this->assertSame($this->entityId, (int) $row->legal_entity_id);
    }
}
