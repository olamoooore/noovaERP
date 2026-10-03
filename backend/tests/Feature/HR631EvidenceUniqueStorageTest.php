<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InvoiceEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Tests\TenantTestCase;

/**
 * Issue #631 (P2, SALES-CHAT / CH3) — evidence uploads were stored under the
 * client's original filename (`.../evidence/{getClientOriginalName()}`) with
 * putFileAs, so a second upload named `signed.pdf` silently overwrote the
 * first: the older evidence/chatter row still said `signed.pdf`, but its
 * stored file now held the newer bytes.
 *
 * Contract under test (real HTTP routes):
 *   - stored names are unique (UUID + sanitised extension); the original name
 *     survives only as metadata on the evidence/chatter rows
 *   - a checksum is recorded on the evidence and chatter rows
 *   - two same-named uploads produce two distinct files, both retrievable
 *
 * This deliberately uploads the SAME filename twice (once per file in a
 * multi-file request, and once per request on the authenticated invoice
 * route) instead of asserting on today's code behaviour.
 */
class HR631EvidenceUniqueStorageTest extends TenantTestCase
{
    private \App\Models\User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->admin = $this->setUpTenantAdminUser('HR631 Evidence Tenant');
    }

    private function makeDeliveryOrder(): DeliveryOrder
    {
        $customer = Customer::factory()->create(['name' => 'HR631 Customer']);

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
            'name' => 'HR631 Invoice Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-HR631-'.Str::random(6),
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

    private function makeOpenInvoiceAcceptanceToken(int $invoiceId): string
    {
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

        return $token;
    }

    /**
     * The error messages: a stored file must be an opaque UUID name, never the
     * client's original filename (which may be shared, or hostile).
     */
    private function assertUuidStoredName(string $path, string $expectedExtension): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.'.preg_quote($expectedExtension, '/').'$/',
            basename($path),
            "Stored name '{$path}' must be a UUID + sanitised extension, not the client filename."
        );
    }

    // ─── Delivery-order acceptance portal ─────────────────────────────────

    public function test_two_do_acceptance_uploads_named_signed_pdf_are_stored_distinctly(): void
    {
        $do = $this->makeDeliveryOrder();

        $this->postJson("/api/v1/do-accept/{$do->acceptance_token}/evidence", [
            'files' => [
                UploadedFile::fake()->createWithContent('signed.pdf', 'FIRST SIGNED COPY'),
                UploadedFile::fake()->createWithContent('signed.pdf', 'SECOND SIGNED COPY'),
            ],
            'signer_name' => 'Jane Receiver',
            'signer_email' => 'jane@example.com',
        ])->assertCreated()->assertJsonPath('file_names', ['signed.pdf', 'signed.pdf']);

        $attachments = DB::table('chatter_attachments')
            ->where('subject_type', DeliveryOrder::class)
            ->where('subject_id', (string) $do->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $attachments, 'Both same-named uploads must be registered.');

        $paths = $attachments->pluck('storage_path')->all();
        $this->assertCount(
            2,
            array_unique($paths),
            'Two same-named uploads must not share a stored file.'
        );

        $storedContents = [];
        foreach ($attachments as $attachment) {
            $this->assertSame('signed.pdf', $attachment->filename, 'The original name is metadata only.');
            $this->assertNotNull($attachment->checksum, 'The chatter row must record a checksum.');

            $this->assertUuidStoredName((string) $attachment->storage_path, 'pdf');

            $this->assertTrue(
                Storage::disk('public')->exists($attachment->storage_path),
                'Each upload must be retrievable at its recorded path.'
            );

            $contents = (string) Storage::disk('public')->get($attachment->storage_path);
            $storedContents[] = $contents;

            $this->assertSame(
                hash('sha256', $contents),
                (string) $attachment->checksum,
                'The stored checksum must match the stored bytes.'
            );
        }

        sort($storedContents);
        $this->assertSame(
            ['FIRST SIGNED COPY', 'SECOND SIGNED COPY'],
            $storedContents,
            'Each upload must keep its own bytes — the second must not overwrite the first.'
        );

        $this->assertNotSame($attachments[0]->checksum, $attachments[1]->checksum);

        $this->assertContains(
            $do->fresh()->signed_document_path,
            $paths,
            'The signed copy the delivery order references must be one of the stored evidence files.'
        );

        // Both retained files stay retrievable through the authenticated
        // chatter download route (the route the vendor UI uses).
        foreach ($attachments as $attachment) {
            $download = $this->actingAs($this->admin)->get(
                '/api/v1/chatter/'.rawurlencode(DeliveryOrder::class)
                ."/{$do->id}/attachments/{$attachment->id}/download"
            );

            $download->assertOk();
            $this->assertSame(
                (string) Storage::disk('public')->get($attachment->storage_path),
                $download->streamedContent(),
                'Downloading an attachment must return that upload\'s own bytes.'
            );
        }
    }

    public function test_hostile_original_filename_cannot_escape_or_name_the_evidence_file(): void
    {
        $do = $this->makeDeliveryOrder();

        $this->postJson("/api/v1/do-accept/{$do->acceptance_token}/evidence", [
            'files' => [
                UploadedFile::fake()
                    ->createWithContent('../../shell.phtml', '%PDF-1.4 hostile name')
                    ->mimeType('application/pdf'),
            ],
            'signer_name' => 'Jane Receiver',
        ])->assertCreated();

        $attachment = DB::table('chatter_attachments')
            ->where('subject_type', DeliveryOrder::class)
            ->where('subject_id', (string) $do->id)
            ->first();

        $this->assertNotNull($attachment);

        $path = (string) $attachment->storage_path;

        $this->assertStringNotContainsString('..', $path, 'The stored path must not contain traversal segments.');
        $this->assertSame(
            "delivery-orders/{$do->id}/evidence/".basename($path),
            $path,
            'The stored path must stay under the delivery order evidence directory.'
        );
        $this->assertUuidStoredName($path, 'pdf');
        $this->assertTrue(Storage::disk('public')->exists($path));
    }

    // ─── Authenticated invoice evidence upload ────────────────────────────

    public function test_two_invoice_evidence_uploads_named_signed_pdf_are_stored_distinctly(): void
    {
        $invoiceId = $this->makeInvoice();

        foreach (['FIRST INVOICE EVIDENCE', 'SECOND INVOICE EVIDENCE'] as $index => $content) {
            $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoiceId}/evidence", [
                'type' => InvoiceEvidence::TYPE_PAYMENT_PROOF,
                'file' => UploadedFile::fake()->createWithContent('signed.pdf', $content),
                'notes' => 'upload '.$index,
            ])->assertCreated();
        }

        $rows = DB::table('invoice_evidence')
            ->where('invoice_id', $invoiceId)
            ->where('type', InvoiceEvidence::TYPE_PAYMENT_PROOF)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);

        $paths = $rows->pluck('file_path')->all();
        $this->assertCount(2, array_unique($paths), 'Two same-named evidence rows must not share a stored file.');

        $storedContents = [];
        foreach ($rows as $row) {
            $this->assertSame('signed.pdf', $row->file_name, 'The original name is metadata only.');
            $this->assertUuidStoredName((string) $row->file_path, 'pdf');
            $this->assertTrue(Storage::disk('public')->exists($row->file_path));

            $contents = (string) Storage::disk('public')->get($row->file_path);
            $storedContents[] = $contents;

            $this->assertSame(
                hash('sha256', $contents),
                (string) $row->checksum_sha256,
                'The evidence row must record the checksum of the stored file.'
            );

            $download = $this->actingAs($this->admin)
                ->get("/api/v1/invoices/{$invoiceId}/evidence/{$row->id}/download");

            $download->assertOk();
            $this->assertSame(
                $contents,
                $download->streamedContent(),
                'Downloading evidence must return that upload\'s own bytes.'
            );
        }

        sort($storedContents);
        $this->assertSame(
            ['FIRST INVOICE EVIDENCE', 'SECOND INVOICE EVIDENCE'],
            $storedContents,
            'Each upload must keep its own bytes — the second must not overwrite the first.'
        );
    }

    // ─── Public invoice acceptance portal ─────────────────────────────────

    public function test_two_customer_portal_evidence_uploads_named_signed_pdf_are_stored_distinctly(): void
    {
        $invoiceId = $this->makeInvoice();
        $token = $this->makeOpenInvoiceAcceptanceToken($invoiceId);

        $this->postJson("/api/v1/invoice-accept/{$token}/evidence", [
            'files' => [
                UploadedFile::fake()->createWithContent('signed.pdf', 'FIRST PORTAL EVIDENCE'),
                UploadedFile::fake()->createWithContent('signed.pdf', 'SECOND PORTAL EVIDENCE'),
            ],
            'customer_name' => 'Jane Customer',
            'customer_email' => 'jane@example.com',
        ])->assertCreated()->assertJsonPath('file_names', ['signed.pdf', 'signed.pdf']);

        $rows = DB::table('invoice_evidence')
            ->where('invoice_id', $invoiceId)
            ->where('type', InvoiceEvidence::TYPE_OTHER)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);

        $paths = $rows->pluck('file_path')->all();
        $this->assertCount(2, array_unique($paths), 'Two same-named portal uploads must not share a stored file.');

        $storedContents = [];
        foreach ($rows as $row) {
            $this->assertSame('signed.pdf', $row->file_name, 'The original name is metadata only.');
            $this->assertUuidStoredName((string) $row->file_path, 'pdf');
            $this->assertTrue(Storage::disk('public')->exists($row->file_path));

            $contents = (string) Storage::disk('public')->get($row->file_path);
            $storedContents[] = $contents;

            $this->assertSame(hash('sha256', $contents), (string) $row->checksum_sha256);
        }

        sort($storedContents);
        $this->assertSame(
            ['FIRST PORTAL EVIDENCE', 'SECOND PORTAL EVIDENCE'],
            $storedContents,
            'Each portal upload must keep its own bytes.'
        );

        // The chatter mirror of the customer upload must carry the checksum too.
        $attachments = DB::table('chatter_attachments')
            ->where('subject_type', \App\Models\Invoice::class)
            ->where('subject_id', (string) $invoiceId)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $attachments);
        $this->assertSame(
            (string) $rows[0]->checksum_sha256,
            (string) $attachments[0]->checksum,
            'The chatter row mirroring an evidence upload must record its checksum.'
        );
        $this->assertSame(
            (string) $rows[1]->checksum_sha256,
            (string) $attachments[1]->checksum,
            'The chatter row mirroring an evidence upload must record its checksum.'
        );
    }
}
