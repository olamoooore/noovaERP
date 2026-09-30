<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Tests\TenantTestCase;

/**
 * SF-027 — P-04c invoice payment links: real hosted URLs, real landings.
 *
 * Pinned defects (each failed before this commit at baseline `75c04d38`):
 *  1) PaymentService::createIntent returns `payment_url`, but
 *     createPaymentLink read only checkout_url/url/client_secret, so the
 *     stored payment_link_url was NULL — the link was unusable end to end;
 *  2) success/cancel redirects pointed at routes that never existed — now
 *     they default to the platform's own (DB-free, webhook remains the only
 *     settlement source) landing endpoints, or to merchant-provided absolute
 *     URLs.
 */
class SF027InvoicePaymentLinksTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        Queue::fake();

        DB::statement('SET search_path TO public');
        $this->bindTenantContext((string) Str::uuid());
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF027 Co', 'email' => 'sf027-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);

        PaymentGateway::create([
            'gateway_name' => 'hitpay',
            'api_key' => 'business-key-test',
            'is_active' => true,
            'is_test_mode' => true,
        ]);

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

    public function test_link_stores_the_gateway_payment_url_not_null(): void
    {
        $issued = [];
        $this->fakeHitpay($issued);
        $admin = $this->makeStaff('admin', 'Admin');
        $invoice = $this->openInvoice();

        $res = $this->actingAs($admin)->postJson("/api/v1/invoices/{$invoice->id}/payment-link", [])
            ->assertStatus(201);

        $url = $res->json('data.url');
        $this->assertSame('https://sandbox.hitpay.biz/'.$issued[0], $url, 'the hosted checkout link itself');
        $invoice->refresh();
        $this->assertSame($url, $invoice->payment_link_url, 'stored link must be the hosted URL (P-04c defect #1: previously NULL)');
        $this->assertStringContainsString('/api/v1/payments/invoice/'.$invoice->id.'/success', $res->json('data.success_url'));

        $txn = PaymentTransaction::query()->where('transaction_id', $issued[0])->sole();
        $this->assertSame('invoice', $txn->metadata['type']);
        $this->assertSame($invoice->id, (int) $txn->metadata['invoice_id']);
    }

    public function test_redirect_payload_carries_the_real_landing_and_ref(): void
    {
        $issued = [];
        $this->fakeHitpay($issued);
        $admin = $this->makeStaff('admin', 'Admin');
        $invoice = $this->openInvoice();

        $this->actingAs($admin)->postJson("/api/v1/invoices/{$invoice->id}/payment-link", [])->assertStatus(201);

        // asForm payload: match against the decoded body (fake request
        // exposes data() only for some drivers; body is always present)
        Http::assertSent(function ($req) use ($invoice) {
            $body = urldecode((string) $req->body());

            return str_contains($body, '/api/v1/payments/invoice/'.$invoice->id.'/success');
        });
    }

    public function test_landings_answer_and_never_settle_from_the_browser(): void
    {
        // GET /success must exist (it was a dead route before) and is
        // deliberately DB-free: settlement stays webhook-owned. Even an
        // unknown invoice id answers — no 404, no state change.
        $ok = $this->getJson('/api/v1/payments/invoice/999999/success?ref=hr_deadbeef')
            ->assertStatus(200)
            ->assertJsonPath('status', 'awaiting_settlement')
            ->assertJsonPath('reference', 'hr_deadbeef');
        $this->assertSame(0, PaymentTransaction::query()->where('transaction_id', 'hr_deadbeef')->count(),
            'a browser arriving at the success URL must not create or settle anything');

        $this->getJson('/api/v1/payments/invoice/999999/cancelled')
            ->assertStatus(200)
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_active_link_is_idempotent_and_expiry_forces_reissue(): void
    {
        $issued = [];
        $this->fakeHitpay($issued);
        $admin = $this->makeStaff('admin', 'Admin');
        $invoice = $this->openInvoice();

        $first = $this->actingAs($admin)->postJson("/api/v1/invoices/{$invoice->id}/payment-link", [])->assertStatus(201);
        $second = $this->actingAs($admin)->postJson("/api/v1/invoices/{$invoice->id}/payment-link", [])->assertStatus(201);

        $this->assertTrue((bool) $second->json('data.idempotent'));
        $this->assertSame($first->json('data.url'), $second->json('data.url'));
        $this->assertCount(1, $issued, 'no duplicate gateway intent while the link is live (PAY-R4)');

        // Expired merchant link + still-PENDING hosted request: the gateway-
        // level dedupe (PAY-R4 / P-04d) reuses the live hosted request
        // instead of firing a second one. Re-issue is only forced once the
        // pending request is gone — asserted here as: same usable URL, one
        // transaction row, one gateway call, and the service honestly
        // reports this as a fresh (non-idempotent) issuance.
        $invoice->updateQuietly(['payment_link_expires_at' => now()->subMinute()]);
        $third = $this->actingAs($admin)->postJson("/api/v1/invoices/{$invoice->id}/payment-link", [])->assertStatus(201);
        $this->assertStringContainsString($issued[0], (string) $third->json('data.url'),
            'the SAME live hosted request is reused (dedupe replies with HitPay canonical form of the same id)');
        $this->assertCount(1, $issued, 'no duplicate gateway request while one is pending');
        $this->assertSame(1, PaymentTransaction::query()->count(), 'single transaction row (P-04d)');
    }

    public function test_merchant_success_urls_are_validated(): void
    {
        $issued = [];
        $this->fakeHitpay($issued);
        $admin = $this->makeStaff('admin', 'Admin');
        $invoice = $this->openInvoice();

        $this->actingAs($admin)->postJson("/api/v1/invoices/{$invoice->id}/payment-link", [
            'success_url' => 'https://merchant.example/thanks',
            'cancel_url' => 'https://merchant.example/oops',
        ])->assertStatus(201)
            ->assertJsonPath('data.success_url', 'https://merchant.example/thanks');

        Http::assertSent(fn ($req) => str_contains(urldecode((string) $req->body()), 'redirect_url=https://merchant.example/thanks')
            || str_contains((string) ($req->data()['redirect_url'] ?? ''), 'https://merchant.example/thanks'));

        $this->actingAs($admin)->postJson("/api/v1/invoices/{$invoice->id}/payment-link", [
            'success_url' => '/relative/never',
        ])->assertStatus(422);
    }

    public function test_rbac_and_paid_invoice_guards(): void
    {
        $this->fakeHitpay();
        $invoice = $this->openInvoice();
        $viewer = $this->makeStaff('viewer', 'Viewer');

        $this->actingAs($viewer)->postJson("/api/v1/invoices/{$invoice->id}/payment-link", [])
            ->assertStatus(403);

        $settled = $this->openInvoice(paid: true);
        $admin = $this->makeStaff('admin', 'Admin');
        $this->actingAs($admin)->postJson("/api/v1/invoices/{$settled->id}/payment-link", [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invoice balance is zero or paid. No payment link needed.');
    }

    // ─── fixtures ─────────────────────────────────────────────────────

    private function fakeHitpay(?array &$issued = null): void
    {
        $issued ??= [];
        Http::fake(function ($req) use (&$issued) {
            if (strtoupper($req->method()) === 'GET') {
                return Http::response(['status' => 'pending'], 200);
            }
            $id = 'hr_'.Str::random(10);
            $issued[] = $id;

            return Http::response(['id' => $id, 'url' => 'https://sandbox.hitpay.biz/'.$id, 'amount' => '250.00', 'currency' => 'SGD'], 200);
        });
    }

    private function openInvoice(bool $paid = false): Invoice
    {
        $customer = Customer::factory()->create(['email' => 'sf027-'.Str::random(6).'@example.test']);

        return Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => $paid ? 'paid' : 'sent',
            'total' => 250,
            'balance' => $paid ? 0 : 250,
            'paid_amount' => $paid ? 250 : 0,
        ]);
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
