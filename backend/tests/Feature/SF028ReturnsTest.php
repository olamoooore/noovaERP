<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Database\Seeders\ModuleFeatureSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Inventory\Models\StockLevel;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use Modules\Storefront\Enums\PaymentStatus;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontReturn;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\CartService;
use Tests\TenantTestCase;

/**
 * SF-028 — P4 returns (FR-ORD-06, A-22): request → approve → receive (THE
 * restock layer deferred from refunds) → refund through the canonical chain,
 * plus the full return message sequence, the state guards, and the money/
 * stock/ledger effects (DR inventory / CR COGS restock entry at the sale's
 * cost basis; refund side = SF020 semantics).
 */
class SF028ReturnsTest extends TenantTestCase
{
    private string $tenantId;

    private int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        Queue::fake();

        DB::statement('SET search_path TO public');
        $this->bindTenantContext((string) Str::uuid());
        $this->seed(RolePermissionSeeder::class);

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF028 Shop', 'email' => 'sf028-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);

        $item = CatalogItem::where('key', 'storefront')->firstOrFail();
        TenantEntitlement::query()->create([
            'tenant_id' => $this->tenantId, 'catalog_item_id' => $item->id,
            'is_entitled' => true, 'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE, 'resolved_at' => now(),
        ]);
        app(FeatureGateService::class)->invalidate($this->tenantId);

        StorefrontSettings::current();

        $this->warehouseId = (int) Warehouse::create(['name' => 'SF028 WH', 'code' => 'S28'.Str::upper(Str::random(3)), 'is_active' => true])->id;

        $owner = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $owner->email],
            ['name' => $owner->name, 'password' => $owner->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $owner->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'owner', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $owner->assignRole('Owner');
        $this->bindTenantContext($this->tenantId);

        $this->artisan('storefront:templates:seed')->assertSuccessful();
    }

    public function test_full_return_cycle_restocks_refunds_and_sends_the_sequence(): void
    {
        $manager = $this->makeStaff('manager', 'Manager');
        [$order, $token] = $this->paidOrder();
        $item = $order->items()->sole();
        $level = StockLevel::query()->where('product_id', $item->product_id)->where('warehouse_id', $this->warehouseId)->sole();
        $stockAfterSale = (float) $level->quantity; // 10 issued - 2 sold
        $this->assertSame(8.0, $stockAfterSale);

        // customer requests returning ONE of two
        $res = $this->postJson('/api/v1/storefront/orders/'.$order->id.'/return', [
            'token' => $token,
            'reason' => 'wrong size',
            'lines' => [['line_id' => (int) $item->id, 'quantity' => 1]],
        ])->assertStatus(200);
        $returnId = (int) $res->json('data.return_id');
        $this->assertSame('requested', $res->json('data.status'));
        $this->assertSame('25.00', $res->json('data.refund_amount'), 'server-computed suggested refund (1 of 2 lines)');

        // approve (keep suggested), receive (restock), refund (canonical chain)
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/returns/{$returnId}/approve", [
            'note' => 'ok',
        ])->assertStatus(200)->assertJsonPath('data.status', 'approved');

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/returns/{$returnId}/receive", [])
            ->assertStatus(200)->assertJsonPath('data.status', 'received');

        $level->refresh();
        $this->assertSame(9.0, (float) $level->quantity, 'physical goods back on the shelf');
        $this->assertSame(1, StockMovement::query()->where('type', 'return')->where('reference', $order->order_number)->count());
        $item->refresh();
        $this->assertSame(1.0, (float) $item->returned_quantity);

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/returns/{$returnId}/refund", [])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.order_payment_status', PaymentStatus::PARTIALLY_REFUNDED->value);

        // ledger legs: restock JE (DR inventory 12 / CR COGS 12 at cost 12*1? cost basis) + refund JE
        $restockLink = \App\Models\JournalEntryLink::query()
            ->where('source_document_type', 'storefront_return_restock')
            ->where('source_document_id', $returnId)
            ->sole();
        $restockJe = \App\Models\JournalEntry::query()->with('lines')->find($restockLink->journal_entry_id);
        $this->assertSame((float) $restockJe->lines->sum('debit'), (float) $restockJe->lines->sum('credit'));
        $this->assertGreaterThan(0, (float) $restockJe->lines->sum('debit'));

        $this->assertSame(1, \App\Models\JournalEntryLink::query()->where('source_document_type', 'storefront_refund')->count(),
            'money leg is the SAME canonical refund mechanism (one reversal)');

        // A-22 full message sequence (plus the order placed/paid pair)
        foreach (['requested', 'approved', 'received', 'refunded'] as $event) {
            $this->assertSame(1, CommunicationMessage::query()->where('subtype', 'storefront.return.'.$event)->count(),
                "return.{$event} mail exactly once");
        }
        $refunded = CommunicationMessage::query()->where('subtype', 'storefront.return.refunded')->sole();
        $this->assertStringNotContainsString('{{', $refunded->body);
        $this->assertStringContainsString('25.00', $refunded->body);
    }

    public function test_state_guards_hold(): void
    {
        $manager = $this->makeStaff('manager', 'Manager');
        [$order, $token] = $this->paidOrder();
        $item = $order->items()->sole();

        // too many
        $this->postJson('/api/v1/storefront/orders/'.$order->id.'/return', [
            'token' => $token, 'reason' => 'greedy',
            'lines' => [['line_id' => (int) $item->id, 'quantity' => 2.5]],
        ])->assertStatus(422)->assertJsonPath('code', 'quantity_exceeds');

        $ret = $this->postJson('/api/v1/storefront/orders/'.$order->id.'/return', [
            'token' => $token, 'reason' => 'ok one',
            'lines' => [['line_id' => (int) $item->id, 'quantity' => 1]],
        ])->assertStatus(200)->json('data.return_id');

        // cannot receive before approval
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/returns/{$ret}/receive", [])
            ->assertStatus(409)->assertJsonPath('code', 'invalid_return_transition');
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/returns/{$ret}/refund", [])
            ->assertStatus(409);

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/returns/{$ret}/approve", [])->assertStatus(200);
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/returns/{$ret}/approve", [])->assertStatus(409);

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/returns/{$ret}/receive", [])->assertStatus(200);
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/returns/{$ret}/receive", [])
            ->assertStatus(409)->assertJsonPath('code', 'invalid_return_transition');
        $this->assertSame(1, StockMovement::query()->where('type', 'return')->count(),
            'the double-receive attempt created no second movement');
    }

    public function test_reject_and_close_paths(): void
    {
        $manager = $this->makeStaff('manager', 'Manager');
        [$order, $token] = $this->paidOrder();
        $item = $order->items()->sole();

        $a = $this->postJson('/api/v1/storefront/orders/'.$order->id.'/return', [
            'token' => $token, 'reason' => 'changed mind',
            'lines' => [['line_id' => (int) $item->id, 'quantity' => 1]],
        ])->json('data.return_id');
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/returns/{$a}/reject", ['note' => 'outside window'])
            ->assertStatus(200)->assertJsonPath('data.status', 'rejected');
        $this->assertSame(1, CommunicationMessage::query()->where('subtype', 'storefront.return.rejected')->count());
        $this->assertSame(0, StockMovement::query()->where('type', 'return')->count(), 'rejected: nothing ever restocks');

        $b = $this->postJson('/api/v1/storefront/orders/'.$order->id.'/return', [
            'token' => $token, 'reason' => 'second attempt',
            'lines' => [['line_id' => (int) $item->id, 'quantity' => 1]],
        ])->assertStatus(200)->json('data.return_id');
        // the rejected row's 1 unit is not double-counted against returnable qty
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/returns/{$b}/approve", [])->assertStatus(200);
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/returns/{$b}/close", ['note' => 'customer kept item'])
            ->assertStatus(200)->assertJsonPath('data.status', 'closed');
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/returns/{$b}/refund", [])
            ->assertStatus(409);
    }

    public function test_rbac_and_message_toggle(): void
    {
        $viewer = $this->makeStaff('viewer', 'Viewer');
        $admin = $this->makeStaff('admin', 'Admin');
        [$order, $token] = $this->paidOrder();
        $item = $order->items()->sole();

        $ret = $this->postJson('/api/v1/storefront/orders/'.$order->id.'/return', [
            'token' => $token, 'reason' => 'x',
            'lines' => [['line_id' => (int) $item->id, 'quantity' => 1]],
        ])->json('data.return_id');

        $this->actingAs($viewer)->postJson("/api/v1/admin/storefront/returns/{$ret}/approve", [])
            ->assertStatus(403)->assertJsonPath('required_permission', 'storefront.write');

        StorefrontSettings::current()->update(['notification_settings' => ['return_approved' => ['email' => false]]]);
        $this->actingAs($admin)->postJson("/api/v1/admin/storefront/returns/{$ret}/approve", [])->assertStatus(200);
        $this->assertSame(0, CommunicationMessage::query()->where('subtype', 'storefront.return.approved')->count(),
            'FR-MSG-06 per-event toggle');
        $this->assertSame(1, StorefrontReturn::query()->find($ret)->status === 'approved' ? 1 : 0);
    }

    // ─── fixtures ─────────────────────────────────────────────────────

    /** @return array{0: StorefrontOrder, 1: string} */
    private function paidOrder(): array
    {
        $product = Product::factory()->create([
            'name' => 'SF28 Tee '.Str::random(4),
            'slug' => Str::slug('sf28-tee-'.Str::random(4)),
            'show_on_website' => true,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => true,
            'stock_quantity' => 10,
            'cost' => 12,
            'price' => 25,
            'tax_rate' => 0,
        ]);
        StockLevel::create(['product_id' => $product->id, 'warehouse_id' => $this->warehouseId, 'quantity' => 10, 'reserved_quantity' => 0]);

        $guestToken = bin2hex(random_bytes(32));
        $cart = StorefrontCart::create(['guest_token_hash' => hash('sha256', $guestToken), 'status' => 'active']);
        app(CartService::class)->addOrUpdateItem($cart, $product->id, null, 2);

        $res = $this->withUnencryptedCookie(CartService::COOKIE, $guestToken)->postJson('/api/v1/storefront/checkout', [
            'email' => 'sf28-'.Str::random(6).'@example.test',
            'idempotency_key' => 'sf28-'.Str::random(24),
            'shipping_address' => ['name' => 'R T', 'address1' => '28 Rd', 'city' => 'Town', 'postal_code' => '54321', 'country_code' => 'SG'],
        ]);
        $res->assertStatus(201);
        $order = StorefrontOrder::query()->findOrFail((int) $res->json('data.id'));
        $token = (string) $res->json('data.confirmation_token');

        $gateway = PaymentGateway::query()->where('gateway_name', 'hitpay')->first()
            ?? PaymentGateway::create(['gateway_name' => 'hitpay', 'is_active' => true, 'is_test_mode' => true]);
        $txn = PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => 'sf28pay_'.Str::random(10),
            'amount' => (float) $order->total,
            'currency' => $order->currency ?? 'SGD',
            'status' => 'completed',
            'metadata' => ['type' => 'storefront_order', 'order_id' => (int) $order->id],
        ]);
        app(PaymentTransactionCompletionService::class)->complete($txn, 'card');

        $this->assertSame(PaymentStatus::PAID->value, $order->fresh()->payment_status);

        return [$order->fresh(), $token];
    }

    private function makeStaff(string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create(['name' => $spatieRole]);
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole);
        $this->bindTenantContext($this->tenantId);

        return $user;
    }
}
