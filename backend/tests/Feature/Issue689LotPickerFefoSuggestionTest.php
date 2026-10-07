<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #689 (INV-UX3): the invoice / delivery-order lot-serial picker lists
 * the available lots in the warehouse with expiry and quantity, pre-selects
 * the FEFO lot (earliest expiry that has not passed), never suggests an
 * expired lot, and flags near-expiry lots so the editor can highlight them.
 * The picker reads GET /api/v1/products/{id}/tracking-availability.
 */
class Issue689LotPickerFefoSuggestionTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            [
                'name' => 'Lot Picker Tenant',
                'email' => 'lot-picker-'.substr(md5($this->tenantId), 0, 8).'@example.com',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->bindTenantContextWithPermissions(
            $this->tenantId,
            null,
            UserRole::ADMIN->spatieName(),
            autoAssignRole: false,
        );

        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => UserRole::ADMIN->value, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole(UserRole::ADMIN->spatieName());
        $this->admin = $user;

        $this->warehouse = Warehouse::create([
            'name' => 'Picker Warehouse',
            'code' => 'PICK-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function batch(Product $product, string $number, ?string $expiry, int $qty = 10, int $reserved = 0, ?string $received = null): ProductBatch
    {
        return ProductBatch::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => $number,
            'quantity' => $qty,
            'quantity_reserved' => $reserved,
            'expiry_date' => $expiry,
            'received_date' => $received,
            'status' => ProductBatch::STATUS_AVAILABLE,
        ]);
    }

    public function test_batch_picker_suggests_the_fefo_lot_and_never_an_expired_one(): void
    {
        $product = Product::factory()->create([
            'sku' => 'FEFO-'.uniqid(),
            'track_inventory' => true,
            'tracking_method' => 'batch',
        ]);

        $expired = $this->batch($product, 'L-EXPIRED', now()->subDays(3)->toDateString(), qty: 50);
        $later = $this->batch($product, 'L-LATER', now()->addDays(400)->toDateString());
        $noExpiry = $this->batch($product, 'L-NOEXP', null);
        $soon = $this->batch($product, 'L-SOON', now()->addDays(10)->toDateString());
        $fullyReserved = $this->batch($product, 'L-RESERVED', now()->addDays(2)->toDateString(), qty: 5, reserved: 5);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/products/{$product->id}/tracking-availability?warehouse_id={$this->warehouse->id}");

        $response->assertOk();

        $ids = collect($response->json('batches'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        // FEFO order: earliest expiry first, lots without expiry last; expired
        // and fully reserved lots are never offered.
        $this->assertSame([$soon->id, $later->id, $noExpiry->id], $ids);
        $this->assertNotContains($expired->id, $ids);
        $this->assertNotContains($fullyReserved->id, $ids);

        // The picker pre-selects this lot.
        $this->assertSame($soon->id, (int) $response->json('suggested_batch_id'));

        $byId = collect($response->json('batches'))->keyBy('id');
        $this->assertSame(10, $byId[$soon->id]['available_quantity']);
        $this->assertSame(now()->addDays(10)->toDateString(), $byId[$soon->id]['expiry_date']);
        $this->assertTrue($byId[$soon->id]['near_expiry'], 'A lot expiring within the warning window is flagged near expiry.');
        $this->assertSame(10, $byId[$soon->id]['days_until_expiry']);
        $this->assertFalse($byId[$later->id]['near_expiry']);
        $this->assertFalse($byId[$noExpiry->id]['near_expiry']);
        $this->assertNull($byId[$noExpiry->id]['days_until_expiry']);
    }

    public function test_no_suggestion_when_only_expired_lots_remain(): void
    {
        $product = Product::factory()->create([
            'sku' => 'FEFOX-'.uniqid(),
            'track_inventory' => true,
            'tracking_method' => 'batch',
        ]);

        $this->batch($product, 'L-OLD', now()->subDay()->toDateString(), qty: 20);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/products/{$product->id}/tracking-availability?warehouse_id={$this->warehouse->id}");

        $response->assertOk();
        $this->assertSame([], $response->json('batches'));
        $this->assertNull($response->json('suggested_batch_id'));
    }

    public function test_serial_picker_suggests_the_serial_from_the_earliest_expiring_lot(): void
    {
        $product = Product::factory()->create([
            'sku' => 'FEFOS-'.uniqid(),
            'track_inventory' => true,
            'tracking_method' => 'serial',
        ]);

        $lateLot = $this->batch($product, 'S-LATE', now()->addDays(300)->toDateString(), qty: 1);
        $soonLot = $this->batch($product, 'S-SOON', now()->addDays(20)->toDateString(), qty: 1);
        $expiredLot = $this->batch($product, 'S-EXP', now()->subDays(5)->toDateString(), qty: 1);

        $make = fn (string $number, ?ProductBatch $lot) => ProductSerial::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_id' => $lot?->id,
            'serial_number' => $number.'-'.Str::upper(Str::random(4)),
            'status' => ProductSerial::STATUS_AVAILABLE,
        ]);

        // Alphabetical order would put "A-..." first; FEFO must not.
        $late = $make('A-LATE', $lateLot);
        $soon = $make('Z-SOON', $soonLot);
        $expired = $make('B-EXP', $expiredLot);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/products/{$product->id}/tracking-availability?warehouse_id={$this->warehouse->id}");

        $response->assertOk();

        $ids = collect($response->json('serials'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([$soon->id, $late->id], $ids);
        $this->assertNotContains($expired->id, $ids);
        $this->assertSame($soon->id, (int) $response->json('suggested_serial_id'));

        $byId = collect($response->json('serials'))->keyBy('id');
        $this->assertTrue($byId[$soon->id]['near_expiry']);
        $this->assertFalse($byId[$late->id]['near_expiry']);
    }
}
