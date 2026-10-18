<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\OCRBookkeeping\Http\Controllers\OcrDocumentController;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Tests\TestCase;

class OcrAuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureOcrDocumentsTableExists();
        $this->ensureOcrAuditLogsTableExists();
    }

    public function test_delete_blocked_creates_ocr_audit_log_record(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum');

        $document = OcrDocument::create([
            'file_path' => '2026/07/test-audit.pdf',
            'file_name' => 'test-audit.pdf',
            'file_hash' => hash('sha256', 'test-audit-file'),
            'file_size' => 1000,
            'mime_type' => 'application/pdf',
            'status' => 'POSTED',
            'legal_entity_id' => 1,
            'entity_book_id' => 1,
            'created_by' => $user->id,
        ]);

        $controller = app(OcrDocumentController::class);

        $response = $controller->destroy($document->uuid);

        $this->assertSame(422, $response->getStatusCode());

        $this->assertDatabaseHas('ocr_audit_logs', [
            'ocr_document_id' => $document->id,
            'document_uuid' => $document->uuid,
            'action' => 'ocr_delete_blocked',
            'result' => 'blocked',
        ]);

        $auditLog = DB::table('ocr_audit_logs')
            ->where('ocr_document_id', $document->id)
            ->where('action', 'ocr_delete_blocked')
            ->first();

        $this->assertNotNull($auditLog);

        $metadata = json_decode($auditLog->metadata_json, true);

        $this->assertSame('delete_blocked', $metadata['original_action']);
        $this->assertSame('test-audit.pdf', $metadata['file_name']);
        $this->assertSame('POSTED', $metadata['status']);
        $this->assertSame(
            'Document cannot be deleted in current status or while linked to a journal entry',
            $metadata['reason']
        );
    }

    private function ensureOcrDocumentsTableExists(): void
    {
        if (Schema::hasTable('ocr_documents')) {
            return;
        }

        Schema::create('ocr_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('file_path');
            $table->string('file_name');
            $table->string('file_hash')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();

            $table->string('status', 50)->default('PENDING');
            $table->string('currency_code', 3)->default('USD');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->unsignedInteger('retry_count')->default(0);

            $table->unsignedBigInteger('tenant_identity_id')->nullable();
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->unsignedBigInteger('entity_book_id')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function ensureOcrAuditLogsTableExists(): void
    {
        if (Schema::hasTable('ocr_audit_logs')) {
            return;
        }

        Schema::create('ocr_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_identity_id')->nullable();
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->unsignedBigInteger('entity_book_id')->nullable();

            $table->unsignedBigInteger('ocr_document_id')->nullable();
            $table->string('document_uuid', 100)->nullable();

            $table->string('action', 80);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('result', 30)->default('success');

            $table->json('metadata_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('tenant_identity_id');
            $table->index('legal_entity_id');
            $table->index('entity_book_id');
            $table->index('ocr_document_id');
            $table->index('document_uuid');
            $table->index('action');
            $table->index('actor_user_id');
            $table->index('created_at');
        });
    }
}