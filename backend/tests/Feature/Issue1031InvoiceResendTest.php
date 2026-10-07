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
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Tests\TenantTestCase;

/**
 * Issue #1031: POST /api/v1/invoices/{id}/deliver with resend=true must
 * resend the invoice on the chosen channel (not 500 on a swapped
 * channel/user argument order), and must still refuse while a delivery on
 * that channel is queued.
 *
 * Router-level tests (router → middleware → controller); mail is faked.
 */
class Issue1031InvoiceResendTest extends TenantTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $tenantId = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenantId,
            'name' => 'Invoice Resend Tenant',
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

        app(DocumentTemplateServiceContract::class)->seedDefaults();
    }

    private function postedInvoice(): Invoice
    {
        $customer = Customer::factory()->create([
            'legal_entity_id' => null,
            'email' => 'billing@resend-customer.example.test',
        ]);

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

    public function test_resend_by_email_sends_the_invoice_again_and_records_a_new_delivery(): void
    {
        Mail::fake();
        $invoice = $this->postedInvoice();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/deliver", ['channel' => 'email'])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/deliver", ['channel' => 'email', 'resend' => true])
            ->assertOk()
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.recipient', 'billing@resend-customer.example.test');

        Mail::assertSent(CustomerEmail::class, 2);
        $this->assertSame(
            2,
            InvoiceDelivery::where('invoice_id', $invoice->id)
                ->where('channel', 'email')
                ->where('status', InvoiceDelivery::STATUS_SENT)
                ->count()
        );
    }

    public function test_resend_is_refused_while_a_delivery_on_that_channel_is_queued(): void
    {
        Mail::fake();
        $invoice = $this->postedInvoice();

        InvoiceDelivery::create([
            'invoice_id' => $invoice->id,
            'channel' => 'email',
            'document_type' => InvoiceDelivery::DOCUMENT_FINAL_INVOICE,
            'recipient' => 'billing@resend-customer.example.test',
            'status' => InvoiceDelivery::STATUS_QUEUED,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/deliver", ['channel' => 'email', 'resend' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A delivery is already in progress for channel email. Please wait or cancel it first.');

        Mail::assertNothingSent();
    }
}
