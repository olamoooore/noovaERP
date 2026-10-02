<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EsignRequest;
use App\Models\InvoiceEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Tests\TenantTestCase;

/**
 * Issue #630 (P1, SALES-CHAT / CH2) — a chatter attachment delete destroyed the
 * underlying evidence file. Evidence services (delivery-order acceptance
 * portal, invoice customer evidence, e-signature mirror) register the SAME
 * stored file as a chatter attachment, and ChatterService::deleteAttachment()
 * deleted it from storage, leaving the evidence record pointing at nothing.
 *
 * Contract under test:
 *   - attachments registered from an evidence source carry evidence_type /
 *     evidence_id and are refused with 403 ("This file is evidence for …")
 *   - an ordinary attachment delete must not remove a file another record
 *     still references
 *   - GET /api/v1/invoices/data-checks/missing-evidence-files lists evidence
 *     rows whose stored file no longer exists
 *
 * Everything is exercised through the real HTTP routes (public portal upload →
 * authenticated chatter delete), per the engineering SOP §3.
 */
class HR630ChatterEvidenceDeletionTest extends TenantTestCase
{
    private \App\Models\User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->admin = $this->setUpTenantAdminUser('HR630 Evidence Tenant');
    }

    private function chatterDeleteUrl(string $subjectType, int|string $subjectId, int $attachmentId): string
    {
        return '/api/v1/chatter/'.rawurlencode($subjectType)
            ."/{$subjectId}/attachments/{$attachmentId}";
    }

    private function makeDeliveryOrder(): DeliveryOrder
    {
        $customer = Customer::factory()->create(['name' => 'HR630 Customer']);

        return DeliveryOrder::create([
            'customer_id' => $customer->id,
            'status' => 'draft',
            'created_by' => $this->admin->id,
            'acceptance_token' => (string) Str::uuid(),
            'acceptance_expires_at' => now()->addDays(7),
        ]);
    }

    private function makeInvoice(): int
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'HR630 Invoice Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-HR630-'.Str::random(6),
            'customer_id' => $customerId,
            'invoice_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'total' => 100,
            'status' => 'draft',
            'lifecycle_status' => 'draft',
            'version_number' => 1,
            'is_active_version' => true,
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAttachment(string $subjectType, int $subjectId, ?int $uploadedById, string $path): int
    {
        return (int) DB::table('chatter_attachments')->insertGetId([
            'tenant_id' => (string) tenant('id'),
            'subject_type' => $subjectType,
            'subject_id' => (string) $subjectId,
            'uploaded_by_id' => $uploadedById,
            'filename' => basename($path),
            'mime_type' => 'application/pdf',
            'size' => 5,
            'storage_disk' => 'public',
            'storage_path' => $path,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function findAttachment(string $subjectType, int $subjectId, string $filename): ?object
    {
        return DB::table('chatter_attachments')
            ->where('subject_type', $subjectType)
            ->where('subject_id', (string) $subjectId)
            ->where('filename', $filename)
            ->first();
    }

    // ─── Acceptance test: DO acceptance evidence → chatter delete → 403 ───

    public function test_do_acceptance_evidence_cannot_be_deleted_through_chatter(): void
    {
        $do = $this->makeDeliveryOrder();

        $this->postJson("/api/v1/do-accept/{$do->acceptance_token}/evidence", [
            'files' => [UploadedFile::fake()->create('signed-do.pdf', 8, 'application/pdf')],
            'signer_name' => 'Jane Receiver',
            'signer_email' => 'jane@example.com',
        ])->assertCreated();

        $attachment = $this->findAttachment(DeliveryOrder::class, $do->id, 'signed-do.pdf');
        $this->assertNotNull($attachment, 'The DO acceptance portal must register a chatter attachment.');

        // The schema-based evidence contract: the registering service stamps
        // the evidence reference on the attachment.
        $this->assertSame(DeliveryOrder::class, $attachment->evidence_type);
        $this->assertSame($do->id, (int) $attachment->evidence_id);
        $this->assertTrue(Storage::disk('public')->exists($attachment->storage_path));

        $response = $this->actingAs($this->admin)->deleteJson(
            $this->chatterDeleteUrl(DeliveryOrder::class, $do->id, (int) $attachment->id)
        );

        $response->assertStatus(403);
        $this->assertStringContainsString(
            'This file is evidence for',
            (string) $response->json('message'),
            'The refusal must name the evidence the file belongs to.'
        );

        $this->assertDatabaseHas('chatter_attachments', ['id' => $attachment->id]);
        $this->assertTrue(
            Storage::disk('public')->exists($attachment->storage_path),
            'The signed delivery-order evidence file must survive the refused chatter delete.'
        );
    }

    /**
     * The signed copy is only the LAST uploaded file (or the drawn signature).
     * Earlier uploads are just as much evidence, but no other record points at
     * them — before #630 deleting one through chatter silently destroyed it.
     */
    public function test_every_do_acceptance_evidence_file_is_protected_not_just_the_signed_copy(): void
    {
        $do = $this->makeDeliveryOrder();

        $this->postJson("/api/v1/do-accept/{$do->acceptance_token}/evidence", [
            'files' => [
                UploadedFile::fake()->create('evidence-a.pdf', 8, 'application/pdf'),
                UploadedFile::fake()->create('evidence-b.pdf', 8, 'application/pdf'),
            ],
            'signer_name' => 'Jane Receiver',
            'signer_email' => 'jane@example.com',
        ])->assertCreated();

        $attachment = $this->findAttachment(DeliveryOrder::class, $do->id, 'evidence-a.pdf');
        $this->assertNotNull($attachment);
        $this->assertNotSame(
            (string) $do->fresh()->signed_document_path,
            (string) $attachment->storage_path,
            'This upload is not the signed copy the delivery order references.'
        );

        $response = $this->actingAs($this->admin)->deleteJson(
            $this->chatterDeleteUrl(DeliveryOrder::class, $do->id, (int) $attachment->id)
        );

        $response->assertStatus(403);
        $this->assertDatabaseHas('chatter_attachments', ['id' => $attachment->id]);
        $this->assertTrue(
            Storage::disk('public')->exists($attachment->storage_path),
            'Non-signed acceptance evidence must not be destroyed through chatter either.'
        );
    }

    // ─── Invoice customer evidence registered through the portal ──────────

    public function test_invoice_customer_evidence_attachment_cannot_be_deleted_through_chatter(): void
    {
        $invoiceId = $this->makeInvoice();
        $token = (string) Str::uuid();

        DB::table('invoice_evidence')->insert([
            'invoice_id' => $invoiceId,
            'type' => InvoiceEvidence::TYPE_CUSTOMER_ACCEPTANCE,
            'source' => InvoiceEvidence::SOURCE_PORTAL_ACCEPTANCE,
            'external_id' => $token,
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/invoice-accept/{$token}/evidence", [
            'file' => UploadedFile::fake()->create('receipt.pdf', 8, 'application/pdf'),
            'customer_name' => 'Jane Customer',
            'customer_email' => 'jane@example.com',
        ]);

        $response->assertCreated();
        $evidenceId = (int) $response->json('evidence_id');

        $attachment = $this->findAttachment(\App\Models\Invoice::class, $invoiceId, 'receipt.pdf');
        $this->assertNotNull($attachment, 'Customer evidence must be mirrored into chatter.');
        $this->assertSame(InvoiceEvidence::class, $attachment->evidence_type);
        $this->assertSame($evidenceId, (int) $attachment->evidence_id);

        $delete = $this->actingAs($this->admin)->deleteJson(
            $this->chatterDeleteUrl(\App\Models\Invoice::class, $invoiceId, (int) $attachment->id)
        );

        $delete->assertStatus(403);
        $this->assertStringContainsString('This file is evidence for', (string) $delete->json('message'));
        $this->assertDatabaseHas('invoice_evidence', ['id' => $evidenceId]);
        $this->assertDatabaseHas('chatter_attachments', ['id' => $attachment->id]);
        $this->assertTrue(Storage::disk('public')->exists($attachment->storage_path));
    }

    // ─── E-sign uploaded signed copy ──────────────────────────────────────

    public function test_esign_uploaded_signed_copy_attachment_cannot_be_deleted_through_chatter(): void
    {
        $do = $this->makeDeliveryOrder();

        $esign = EsignRequest::create([
            'document_type' => 'delivery_order',
            'document_id' => $do->id,
            'document_number' => $do->delivery_order_number,
            'token' => (string) Str::uuid(),
            'status' => EsignRequest::STATUS_PENDING,
            'signer_name' => 'Jane Receiver',
            'signer_email' => 'jane@example.com',
            'sent_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        // Customer chose to sign on paper: uploads the signed copy through the
        // public /sign/{token}/upload route.
        $this->post("/api/v1/sign/{$esign->token}/upload", [
            'file' => UploadedFile::fake()->create('paper-signed.pdf', 8, 'application/pdf'),
            'signer_name' => 'Jane Receiver',
            'signer_email' => 'jane@example.com',
        ])->assertCreated();

        $attachment = DB::table('chatter_attachments')
            ->where('subject_type', DeliveryOrder::class)
            ->where('subject_id', (string) $do->id)
            ->where('filename', 'like', 'uploaded-signed-%')
            ->first();

        $this->assertNotNull($attachment, 'The e-sign mirror must register the signed copy in chatter.');
        $this->assertSame(EsignRequest::class, $attachment->evidence_type);
        $this->assertSame($esign->id, (int) $attachment->evidence_id);

        $delete = $this->actingAs($this->admin)->deleteJson(
            $this->chatterDeleteUrl(DeliveryOrder::class, $do->id, (int) $attachment->id)
        );

        $delete->assertStatus(403);
        $this->assertStringContainsString('This file is evidence for', (string) $delete->json('message'));
        $this->assertDatabaseHas('chatter_attachments', ['id' => $attachment->id]);
        $this->assertTrue(Storage::disk('public')->exists($attachment->storage_path));
    }

    // ─── Ordinary attachment reference check ──────────────────────────────

    public function test_deleting_an_ordinary_attachment_keeps_a_file_another_record_references(): void
    {
        $do = $this->makeDeliveryOrder();
        $path = 'chatter/shared/duplicate.pdf';
        Storage::disk('public')->put($path, 'shared bytes');

        $otherId = $this->insertAttachment(DeliveryOrder::class, $do->id, $this->admin->id, $path);
        $targetId = $this->insertAttachment(DeliveryOrder::class, $do->id, $this->admin->id, $path);

        $response = $this->actingAs($this->admin)->deleteJson(
            $this->chatterDeleteUrl(DeliveryOrder::class, $do->id, $targetId)
        );

        $response->assertOk();
        $this->assertDatabaseMissing('chatter_attachments', ['id' => $targetId]);
        $this->assertDatabaseHas('chatter_attachments', ['id' => $otherId]);
        $this->assertTrue(
            Storage::disk('public')->exists($path),
            'The file must survive while another attachment row still references it.'
        );
    }

    // ─── Data check ───────────────────────────────────────────────────────

    public function test_data_check_lists_evidence_rows_whose_file_is_missing(): void
    {
        $invoiceId = $this->makeInvoice();

        Storage::disk('public')->put("invoices/{$invoiceId}/evidence/present.pdf", 'present');

        $presentId = (int) DB::table('invoice_evidence')->insertGetId([
            'invoice_id' => $invoiceId,
            'type' => InvoiceEvidence::TYPE_PAYMENT_PROOF,
            'source' => InvoiceEvidence::SOURCE_UPLOAD,
            'file_path' => "invoices/{$invoiceId}/evidence/present.pdf",
            'file_name' => 'present.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $missingId = (int) DB::table('invoice_evidence')->insertGetId([
            'invoice_id' => $invoiceId,
            'type' => InvoiceEvidence::TYPE_PAYMENT_PROOF,
            'source' => InvoiceEvidence::SOURCE_UPLOAD,
            'file_path' => "invoices/{$invoiceId}/evidence/destroyed.pdf",
            'file_name' => 'destroyed.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/invoices/data-checks/missing-evidence-files');

        $response->assertOk();

        $reportedIds = array_column($response->json('data.rows') ?? [], 'evidence_id');

        $this->assertContains(
            $missingId,
            $reportedIds,
            'The data check must report an evidence row whose file no longer exists.'
        );
        $this->assertNotContains(
            $presentId,
            $reportedIds,
            'An evidence row whose file still exists is not a mismatch.'
        );
    }
}
