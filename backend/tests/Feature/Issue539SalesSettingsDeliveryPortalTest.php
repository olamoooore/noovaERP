<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerEmail;
use App\Models\Customer;
use App\Models\EsignRequest;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\InvoiceEvidence;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Modules\PaymentGateway\Services\PaymentService;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Tests\TenantTestCase;

/**
 * Issue #539 (ST2): the portal and delivery Sales Settings are read by the
 * live send / acceptance paths instead of hard-coded values.
 *
 * Every case goes through the real HTTP routes (send-bundle, acceptance-link,
 * payment-link and the public legacy acceptance portal) and asserts the
 * behaviour the setting promises.
 */
class Issue539SalesSettingsDeliveryPortalTest extends TenantTestCase
{
    private string $tenantUuid;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Issue 539 Delivery Tenant',
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

        app(DocumentTemplateServiceContract::class)->seedDefaults();
    }

    private function invoice(array $overrides = []): Invoice
    {
        $customer = Customer::factory()->create([
            'legal_entity_id' => null,
            'email' => 'billing@acme.example.test',
        ]);

        return Invoice::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
            'approval_status' => 'approved',
            'total' => 250,
            'balance' => 250,
            'currency_code' => null,
            'exchange_rate' => 1,
        ], $overrides));
    }

    private function fakeGateway(string $url = 'https://pay.test/checkout/i539'): void
    {
        $gateway = Mockery::mock(PaymentService::class);
        $gateway->shouldReceive('createIntent')->andReturn([
            'payment_url' => $url,
            'transaction_id' => 'txn-i539',
        ]);
        $this->app->instance(PaymentService::class, $gateway);
    }

    private function assertDaysFromNow(int $days, mixed $timestamp, string $message): void
    {
        $this->assertNotNull($timestamp, $message);
        $this->assertEqualsWithDelta(
            now()->addDays($days)->getTimestamp(),
            Carbon::parse($timestamp)->getTimestamp(),
            120,
            $message
        );
    }

    // ── sales.portal.acceptance_link_expiry_days ─────────────────────────

    public function test_acceptance_link_expiry_defaults_to_the_tenant_setting(): void
    {
        Mail::fake();
        Setting::setValue('sales.portal.acceptance_link_expiry_days', 5);

        $invoice = $this->invoice();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/acceptance-link", ['send_email' => false])
            ->assertCreated();

        $this->assertDaysFromNow(5, $response->json('data.expires_at'), 'The acceptance link must expire after the configured 5 days, not the hard-coded 30.');
    }

    public function test_send_bundle_acceptance_link_uses_the_tenant_expiry(): void
    {
        Mail::fake();
        Setting::setValue('sales.portal.acceptance_link_expiry_days', 9);

        $invoice = $this->invoice();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/send-bundle")
            ->assertCreated();

        $this->assertDaysFromNow(9, $response->json('data.expires_at'), 'The bundle acceptance link must use the configured expiry.');
    }

    public function test_an_explicit_expiry_still_wins_over_the_setting(): void
    {
        Mail::fake();
        Setting::setValue('sales.portal.acceptance_link_expiry_days', 5);

        $invoice = $this->invoice();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/acceptance-link", ['send_email' => false, 'expires_in_days' => 12])
            ->assertCreated();

        $this->assertDaysFromNow(12, $response->json('data.expires_at'), 'A caller-chosen expiry overrides the tenant default.');
    }

    // ── sales.portal.payment_link_expiry_days ────────────────────────────

    public function test_payment_link_expiry_defaults_to_the_tenant_setting(): void
    {
        Mail::fake();
        $this->fakeGateway();
        Setting::setValue('sales.portal.payment_link_expiry_days', 3);

        $invoice = $this->invoice(['status' => 'posted']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/payment-link", [])
            ->assertCreated();

        $this->assertDaysFromNow(3, $invoice->fresh()->payment_link_expires_at, 'The payment link must expire after the configured 3 days, not the hard-coded 14.');
    }

    public function test_send_bundle_payment_link_uses_the_tenant_expiry(): void
    {
        Mail::fake();
        $this->fakeGateway();
        Setting::setValue('sales.portal.payment_link_expiry_days', 4);

        $invoice = $this->invoice(['status' => 'posted']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/send-bundle")
            ->assertCreated();

        $this->assertDaysFromNow(4, $invoice->fresh()->payment_link_expires_at, 'The bundle pay-now link must use the configured expiry.');
    }

    // ── sales.delivery.attach_pdf ────────────────────────────────────────

    public function test_attach_pdf_off_sends_the_email_without_the_pdf(): void
    {
        Mail::fake();
        Setting::setValue('sales.delivery.attach_pdf', false);

        $invoice = $this->invoice();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/send-bundle")
            ->assertCreated();

        Mail::assertSent(CustomerEmail::class, fn (CustomerEmail $mail) => $mail->emailAttachments === []);
    }

    public function test_attach_pdf_on_keeps_the_pdf_attachment(): void
    {
        Mail::fake();
        Setting::setValue('sales.delivery.attach_pdf', true);

        $invoice = $this->invoice();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/send-bundle")
            ->assertCreated();

        Mail::assertSent(CustomerEmail::class, fn (CustomerEmail $mail) => count($mail->emailAttachments) === 1
            && $mail->emailAttachments[0]['mime'] === 'application/pdf');
    }

    // ── sales.delivery.cc_internal_email ─────────────────────────────────

    public function test_cc_internal_email_is_copied_on_customer_invoice_emails(): void
    {
        Mail::fake();
        Setting::setValue('sales.delivery.cc_internal_email', 'ar-team@seller.example.test');

        $invoice = $this->invoice();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/send-bundle")
            ->assertCreated();

        Mail::assertSent(CustomerEmail::class, fn (CustomerEmail $mail) => $mail->hasTo('billing@acme.example.test')
            && $mail->hasCc('ar-team@seller.example.test'));
    }

    // ── sales.delivery.document_type_on_send ─────────────────────────────

    public function test_send_bundle_honours_the_document_type_on_send_setting(): void
    {
        Mail::fake();
        Setting::setValue('sales.delivery.document_type_on_send', InvoiceDelivery::DOCUMENT_ACCEPTANCE_COPY);

        $invoice = $this->invoice();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/send-bundle")
            ->assertCreated();

        $this->assertSame(
            InvoiceDelivery::DOCUMENT_ACCEPTANCE_COPY,
            InvoiceDelivery::where('invoice_id', $invoice->id)->value('document_type'),
            'The send bundle must not hard-code final_invoice when the tenant chose the acceptance copy.'
        );
    }

    // ── sales.portal.acceptance_enabled ──────────────────────────────────

    public function test_acceptance_disabled_refuses_to_issue_an_acceptance_link(): void
    {
        Mail::fake();
        Setting::setValue('sales.portal.acceptance_enabled', false);

        $invoice = $this->invoice();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/acceptance-link", ['send_email' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ACCEPTANCE_DISABLED');

        $this->assertSame(0, EsignRequest::where('document_type', 'invoice')->where('document_id', $invoice->id)->count());
        Mail::assertNothingSent();
    }

    public function test_acceptance_disabled_send_bundle_sends_without_an_acceptance_link(): void
    {
        Mail::fake();
        Setting::setValue('sales.portal.acceptance_enabled', false);

        $invoice = $this->invoice();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/send-bundle")
            ->assertCreated();

        $this->assertNull($response->json('data.url'));
        $this->assertSame(0, EsignRequest::where('document_type', 'invoice')->where('document_id', $invoice->id)->count());
        Mail::assertSent(CustomerEmail::class, fn (CustomerEmail $mail) => ! str_contains($mail->emailBody, '/sign/'));
    }

    public function test_acceptance_disabled_blocks_the_legacy_portal_accept(): void
    {
        Setting::setValue('sales.portal.acceptance_enabled', false);

        $token = $this->legacyAcceptanceToken();

        $this->postJson("/api/v1/invoice-accept/{$token}/accept", [
            'signer_name' => 'Jane Customer',
            'signer_email' => 'jane@example.test',
            'agreed' => true,
            'signature' => $this->signatureDataUri(),
        ])->assertStatus(403)->assertJsonPath('code', 'ACCEPTANCE_DISABLED');

        $this->assertNull(InvoiceEvidence::where('external_id', $token)->value('signed_at'));
    }

    // ── sales.portal.require_signature ───────────────────────────────────

    public function test_require_signature_rejects_an_unsigned_portal_acceptance(): void
    {
        Setting::setValue('sales.portal.require_signature', true);

        $token = $this->legacyAcceptanceToken();

        $this->postJson("/api/v1/invoice-accept/{$token}/accept", [
            'signer_name' => 'Jane Customer',
            'signer_email' => 'jane@example.test',
            'agreed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('signature');

        $this->assertNull(InvoiceEvidence::where('external_id', $token)->value('signed_at'));
    }

    public function test_signature_optional_when_the_setting_is_off(): void
    {
        Setting::setValue('sales.portal.require_signature', false);

        $token = $this->legacyAcceptanceToken();

        $this->postJson("/api/v1/invoice-accept/{$token}/accept", [
            'signer_name' => 'Jane Customer',
            'signer_email' => 'jane@example.test',
            'agreed' => true,
        ])->assertOk();

        $this->assertNotNull(InvoiceEvidence::where('external_id', $token)->value('signed_at'));
    }

    private function legacyAcceptanceToken(): string
    {
        $invoice = $this->invoice();
        $token = (string) Str::uuid();

        DB::table('invoice_evidence')->insert([
            'invoice_id' => $invoice->id,
            'type' => InvoiceEvidence::TYPE_CUSTOMER_ACCEPTANCE,
            'source' => InvoiceEvidence::SOURCE_PORTAL_ACCEPTANCE,
            'external_id' => $token,
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function signatureDataUri(): string
    {
        // 1x1 transparent PNG.
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    }
}
