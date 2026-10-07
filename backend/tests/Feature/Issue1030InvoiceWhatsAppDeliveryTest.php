<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\WhatsApp\Models\WhatsAppApiKey;
use Tests\TenantTestCase;

/**
 * Issue #1030: sending an invoice by WhatsApp must go through the real
 * Modules\WhatsApp\Services\WhatsAppService::sendMessage to the customer's
 * mobile, and a missing provider must fail visibly and be recorded as a
 * failed delivery.
 *
 * Router-level tests (router → middleware → controller). The WhatsApp
 * provider is faked at the HTTP layer, as in the #500 receipt tests.
 */
class Issue1030InvoiceWhatsAppDeliveryTest extends TenantTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $tenantId = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenantId,
            'name' => 'Invoice WhatsApp Tenant',
            'email' => $tenantId.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function postedInvoice(array $customerAttributes = []): Invoice
    {
        $customer = Customer::factory()->create(array_merge([
            'legal_entity_id' => null,
            'email' => 'billing@wa-customer.example.test',
            'mobile' => '+6591234567',
        ], $customerAttributes));

        return Invoice::factory()->create([
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
            'status' => 'posted',
            'total' => 250,
            'balance' => 250,
            'currency_code' => null,
            'exchange_rate' => 1,
        ]);
    }

    public function test_invoice_is_sent_by_whatsapp_to_the_customer_mobile(): void
    {
        Http::fake();
        WhatsAppApiKey::create([
            'key' => 'issue1030-test-whatsapp-key',
            'name' => 'Issue 1030 WhatsApp Key',
            'whatsapp_provider' => 'custom',
            'api_endpoint' => 'https://wa.example.test/send',
            'is_active' => true,
        ]);
        $invoice = $this->postedInvoice();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/deliver", ['channel' => 'whatsapp'])
            ->assertOk()
            ->assertJsonPath('data.channel', 'whatsapp')
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.recipient', '+6591234567');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://wa.example.test/send'
            && ($request->data()['phone_number'] ?? null) === '6591234567'
            && str_contains((string) ($request->data()['message'] ?? ''), $invoice->invoice_number));

        $this->assertSame(
            InvoiceDelivery::STATUS_SENT,
            InvoiceDelivery::where('invoice_id', $invoice->id)->where('channel', 'whatsapp')->value('status')
        );
    }

    public function test_whatsapp_send_without_a_configured_provider_fails_visibly_and_is_recorded(): void
    {
        Http::fake();
        $invoice = $this->postedInvoice();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/deliver", ['channel' => 'whatsapp'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'WhatsApp is not configured for this tenant.');

        Http::assertNothingSent();

        $delivery = InvoiceDelivery::where('invoice_id', $invoice->id)->where('channel', 'whatsapp')->sole();
        $this->assertSame(InvoiceDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame('WhatsApp is not configured for this tenant.', $delivery->error);
    }

    public function test_customer_without_a_phone_number_gets_a_clear_error(): void
    {
        Http::fake();
        $invoice = $this->postedInvoice(['mobile' => null, 'phone' => null]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/deliver", ['channel' => 'whatsapp'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No WhatsApp number is on file for this customer.');

        Http::assertNothingSent();
    }
}
