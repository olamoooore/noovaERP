<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationAttachment;
use Modules\Communication\Services\CommunicationEngine;
use Tests\TenantTestCase;

/**
 * Issue #88 — attachments for communication messages: upload via the router,
 * stored on disk with a checksum, listed on the thread, downloaded only
 * through an authenticated endpoint (no public storage paths).
 */
class HR88CommunicationAttachmentsTest extends TenantTestCase
{
    private string $tenantId;

    private User $user;

    private int $messageId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureEntitlementAccess::class,
            \App\Http\Middleware\EnsureCommunicationAccess::class,
        ]);

        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $message = app(CommunicationEngine::class)->logSystem(
            'Invoice', 1, 'Please review the attached document.', 'system.log', $this->tenantId
        );
        $this->messageId = (int) $message->id;
    }

    public function test_upload_lists_on_thread_and_downloads_authenticated(): void
    {
        $upload = $this->postJson("/api/v1/api/communication/messages/{$this->messageId}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('invoice.pdf', 'PDF-CONTENT'),
        ]);

        $upload->assertCreated();
        $attachmentId = $upload->json('data.id');

        $attachment = CommunicationAttachment::findOrFail($attachmentId);
        $this->assertSame('invoice.pdf', $attachment->filename);
        $this->assertSame(strlen('PDF-CONTENT'), (int) $attachment->size);
        $this->assertSame(hash('sha256', 'PDF-CONTENT'), $attachment->checksum);
        Storage::disk('local')->assertExists($attachment->storage_path);

        // The thread exposes the attachment (no public URL).
        $thread = $this->getJson('/api/v1/api/communication/messages?model=Invoice&record_id=1');
        $thread->assertOk();
        $attachments = collect($thread->json('data'))->firstWhere('id', $this->messageId)['attachments'] ?? [];
        $this->assertNotEmpty($attachments, 'Thread must include the message attachments');
        $this->assertArrayNotHasKey('url', $attachments[0], 'No public storage URL may be exposed');

        // Authenticated download returns the content.
        $download = $this->get("/api/v1/api/communication/attachments/{$attachmentId}/download");
        $this->assertSame(200, $download->getStatusCode());
        $this->assertSame('PDF-CONTENT', $download->streamedContent());
    }
}
