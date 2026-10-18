<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use Tests\TenantTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Models\User;
use App\Models\JournalEntry;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

class OcrDocumentDeleteProtectionTest extends TenantTestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            PreventAccessFromCentralDomains::class,
        ]);

        $this->ensureLegacyFeatureTablesExist();

        $tenantId = '00000000-0000-0000-0000-000000000001';

        DB::table('tenants')->updateOrInsert(
            ['id' => $tenantId],
            [
                'name' => 'Test Tenant',
                'email' => 'test-tenant@example.com',
                'data' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->bindTenantContext($tenantId);

        $this->user = User::factory()->create();

        $this->setOcrBookkeepingEntitlement(true);
    }

    private function ensureLegacyFeatureTablesExist(): void
    {
        if (!Schema::hasTable('features')) {
            Schema::create('features', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('tenant_features')) {
            Schema::create('tenant_features', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('feature_id');
                $table->boolean('is_enabled')->default(true);
                $table->timestamp('enabled_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
    }

    private function setOcrBookkeepingEntitlement(bool $entitled): void
    {
        $tenantId = $this->boundTestTenant->getTenantKey();

        $catalogType = CatalogItemType::firstOrCreate(
            ['key' => 'module'],
            [
                'name' => 'Module',
                'is_active' => true,
            ]
        );

        $item = CatalogItem::firstOrCreate(
            ['key' => 'ocr-bookkeeping'],
            [
                'name' => 'OCR Bookkeeping',
                'type_id' => $catalogType->id,
                'is_active' => true,
                'effective_from' => now()->toDateString(),
            ]
        );

        TenantEntitlement::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'catalog_item_id' => $item->id,
            ],
            [
                'is_entitled' => $entitled,
                'source' => $entitled
                    ? TenantEntitlement::SOURCE_TIER_INCLUDED
                    : TenantEntitlement::SOURCE_OVERRIDE_EXCLUDE,
                'resolved_at' => now(),
            ]
        );

        app(FeatureGateService::class)->invalidate($tenantId);
    }

    private function createOcrDocument(array $overrides = []): OcrDocument
    {
        return OcrDocument::factory()->create(array_merge([
            'status' => 'NEEDS_REVIEW',
            'created_by' => $this->user->id,
            'legal_entity_id' => 1,
            'entity_book_id' => 1,
        ], $overrides));
    }

    private function assertDocumentNotSoftDeleted(OcrDocument $document): void
    {
        $this->assertNull(
            OcrDocument::withTrashed()->find($document->id)->deleted_at
        );
    }

    public function test_needs_review_document_can_be_deleted(): void
    {
        $document = $this->createOcrDocument([
            'status' => 'NEEDS_REVIEW',
        ]);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($this->user, 'sanctum')
            ->delete("/api/v1/ocr/documents/{$document->uuid}");

        $response->assertOk();
        $response->assertJsonPath('message', 'Document deleted successfully');

        $this->assertNotNull(
            OcrDocument::withTrashed()->find($document->id)->deleted_at
        );
    }

    public function test_posted_document_cannot_be_deleted(): void
    {
        $document = $this->createOcrDocument([
            'status' => 'POSTED',
            'posted_at' => now(),
            'posted_by' => $this->user->id,
        ]);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($this->user, 'sanctum')
            ->delete("/api/v1/ocr/documents/{$document->uuid}");

        $response->assertStatus(422);
        $this->assertStringContainsString('cannot be deleted', $response->json('message'));

        $this->assertDocumentNotSoftDeleted($document);
    }

    public function test_promoted_document_cannot_be_deleted(): void
    {
        $document = $this->createOcrDocument([
            'status' => 'PROMOTED',
        ]);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($this->user, 'sanctum')
            ->delete("/api/v1/ocr/documents/{$document->uuid}");

        $response->assertStatus(422);
        $this->assertStringContainsString('cannot be deleted', $response->json('message'));

        $this->assertDocumentNotSoftDeleted($document);
    }

    public function test_journal_entry_linked_document_cannot_be_deleted(): void
    {
        $journalEntry = JournalEntry::factory()->create([
            'created_by' => $this->user->id,
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $this->user->id,
        ]);

        $document = $this->createOcrDocument([
            'status' => 'NEEDS_REVIEW',
            'journal_entry_id' => $journalEntry->id,
        ]);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($this->user, 'sanctum')
            ->delete("/api/v1/ocr/documents/{$document->uuid}");

        $response->assertStatus(422);
        $this->assertStringContainsString('cannot be deleted', $response->json('message'));

        $this->assertDocumentNotSoftDeleted($document);
    }
}