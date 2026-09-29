<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Database\Seeders\ModuleFeatureSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationMessage;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Enums\PaymentStatus;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontPaymentProof;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\CartService;
use Tests\TenantTestCase;

/**
 * SF-022 — P3 offline settlement (PAY-R13, FR-PAY-01/PAY-R3 server-decided
 * availability, Appendix C flow 5: proof upload -> approve -> completion;
 * reject -> stays pending). Approval must drive the SAME canonical chain a
 * gateway webhook drives — machine-only state, invoice + combined GL, paid
 * mail — never a side-door status write.
 */
class SF022OfflinePaymentsTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        Queue::fake();
        Storage::fake('local');

        DB::statement('SET search_path TO public');
        $this->bindTenantContext((string) Str::uuid());
        $this->seed(RolePermissionSeeder::class);

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF022 Shop', 'email' => 'sf022-'.Str::random(6).'@example.test',
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
        PaymentGateway::create(['gateway_name' => 'hitpay', 'is_active' => true, 'is_test_mode' => true]);

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
    }

    public function test_availability_is_server_decided_and_settings_gated(): void
    {
        $admin = $this->makeStaff('admin', 'Admin');

        // Nothing configured => no offline entries (SF016 gateway shape intact).
        $data = $this->getJson('/api/v1/storefront/payment-methods')->assertStatus(200)->json('data');
        $this->assertSame([], array_values(array_filter($data, fn ($m) => ($m['type'] ?? '') === 'offline')));

        $this->actingAs($admin)->putJson('/api/v1/admin/storefront/settings', [
            'payment_settings' => ['offline' => [
                'bank_transfer' => ['enabled' => true, 'label' => 'DBS Current 001-123456', 'instructions' => 'Transfer to DBS 001-123456, quote the order number.'],
                'pay_at_collection' => ['enabled' => false],
            ]],
        ])->assertStatus(200);

        $data = $this->getJson('/api/v1/storefront/payment-methods')->assertStatus(200)->json('data');
        $offline = collect($data)->firstWhere('type', 'offline');
        $this->assertNotNull($offline);
        $this->assertSame('bank_transfer', $offline['name']);
        $this->assertStringContainsString('DBS', $offline['instructions']);
        $this->assertNull(collect($data)->firstWhere('name', 'pay_at_collection'), 'disabled methods must not appear');

        // Unknown method keys are refused at the settings gate.
        $this->actingAs($admin)->putJson('/api/v1/admin/storefront/settings', [
            'payment_settings' => ['offline' => ['crypto' => ['enabled' => true]]],
        ])->assertStatus(422)->assertJsonPath('code', 'payment_method_unknown');
    }

    public function test_proof_upload_then_admin_approval_completes_through_the_canonical_chain(): void
    {
        $manager = $this->makeStaff('manager', 'Manager');
        $this->enableBankTransfer();
        [$order, $token] = $this->placedOrder(35.0);

        $res = $this->post("/api/v1/storefront/orders/{$order->id}/proof-of-payment", [
            'token' => $token,
            'method' => 'bank_transfer',
            'reference' => 'TRF-991',
            'proof' => UploadedFile::fake()->image('receipt.png', 400, 300),
        ]);
        $res->assertStatus(200)->assertJsonPath('data.status', 'submitted');
        $proofId = (int) $res->json('data.proof_id');

        $this->assertSame(1, StorefrontPaymentProof::query()->count());
        $this->assertTrue(Storage::disk('local')->exists(StorefrontPaymentProof::query()->sole()->file_path));
        $this->assertSame('proof_submitted', $order->fresh()->paymentAttempts()->latest('id')->first()->status);

        // order is NOT self-marked paid by an upload — machine state unchanged
        $this->assertSame(PaymentStatus::PENDING_PAYMENT->value, $order->fresh()->payment_status);
        $this->assertSame(0, \App\Models\Invoice::query()->count(), 'approval, not upload, converts');

        // console detail carries the proof + gated file link; viewer cannot download
        $show = $this->actingAs($manager)->getJson("/api/v1/admin/storefront/orders/{$order->id}")
            ->assertStatus(200)->json('data.payment_proofs');
        $this->assertCount(1, $show);
        $this->assertSame('TRF-991', $show[0]['reference']);

        $file = $this->actingAs($manager)->getJson($show[0]['file_url']);
        $file->assertStatus(200);

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/offline-payment", [
            'proof_id' => $proofId,
            'decision' => 'approve',
            'note' => 'money seen in bank statement',
        ])->assertStatus(200)
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('data.payment_status', PaymentStatus::PAID->value);

        // canonical completion side effects, same as a gateway webhook (SF015 shape)
        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::PAID->value, $fresh->status);
        $this->assertNotNull($fresh->invoice_id);
        $this->assertSame(1, \Modules\PaymentGateway\Models\PaymentTransaction::query()->where('status', 'completed')
            ->where('metadata->settlement', 'offline:bank_transfer')->count());
        $this->assertSame(1, Payment::query()->where('method', 'bank_transfer')->count());
        $this->assertSame(1, (int) DB::selectOne("select count(*) c from journal_entry_links where source_document_type='storefront_order' and relation_type='post'")->c);
        $this->assertSame(1, CommunicationMessage::query()->where('subtype', 'storefront.order.paid')->count());

        $proof = StorefrontPaymentProof::query()->find($proofId);
        $this->assertSame('approved', $proof->status);
        $this->assertSame('money seen in bank statement', $proof->review_note);

        // replay: the reviewed proof cannot drive a second completion
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/offline-payment", [
            'proof_id' => $proofId, 'decision' => 'approve',
        ])->assertStatus(422)->assertJsonPath('code', 'proof_reviewed');
        $this->assertSame(1, Payment::query()->count(), 'no double settlement');
    }

    public function test_rejection_keeps_the_order_pending_and_a_new_proof_can_be_approved(): void
    {
        $manager = $this->makeStaff('manager', 'Manager');
        $this->enableBankTransfer();
        [$order, $token] = $this->placedOrder(19.0);

        $first = $this->post("/api/v1/storefront/orders/{$order->id}/proof-of-payment", [
            'token' => $token,
            'method' => 'bank_transfer',
            'proof' => UploadedFile::fake()->create('blurry.jpg', 20, 'image/jpeg'),
        ])->assertStatus(200)->json('data.proof_id');

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/offline-payment", [
            'proof_id' => (int) $first, 'decision' => 'reject', 'note' => 'receipt illegible',
        ])->assertStatus(200)
            ->assertJsonPath('data.completed', false)
            ->assertJsonPath('data.payment_status', PaymentStatus::PENDING_PAYMENT->value);

        $second = $this->post("/api/v1/storefront/orders/{$order->id}/proof-of-payment", [
            'token' => $token,
            'method' => 'bank_transfer',
            'reference' => 'TRF-2',
            'proof' => UploadedFile::fake()->image('clear.png'),
        ])->assertStatus(200)->json('data.proof_id');

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/offline-payment", [
            'proof_id' => (int) $second, 'decision' => 'approve',
        ])->assertStatus(200)->assertJsonPath('data.payment_status', PaymentStatus::PAID->value);

        $this->assertSame(OrderStatus::PAID->value, $order->fresh()->status);
        $this->assertSame('rejected', StorefrontPaymentProof::query()->find($first)->status);
    }

    public function test_bad_requests_are_rejected_without_side_effects(): void
    {
        $manager = $this->makeStaff('manager', 'Manager');
        $this->enableBankTransfer();
        [$order, $token] = $this->placedOrder(15.0);

        // wrong type
        $this->post("/api/v1/storefront/orders/{$order->id}/proof-of-payment", [
            'token' => $token, 'method' => 'bank_transfer',
            'proof' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertStatus(422)->assertJsonPath('code', 'proof_invalid');

        // method not enabled for the store
        $this->post("/api/v1/storefront/orders/{$order->id}/proof-of-payment", [
            'token' => $token, 'method' => 'pay_at_collection',
            'proof' => UploadedFile::fake()->image('ok.png'),
        ])->assertStatus(422)->assertJsonPath('code', 'method_unavailable');

        // browser-invented gateway name still refused at /pay (server-decided)
        $this->withUnencryptedCookie(CartService::COOKIE, 'x')->postJson("/api/v1/storefront/orders/{$order->id}/pay", [
            'token' => $token, 'gateway' => 'pay_at_collection',
        ])->assertStatus(422)->assertJsonPath('code', 'gateway_unavailable');

        $this->assertSame(0, StorefrontPaymentProof::query()->count());

        // viewer cannot review
        $viewer = $this->makeStaff('viewer', 'Viewer');
        $this->actingAs($viewer)->postJson("/api/v1/admin/storefront/orders/{$order->id}/offline-payment", [
            'proof_id' => 1, 'decision' => 'approve',
        ])->assertStatus(403);
    }

    public function test_offline_approval_landing_on_payments_respects_the_method_constraint_set(): void
    {
        // P-04f guard: pay-at-collection must settle as 'cash' (inside the
        // payments.method CHECK set) — no constraint violation on write.
        $manager = $this->makeStaff('manager', 'Manager');
        StorefrontSettings::current()->update(['payment_settings' => ['offline' => [
            'pay_at_collection' => ['enabled' => true, 'label' => 'Cash at pickup'],
        ]]]);
        [$order, $token] = $this->placedOrder(9.0);

        $proofId = (int) $this->post("/api/v1/storefront/orders/{$order->id}/proof-of-payment", [
            'token' => $token, 'method' => 'pay_at_collection',
            'proof' => UploadedFile::fake()->image('collected.png'),
        ])->assertStatus(200)->json('data.proof_id');

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/offline-payment", [
            'proof_id' => $proofId, 'decision' => 'approve',
        ])->assertStatus(200)->assertJsonPath('data.payment_status', PaymentStatus::PAID->value);

        $this->assertSame('cash', Payment::query()->where('method', 'cash')->sole()->method);
    }

    // ─── fixtures ─────────────────────────────────────────────────────

    private function enableBankTransfer(): void
    {
        StorefrontSettings::current()->update(['payment_settings' => ['offline' => [
            'bank_transfer' => ['enabled' => true, 'label' => 'Bank transfer', 'instructions' => 'Transfer to DBS 001-123456.'],
        ]]]);
    }

    /** @return array{0: StorefrontOrder, 1: string} */
    private function placedOrder(float $price): array
    {
        $product = $this->makeProduct(['price' => $price]);
        $guestToken = bin2hex(random_bytes(32));
        $cart = StorefrontCart::create(['guest_token_hash' => hash('sha256', $guestToken), 'status' => 'active']);
        app(CartService::class)->addOrUpdateItem($cart, $product->id, null, 1);

        $res = $this->withUnencryptedCookie(CartService::COOKIE, $guestToken)->postJson('/api/v1/storefront/checkout', [
            'email' => 'sf22-'.Str::random(6).'@example.test',
            'idempotency_key' => 'sf22-'.Str::random(24),
            'shipping_address' => ['name' => 'O F', 'address1' => '22 Rd', 'city' => 'Town', 'postal_code' => '54321', 'country_code' => 'SG'],
        ]);
        $res->assertStatus(201);
        $order = StorefrontOrder::query()->findOrFail((int) $res->json('data.id'));

        return [$order, (string) $res->json('data.confirmation_token')];
    }

    private function makeProduct(array $overrides = []): Product
    {
        $name = $overrides['name'] ?? 'SF22 Product '.Str::random(4);

        return Product::factory()->create(array_merge([
            'name' => $name,
            'slug' => Str::slug($name),
            'show_on_website' => true,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'stock_quantity' => 100,
            'price' => 10,
            'tax_rate' => 0,
        ], $overrides));
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
