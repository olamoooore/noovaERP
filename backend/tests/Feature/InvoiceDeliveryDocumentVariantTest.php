<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerEmail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Modules\TemplateBuilder\Support\DefaultTemplates;
use Smalot\PdfParser\Parser;
use Tests\TenantTestCase;

/**
 * Issue #617 — an invoice email must attach the document the operator
 * selected: an acceptance copy (which is not a tax invoice) when sending a
 * draft for approval, and the final invoice for a posted document. The #539
 * "document type on send" setting decides the default when the caller does
 * not pass one.
 *
 * These are router-level tests (router → middleware → controller → delivery
 * service → TemplateBuilder render) so the attachment actually sent is the
 * one asserted on — the mail transport is the only thing faked.
 */
class InvoiceDeliveryDocumentVariantTest extends TenantTestCase
{
    private const ACCEPTANCE_BANNER = 'ACCEPTANCE COPY — NOT A TAX INVOICE';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenantUuid,
            'name' => 'Document Variant Tenant',
            'email' => $tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The delivery service renders the invoice through the template
        // builder, exactly like the production path.
        app(DocumentTemplateServiceContract::class)->seedDefaults();
    }

    /** @param array<string,mixed> $overrides */
    private function makeInvoice(array $overrides = []): Invoice
    {
        $customer = Customer::factory()->create([
            'legal_entity_id' => null,
            'email' => 'billing@acme.example.test',
        ]);

        return Invoice::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
            'lifecycle_status' => 'draft',
            'approval_status' => Invoice::APPROVAL_APPROVED,
            'total' => 250,
            'balance' => 250,
            'currency_code' => null,
            'exchange_rate' => 1,
        ], $overrides));
    }

    /** @return \Illuminate\Support\Collection<int,string> */
    private function sentAttachmentBytes()
    {
        return Mail::sent(CustomerEmail::class)
            ->map(fn (CustomerEmail $mail) => $mail->emailAttachments[0]['data'] ?? '')
            ->values();
    }

    /**
     * Extract the visible text of an emailed PDF attachment so the assertion
     * is on the document the customer actually receives, not the raw bytes
     * (DomPDF embeds a render timestamp, so byte equality is never usable).
     */
    private function pdfText(string $bytes): string
    {
        return (new Parser)->parseContent($bytes)->getText();
    }

    public function test_acceptance_copy_and_final_invoice_emails_attach_different_documents(): void
    {
        Mail::fake();

        $invoice = $this->makeInvoice();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/deliver", [
                'channel' => 'email',
                'document_type' => 'acceptance_copy',
            ])
            ->assertOk()
            ->assertJsonPath('data.document_type', 'acceptance_copy');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/deliver", [
                'channel' => 'email',
                'document_type' => 'final_invoice',
            ])
            ->assertOk()
            ->assertJsonPath('data.document_type', 'final_invoice');

        $attachments = $this->sentAttachmentBytes();

        $this->assertCount(2, $attachments);
        $this->assertStringStartsWith('%PDF', $attachments[0]);
        $this->assertStringStartsWith('%PDF', $attachments[1]);

        $this->assertStringContainsString(
            'ACCEPTANCE COPY',
            $this->pdfText($attachments[0]),
            'The acceptance-copy email must carry the acceptance-copy document (#617).'
        );
        $this->assertStringNotContainsString(
            'ACCEPTANCE COPY',
            $this->pdfText($attachments[1]),
            'The final-invoice email must not carry the acceptance-copy banner (#617).'
        );
    }

    public function test_default_invoice_template_renders_the_acceptance_banner_only_for_acceptance_copies(): void
    {
        $this->assertStringContainsString(
            'document_variant',
            json_encode(DefaultTemplates::invoice()),
            'The default invoice template must carry the document_variant token (#617).'
        );

        $invoice = $this->makeInvoice();
        $pdf = app(PdfGenerationServiceContract::class);

        $acceptance = $pdf->render('invoice', (string) $invoice->id, [
            'format' => 'html',
            'document_variant' => 'acceptance_copy',
        ]);
        $this->assertStringContainsString(self::ACCEPTANCE_BANNER, $acceptance['html']);

        $final = $pdf->render('invoice', (string) $invoice->id, [
            'format' => 'html',
            'document_variant' => 'final_invoice',
        ]);
        $this->assertStringNotContainsString(
            self::ACCEPTANCE_BANNER,
            $final['html'],
            'A final invoice must never carry the acceptance-copy banner (#617).'
        );
    }

    public function test_document_type_on_send_setting_drives_the_default_and_falls_back_to_status(): void
    {
        Mail::fake();

        $invoice = $this->makeInvoice();

        // Nothing configured: the existing status-based fallback still applies
        // (a draft being sent is an acceptance copy).
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/deliver", ['channel' => 'portal'])
            ->assertOk()
            ->assertJsonPath('data.document_type', 'acceptance_copy');

        // #539 setting drives the default when the caller does not specify.
        Setting::setValue('sales.delivery.document_type_on_send', 'final_invoice');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/deliver", [
                'channel' => 'email',
            ])
            ->assertOk()
            ->assertJsonPath('data.document_type', 'final_invoice');

        Mail::assertSent(CustomerEmail::class, function (CustomerEmail $mail) {
            return str_starts_with($mail->emailSubject, 'Invoice ')
                && ! str_contains($mail->emailSubject, '[ACCEPTANCE COPY]')
                && ! str_contains($this->pdfText($mail->emailAttachments[0]['data']), 'ACCEPTANCE COPY');
        });
    }

    public function test_acceptance_copy_never_reuses_the_posted_immutable_final_invoice(): void
    {
        Mail::fake();

        $invoice = $this->makeInvoice([
            'status' => 'posted',
            'lifecycle_status' => 'posted',
        ]);

        // A posted invoice has an immutable copy of the issued final invoice.
        $pdf = app(PdfGenerationServiceContract::class);
        $snapshot = $pdf->snapshot('invoice', (string) $invoice->id);
        $storedBytes = (string) Storage::disk($snapshot->disk)->get($snapshot->disk_path);
        $this->assertStringStartsWith('%PDF', $storedBytes);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/deliver", [
                'channel' => 'email',
                'document_type' => 'acceptance_copy',
            ])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/deliver", [
                'channel' => 'email',
                'document_type' => 'final_invoice',
            ])
            ->assertOk();

        $attachments = $this->sentAttachmentBytes();
        $this->assertCount(2, $attachments);

        $this->assertNotSame(
            $storedBytes,
            $attachments[0],
            'An acceptance copy must be rendered as a variant, never served as the issued tax invoice (#617).'
        );
        $this->assertStringContainsString(
            'ACCEPTANCE COPY',
            $this->pdfText($attachments[0]),
            'The acceptance copy sent for a posted invoice must be the variant document (#617).'
        );
        $this->assertSame(
            $storedBytes,
            $attachments[1],
            'A final-invoice reprint must still be served from the immutable issued copy (#613).'
        );
    }
}
