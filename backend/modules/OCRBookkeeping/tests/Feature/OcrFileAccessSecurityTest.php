<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use Tests\TenantTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

class OcrFileAccessSecurityTest extends TenantTestCase
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

        Storage::fake('ocr-documents-local');
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

    private function createStoredOcrDocument(array $overrides = []): OcrDocument
    {
        $filePath = $overrides['file_path'] ?? 'test-files/receipt.jpg';

        Storage::disk('ocr-documents-local')->put($filePath, 'fake-image-bytes');

        return OcrDocument::factory()->create(array_merge([
            'file_path' => $filePath,
            'file_name' => 'receipt.jpg',
            'mime_type' => 'image/jpeg',
            'status' => 'NEEDS_REVIEW',
            'created_by' => $this->user->id,
            'legal_entity_id' => 1,
            'entity_book_id' => 1,
        ], $overrides));
    }

    public function test_file_access_requires_authentication(): void
    {
        $document = $this->createStoredOcrDocument();

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->get("/api/v1/ocr/documents/{$document->uuid}/file");

        $response->assertStatus(401);
    }

    public function test_file_access_requires_ocr_bookkeeping_entitlement(): void
    {
        $document = $this->createStoredOcrDocument();

        $this->setOcrBookkeepingEntitlement(false);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($this->user, 'sanctum')
            ->get("/api/v1/ocr/documents/{$document->uuid}/file");

        $response->assertStatus(403);
    }

    public function test_entitled_user_can_view_ocr_file(): void
    {
        $document = $this->createStoredOcrDocument();

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($this->user, 'sanctum')
            ->get("/api/v1/ocr/documents/{$document->uuid}/file");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');

        $this->assertSame('fake-image-bytes', $response->getContent());
        $this->assertStringNotContainsString($document->file_path, $response->getContent());
        $this->assertStringNotContainsString('storage/app', $response->getContent());
    }

    public function test_missing_file_response_does_not_expose_internal_file_path(): void
    {
        $document = OcrDocument::factory()->create([
            'file_path' => 'private/missing/secret-receipt.jpg',
            'file_name' => 'secret-receipt.jpg',
            'mime_type' => 'image/jpeg',
            'status' => 'NEEDS_REVIEW',
            'created_by' => $this->user->id,
            'legal_entity_id' => 1,
            'entity_book_id' => 1,
        ]);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($this->user, 'sanctum')
            ->get("/api/v1/ocr/documents/{$document->uuid}/file");

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'File not found');

        $this->assertArrayNotHasKey('path', $response->json());
        $this->assertStringNotContainsString($document->file_path, $response->getContent());
        $this->assertStringNotContainsString('storage/app', $response->getContent());
    }

    public function test_wrong_tenant_file_access_requires_multi_tenant_fixture(): void
    {
        $this->markTestSkipped(
            'Blocked: current OCR test fixture does not provide a reliable second tenant schema for OCR documents. ocr_documents has scope fields but no direct tenant_id column.'
        );
    }
}