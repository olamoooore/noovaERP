<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerEmail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Modules\PaymentGateway\Services\PaymentService;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Tests\TenantTestCase;

/**
 * D8 (Oct-2026 invoice review): the one-click Send bundle must produce ONE
 * customer email carrying the PDF, the acceptance portal link, the pay-now
 * link and a QR for it — instead of the previous separate acceptance email
 * plus a second payment-request email.
 */
class InvoiceSendBundleTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Send Bundle Tenant',
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

        // The delivery service renders the invoice through the template
        // builder, exactly like the production path.
        app(DocumentTemplateServiceContract::class)->seedDefaults();
    }

    public function test_send_bundle_emails_one_message_with_acceptance_pay_link_and_qr(): void
    {
        Mail::fake();

        $paymentUrl = 'https://pay.test/checkout/abc123';
        $gateway = Mockery::mock(PaymentService::class);
        $gateway->shouldReceive('createIntent')->once()->andReturn([
            'payment_url' => $paymentUrl,
            'transaction_id' => 'txn-1',
        ]);
        $this->app->instance(PaymentService::class, $gateway);

        $customer = Customer::factory()->create([
            'legal_entity_id' => null,
            'email' => 'billing@acme.example.test',
        ]);

        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
            'status' => 'posted',
            'total' => 250,
            'balance' => 250,
            'currency_code' => null,
            'exchange_rate' => 1,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/send-bundle")
            ->assertCreated();

        $acceptanceUrl = $response->json('data.url');
        $this->assertNotEmpty($acceptanceUrl, 'The acceptance link must be returned.');
        $this->assertSame($paymentUrl, $response->json('data.payment_url'));
        $this->assertSame('sent', $response->json('data.delivery.status'));

        // Exactly ONE email — no separate payment-request mail.
        Mail::assertSentCount(1);
        Mail::assertSent(CustomerEmail::class, function (CustomerEmail $mail) use ($acceptanceUrl, $paymentUrl) {
            return $mail->hasTo('billing@acme.example.test')
                && str_contains($mail->emailBody, $acceptanceUrl)
                && str_contains($mail->emailBody, $paymentUrl)
                && $mail->qrDataUri !== null
                && str_starts_with((string) $mail->qrDataUri, 'data:image/');
        });

        $this->assertSame(
            1,
            InvoiceDelivery::where('invoice_id', $invoice->id)->count(),
            'The bundle must produce a single delivery record.'
        );
    }

    public function test_send_bundle_for_draft_without_payment_skips_the_pay_link(): void
    {
        Mail::fake();

        $customer = Customer::factory()->create([
            'legal_entity_id' => null,
            'email' => 'billing@acme.example.test',
        ]);

        // Approved draft: deliverable for acceptance, but not payable yet.
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
            'approval_status' => 'approved',
            'total' => 250,
            'balance' => 250,
            'currency_code' => null,
            'exchange_rate' => 1,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/send-bundle")
            ->assertCreated()
            ->assertJsonPath('data.payment_url', null);

        Mail::assertSentCount(1);
        Mail::assertSent(CustomerEmail::class, fn (CustomerEmail $mail) => $mail->qrDataUri === null);
    }
}
