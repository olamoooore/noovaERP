<?php

declare(strict_types=1);

namespace Tests\Feature\HostileReview;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Services\CommunicationEngine;
use Tests\TenantTestCase;

/**
 * Hostile review repro (#88 / #77): an attachment on tenant A's internal note
 * must not be downloadable by an unrelated user operating in tenant B.
 * Only the entitlement middleware is bypassed; EnsureCommunicationAccess and
 * every other middleware stay on.
 */
class HRV88AttachmentAccessTest extends TenantTestCase
{
    public function test_other_tenant_user_cannot_download_internal_note_attachment(): void
    {
        $this->withoutMiddleware([\App\Http\Middleware\EnsureEntitlementAccess::class]);
        Storage::fake('local');

        // Tenant A: an admin writes an internal note and attaches a file.
        $tenantA = (string) Str::uuid();
        $this->bindTenantContext($tenantA);
        $author = User::factory()->create();
        $note = CommunicationMessage::create([
            'tenant_id' => $tenantA, 'model' => 'invoice', 'record_id' => 1,
            'message_type' => CommunicationMessage::TYPE_INTERNAL_NOTE,
            'subtype' => 'internal.note', 'body' => 'confidential margin notes',
            'author_id' => $author->id, 'author_type' => 'user', 'status' => 'sent',
        ]);
        $attachment = app(CommunicationEngine::class)->attachToMessage(
            (int) $note->id, UploadedFile::fake()->createWithContent('margins.pdf', 'SECRET-A'), $author->id, $tenantA, false
        );

        // Tenant B: a different user with no relation to tenant A.
        $tenantB = (string) Str::uuid();
        $this->bindTenantContext($tenantB);
        $outsider = User::factory()->create();

        $res = $this->actingAs($outsider)->get("/api/v1/api/communication/attachments/{$attachment->id}/download");
        $status = $res->getStatusCode();
        $body = $status === 200 ? $res->streamedContent() : '';

        fwrite(STDERR, sprintf("\n[HRV88] tenantB user download of tenantA internal-note attachment: http=%d body=%s schema_of_table=%s\n",
            $status, $body, \DB::selectOne("select table_schema from information_schema.tables where table_name='communication_attachments' limit 1")->table_schema));

        $this->assertNotSame(200, $status, 'cross-tenant internal-note attachment must not be downloadable');
    }
}
